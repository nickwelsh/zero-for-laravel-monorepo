<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Middleware;
use Symfony\Component\Console\Output\ConsoleOutput;
use TodoMigration\TodoController;
use ZeroForLaravel\Generation\ReactFrontendDriver;
use ZeroForLaravel\Inertia\ZeroInertiaServiceProvider;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Support\ApplicationClasses;
use ZeroForLaravel\Tests\Fixtures\ContextResolver;
use ZeroForLaravel\Tests\Fixtures\DiscoveredClasses;
use ZeroForLaravel\Tests\Fixtures\OwnerUser;
use ZeroForLaravel\Tests\Fixtures\Todos\Todo;
use ZeroForLaravel\Tests\Fixtures\Todos\TodoPolicy;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
use ZeroForLaravel\ZeroServiceProvider;

$package = dirname(__DIR__, 3).'/packages/laravel';
require $package.'/vendor/autoload.php';
require_once __DIR__.'/TodoController.php';

$root = getenv('ZERO_INTEGRATION_ROOT');
$database = getenv('ZERO_LIVE_DATABASE');
if (! is_string($root) || ! is_dir($root) || ! str_starts_with(basename($root), 'zero-todos-') || ! preg_match('/^laravel_zero_test_todos_[a-f0-9]+$/D', (string) $database)) {
    throw new RuntimeException('This fixture requires an isolated Todo database and directory.');
}

class PreserveFilterOrder extends Middleware
{
    public function urlResolver(): Closure
    {
        // Inertia defaults to fullUrl(), which sorts query keys on initial load.
        // Keep the baseline's literal URL so the stay/recovery assertion is exact.
        return fn (Request $request): string => $request->getRequestUri();
    }
}

// The route's business constraint is deliberately server-only: an otherwise
// valid form can predict a visit but the native mutation transaction must fail.
class RejectReservedTitle
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->input('title') === 'Reserved title') {
            throw ValidationException::withMessages(['title' => 'This title is reserved.']);
        }

        return $next($request);
    }
}

$app = Application::configure(basePath: $root)
    ->withProviders([ZeroServiceProvider::class, PermissionServiceProvider::class, ZeroInertiaServiceProvider::class])
    ->withMiddleware(function (Illuminate\Foundation\Configuration\Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['zero/query', 'zero/mutate']);
    })->withExceptions()->create();
$app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($root, $database, $package): void {
    $app['config']->set('permissions', require $package.'/config/permissions.php');
    $app['config']->set('zero', require $package.'/config/zero.php');
    $app['config']->set([
        'app.env' => 'local', 'app.url' => getenv('APP_URL'), 'app.key' => getenv('APP_KEY'),
        'session.driver' => 'file', 'cache.default' => 'array', 'auth.providers.users.model' => OwnerUser::class,
        'database.default' => 'pgsql', 'database.connections.pgsql.database' => $database,
        'database.connections.pgsql.url' => null, 'database.connections.pgsql.host' => getenv('PGHOST'),
        'database.connections.pgsql.port' => getenv('PGPORT'), 'database.connections.pgsql.username' => getenv('PGUSER'),
        'database.connections.pgsql.password' => getenv('PGPASSWORD'),
        'permissions.models.default' => $package.'/config/permissions/global-acl.conf',
        'permissions.resources' => [Todo::class => ['type' => 'todo']], 'permissions.subject.class' => OwnerUser::class,
        'zero.context' => ZeroContext::class, 'zero.context_resolver' => ContextResolver::class,
        'zero.middleware' => ['web', 'auth'], 'zero.api_key' => getenv('ZERO_API_KEY'),
        'zero.app_id' => getenv('ZERO_APP_ID'), 'zero.upstream_schema' => getenv('ZERO_APP_ID').'_0',
        'zero.publication' => getenv('ZERO_APP_PUBLICATIONS'), 'zero.generation.output' => $root.'/generated.ts',
        'zero.dev.port' => (int) getenv('ZERO_PORT'), 'zero.dev.replica' => $root.'/replica.db',
        'zero.frontend.driver' => ReactFrontendDriver::class,
    ]);
});
$kernel = $app->make(PHP_SAPI === 'cli' ? Illuminate\Contracts\Console\Kernel::class : Kernel::class);
$kernel->bootstrap();
app()->instance(ApplicationClasses::class, new DiscoveredClasses([Todo::class]));
Gate::policy(Todo::class, TodoPolicy::class);
Route::get('/fixture/session', function () {
    auth()->loginUsingId('alice');

    return redirect('/todos?status=open&sort=title');
})->middleware('web');
Route::middleware(['web', 'auth', PreserveFilterOrder::class, RejectReservedTitle::class])->group(function (): void {
    Route::resource('todos', TodoController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::get('/notes', [TodoController::class, 'notes']);
});
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? null) === 'dev') {
        exit(Artisan::call('zero:dev', [], new ConsoleOutput));
    }
    if (in_array($argv[1] ?? null, ['revoke-view', 'restore-view', 'revoke-viewAny'], true)) {
        [$operation, $ability] = explode('-', $argv[1]);
        $policy = ['user:alice', 'todo', $ability, 'any', 'allow'];
        exit(($operation === 'restore' ? Casbin::addPolicy(...$policy) : Casbin::removePolicy(...$policy)) ? 0 : 1);
    }
    PermissionSchema::create(DB::connection());
    Schema::create('todos', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('users', fn (Blueprint $table) => $table->string('id')->primary());
    DB::table('users')->insert(['id' => 'alice']);
    (new ProjectionManager)->rebuild();
    foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
        Casbin::addPolicy('user:alice', 'todo', $ability, 'any', 'allow');
    }
    exit(Artisan::call('zero:generate', [], new ConsoleOutput));
}
$request = Request::capture();
if ($request->is('app.js')) {
    header('Content-Type: text/javascript');
    readfile($root.'/app.js');

    return;
}
file_put_contents($root.'/requests', $request->method().' '.$request->getRequestUri()."\n", FILE_APPEND | LOCK_EX);
$writes = $request->is('zero/mutate') ? array_filter($request->json('mutations', []), fn ($m) => $m['name'] !== '_zero_cleanupResults') : [];
if ($writes !== [] && is_file($root.'/hold')) {
    file_put_contents($root.'/arrived', json_encode($writes, JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 60;
    while (is_file($root.'/hold')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test did not release the native callback.');
        }
        usleep(10000);
        clearstatcache();
    }
}
$response = $kernel->handle($request);
if ($writes !== []) {
    file_put_contents($root.'/results', $response->getContent()."\n", FILE_APPEND | LOCK_EX);
}
$response->send();
$kernel->terminate($request, $response);
