<?php

// Isolated fixture server. Its test-only bearer identities are never application middleware.
require __DIR__.'/../../packages/laravel/vendor/autoload.php';
// QueryDirection is declared alongside this test controller, not in its own PSR-4 file.
require_once __DIR__.'/../../packages/laravel/tests/Fixtures/QueryController.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Tests\Fixtures\{Task, TaskList, Tag, Comment};
use ZeroForLaravel\ZeroServiceProvider;

class FixtureIdentity
{
    public function handle(Request $request, Closure $next)
    {
        $identity = $request->bearerToken();
        abort_unless(in_array($identity, ['alice', 'bob'], true), 401);
        $user = (new User)->setKeyType('string')->forceFill(['id' => $identity]);
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);
        if ($request->is('callbacks/zero/query') && str_contains($request->getContent(), '"desc"')) { usleep(1200000); }
        return $next($request);
    }
}

class FixtureMutations
{
    /** @return array{id: string} */
    #[\ZeroForLaravel\Attributes\ZeroMutation]
    public function store(\ZeroForLaravel\Tests\Fixtures\ControllerMutationRequest $request): array
    {
        $task = Task::create($request->validated());
        return ['id' => $task->id];
    }

    #[\ZeroForLaravel\Attributes\ZeroMutation]
    public function update(\ZeroForLaravel\Tests\Fixtures\ControllerMutationRequest $request, Task $task): void
    {
        Gate::authorize('update', $task);
        $task->update($request->validated());
    }
}

$root = getenv('ZERO_INTEGRATION_ROOT');
if (! is_string($root) || ! is_dir($root) || ! str_starts_with(basename($root), 'zero-live-')) { throw new RuntimeException('Missing isolated fixture root.'); }
$app = Application::configure(basePath: $root)->withProviders([ZeroServiceProvider::class])->withExceptions()->create();
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
        'zero.models' => [Task::class, TaskList::class, Tag::class, Comment::class],
        'zero.middleware' => [FixtureIdentity::class], 'zero.api_key' => getenv('ZERO_API_KEY'),
        'zero.app_id' => getenv('ZERO_APP_ID'), 'zero.upstream_schema' => getenv('ZERO_APP_ID').'_0',
        'zero.publication' => getenv('ZERO_APP_PUBLICATIONS'), 'zero.output' => $root.'/generated.ts',
        'zero.dev.port' => (int) getenv('ZERO_PORT'), 'zero.dev.replica' => $root.'/replica.db',
        'zero.sync_publication_on_migrate' => true, 'zero.route_prefix' => 'callbacks/zero',
    ]);
});
ini_set('error_log', $root.'/php.log');
$kernel = $app->make(PHP_SAPI === 'cli' ? \Illuminate\Contracts\Console\Kernel::class : Kernel::class);
$kernel->bootstrap();
\Illuminate\Support\Facades\Route::post('/fixture/tasks', [FixtureMutations::class, 'store'])->middleware(FixtureIdentity::class);
\Illuminate\Support\Facades\Route::put('/fixture/tasks/{task}', [FixtureMutations::class, 'update'])->middleware([FixtureIdentity::class, \Illuminate\Routing\Middleware\SubstituteBindings::class]);
\Illuminate\Support\Facades\Route::get('/fixture/tasks/sorted/{direction}', [\ZeroForLaravel\Tests\Fixtures\QueryController::class, 'sorted'])->middleware([FixtureIdentity::class, \Illuminate\Routing\Middleware\SubstituteBindings::class]);
foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
    Gate::define($ability, fn ($user, $model) => $ability === 'create' || ($model->user_id === $user->getAuthIdentifier() && $model->title !== 'Locked'));
}
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? null) === 'reset') { exit(Artisan::call('zero:reset', [($argv[2] ?? null) === 'force' ? '--force' : '--dry-run' => true], new \Symfony\Component\Console\Output\ConsoleOutput)); }
    if (($argv[1] ?? null) === 'dev') { exit(Artisan::call('zero:dev', [], new \Symfony\Component\Console\Output\ConsoleOutput)); }
    if (Artisan::call('migrate', ['--path' => __DIR__.'/migrations', '--realpath' => true, '--force' => true]) !== 0 || Artisan::call('zero:generate') !== 0 || Artisan::call('zero:generate', ['--check' => true]) !== 0) { throw new RuntimeException('Fixture setup failed.'); }
    $contracts = [];
    foreach (['store', 'update'] as $method) {
        $program = app(\ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler::class)->compile(FixtureMutations::class, $method);
        $contracts[$program->name()] = $program->contract();
    }
    file_put_contents($root.'/controller-contracts.json', json_encode($contracts, JSON_THROW_ON_ERROR));
    exit(0);
}
$request = Request::capture();
$response = $kernel->handle($request);
// Lose the first successful callback response after commit. Native replication
// must recover its data; retries must not execute the action a second time.
if ($request->is('callbacks/zero/mutate') && str_contains($request->getContent(), 'Recovered result') && $response->isSuccessful() && ! is_file($root.'/lost-response.json')) {
    file_put_contents($root.'/lost-response.json', $response->getContent());
    $response = new \Symfony\Component\HttpFoundation\Response('Simulated lost response', 503);
}
$response->send();
$kernel->terminate($request, $response);
