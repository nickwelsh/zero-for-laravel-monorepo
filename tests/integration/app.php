<?php

declare(strict_types=1);

// Isolated fixture server. Its test-only bearer identities are never application middleware.
require __DIR__.'/../../packages/laravel/vendor/autoload.php';
// QueryDirection is declared alongside this test controller, not in its own PSR-4 file.
require_once __DIR__.'/../../packages/laravel/tests/Fixtures/QueryController.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Console\Output\ConsoleOutput;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Inertia\ZeroInertiaServiceProvider;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedGroup;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableContext;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\ContextResolver;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationRequest;
use ZeroForLaravel\Tests\Fixtures\Module\TaskQueries;
use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
use ZeroForLaravel\ZeroServiceProvider;

// Access checks reload this configured model rather than trusting the request's
// deliberately incomplete identity. Eloquent must not coerce alice/bob to ints.
class FixtureUser extends User
{
    public $incrementing = false;

    protected $table = 'users';

    protected $keyType = 'string';
}

class FixtureIdentity
{
    public function handle(Request $request, Closure $next)
    {
        $identity = $request->bearerToken();
        abort_unless(in_array($identity, ['alice', 'bob'], true), 401);
        if (! $request->attributes->get('zero.controller.dispatch')) {
            $user = (new FixtureUser)->forceFill(['id' => $identity, 'access_blocked' => false]);
            $user->exists = true; // These fixture identities have persisted users; Gates may call fresh().
            auth()->setUser($user);
            $request->setUserResolver(fn () => $user);
        }
        if ($request->is('callbacks/zero/query')) {
            file_put_contents(getenv('ZERO_INTEGRATION_ROOT').'/query-callbacks.log', $request->getContent()."\n", FILE_APPEND);
        }
        if ($request->is('callbacks/zero/query') && str_contains($request->getContent(), '"desc"')) {
            usleep(1200000);
        }

        return $next($request);
    }
}

class FixtureAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        file_put_contents(getenv('ZERO_INTEGRATION_ROOT').'/access-actors.log', json_encode([
            'request' => $request->user()?->access_blocked,
            'auth' => auth()->user()?->access_blocked,
            'transaction' => DB::transactionLevel(),
            'locked' => DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory' AND granted)"),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        abort_if(is_file(getenv('ZERO_INTEGRATION_ROOT').'/access-denied') || $request->user()?->access_blocked, 403);

        return $next($request);
    }
}

class FixtureReads
{
    public function index(): Response
    {
        return Inertia::render('fixture/related', [
            'tasks' => Task::zeroQuery()->with('tags'),
        ]);
    }
}

class TasksTable
{
    public function definition(TableContext $context): Table
    {
        return Table::for(Task::class)->visibleTo($context->user())
            ->allowedFilters([
                AllowedFilter::text('title')->operators(['equals', 'notEquals', 'contains', 'startsWith', 'endsWith']),
                AllowedFilter::exact('completed', 'done'),
                AllowedFilter::exact('list', 'list_id')->nullable(),
                AllowedFilter::date('date', 'completed_at')->operators(['equals', 'notEquals', 'in', 'gt', 'gte', 'lt', 'lte'])->nullable(),
            ])->allowedSorts(['title', 'completed_at'])
            ->allowedGroups([AllowedGroup::field('done'), AllowedGroup::belongsTo('list')->label('name')])
            ->pageSize(2);
    }
}

class FixtureMutations
{
    /** @return array{id: string} */
    #[ZeroMutation]
    public function store(ControllerMutationRequest $request): array
    {
        $task = Task::create($request->validated());

        return ['id' => $task->id];
    }

    #[ZeroMutation]
    public function update(ControllerMutationRequest $request, Task $task): void
    {
        Gate::authorize('update', $task);
        $task->update($request->validated());
    }
}

$root = getenv('ZERO_INTEGRATION_ROOT');
if (! is_string($root) || ! is_dir($root) || ! str_starts_with(basename($root), 'zero-live-')) {
    throw new RuntimeException('Missing isolated fixture root.');
}
$app = Application::configure(basePath: $root)->withProviders([ZeroServiceProvider::class, ZeroInertiaServiceProvider::class])
    // This stateless fixture substitutes its test bearer identities for browser
    // sessions. The Todos browser proof exercises real web/auth/CSRF middleware.
    ->withMiddleware(function (Illuminate\Foundation\Configuration\Middleware $middleware): void {
        $middleware->group('web', [FixtureIdentity::class]);
        $middleware->alias(['auth' => FixtureIdentity::class]);
    })->withExceptions()->create();
$app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($root) {
    $app['config']->set([
        'logging.default' => 'single', 'logging.channels.single' => ['driver' => 'single', 'path' => $root.'/laravel.log'],
        'app.env' => 'local', 'app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('0', 32)),
        'app.url' => getenv('APP_URL'), 'app.locale' => 'en', 'app.fallback_locale' => 'en',
        'database.default' => 'testing', 'database.connections.testing' => [
            'driver' => 'pgsql', 'host' => getenv('ZERO_TEST_PG_HOST') ?: '127.0.0.1',
            'port' => getenv('ZERO_TEST_PG_PORT') ?: 5432, 'database' => getenv('ZERO_LIVE_DATABASE'),
            'username' => getenv('ZERO_TEST_PG_USER') ?: 'postgres', 'password' => getenv('ZERO_TEST_PG_PASSWORD') ?: '',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ],
        'zero.context' => ZeroContext::class,
        'zero.context_resolver' => ContextResolver::class,
        'zero.middleware' => [FixtureIdentity::class], 'zero.api_key' => getenv('ZERO_API_KEY'),
        'zero.app_id' => getenv('ZERO_APP_ID'), 'zero.upstream_schema' => getenv('ZERO_APP_ID').'_0',
        'zero.publication' => getenv('ZERO_APP_PUBLICATIONS'), 'zero.generation.output' => $root.'/generated.ts',
        'zero.dev.port' => (int) getenv('ZERO_PORT'), 'zero.dev.replica' => $root.'/replica.db',
        'zero.sync_publication_on_migrate' => true, 'zero.route_prefix' => 'callbacks/zero',
    ]);
});
ini_set('error_log', $root.'/php.log');
$kernel = $app->make(PHP_SAPI === 'cli' ? Illuminate\Contracts\Console\Kernel::class : Kernel::class);
$kernel->bootstrap();
app()->instance(ZeroForLaravel\Support\ApplicationClasses::class, new ZeroForLaravel\Tests\Fixtures\DiscoveredClasses([
    Task::class, TaskList::class, Tag::class, Comment::class, TasksTable::class, TaskQueries::class,
]));
OwnerPermissions::configure();
config(['permissions.subject.class' => FixtureUser::class]);
Task::addGlobalScope(new VisibleToCurrentUser);
Tag::addGlobalScope(new VisibleToCurrentUser);
Route::post('/fixture/tasks', [FixtureMutations::class, 'store'])->middleware(FixtureIdentity::class);
Route::put('/fixture/tasks/{task}', [FixtureMutations::class, 'update'])->middleware([FixtureIdentity::class, SubstituteBindings::class]);
Route::get('/fixture/tasks/sorted/{direction}', [QueryController::class, 'sorted'])->middleware([FixtureIdentity::class, SubstituteBindings::class]);
Route::get('/fixture/tasks/{task}', [QueryController::class, 'show'])->middleware([FixtureIdentity::class, FixtureAccessMiddleware::class, SubstituteBindings::class]);
Route::get('/fixture/related', [FixtureReads::class, 'index'])->middleware(FixtureIdentity::class);
foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
    Gate::define($ability, fn ($user, $model) => $ability === 'create' || ($model->user_id === $user->getAuthIdentifier() && $model->title !== 'Locked'));
}
if (PHP_SAPI === 'cli') {
    if (in_array($argv[1] ?? null, ['revoke', 'restore', 'revoke-rollback', 'revoke-related', 'restore-related'], true)) {
        if ($argv[1] === 'revoke-rollback') {
            DB::beginTransaction();
        }
        $permissions = Casbin::model('owner');
        $fields = ['owner:alice', str_ends_with($argv[1], '-related') ? 'tags' : 'tasks', 'view', 'owner', 'allow'];
        if (! str_starts_with($argv[1], 'restore')) {
            $permissions->removePolicy(...$fields);
        } else {
            $permissions->addPolicy(...$fields);
        }
        if ($argv[1] === 'revoke-rollback') {
            DB::rollBack();
        }
        exit(0);
    }
    if (($argv[1] ?? null) === 'reset') {
        exit(Artisan::call('zero:reset', [($argv[2] ?? null) === 'force' ? '--force' : '--dry-run' => true], new ConsoleOutput));
    }
    if (($argv[1] ?? null) === 'dev') {
        exit(Artisan::call('zero:dev', [], new ConsoleOutput));
    }
    if (Artisan::call('migrate', ['--path' => __DIR__.'/migrations', '--realpath' => true, '--force' => true]) !== 0 || Artisan::call('zero:generate') !== 0 || Artisan::call('zero:generate', ['--check' => true]) !== 0) {
        throw new RuntimeException('Fixture setup failed.');
    }
    Schema::table('users', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->boolean('access_blocked')->default(false);
    });
    exit(0);
}
$request = Request::capture();
$response = $kernel->handle($request);
// Lose the first successful callback response after commit. Native replication
// must recover its data; retries must not execute the action a second time.
if ($request->is('callbacks/zero/mutate') && str_contains($request->getContent(), 'Recovered result') && $response->isSuccessful() && ! is_file($root.'/lost-response.json')) {
    file_put_contents($root.'/lost-response.json', $response->getContent());
    $response = new Symfony\Component\HttpFoundation\Response('Simulated lost response', 503);
}
$response->send();
$kernel->terminate($request, $response);
