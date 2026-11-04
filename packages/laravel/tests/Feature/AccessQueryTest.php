<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use LogicException;
use PhpParser\ParserFactory;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Http\CaptureTransportCookies;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Publication\PublicationManager;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Tests\Fixtures\ContextResolver;
use ZeroForLaravel\Tests\Fixtures\PermissionAdminPolicy;
use ZeroForLaravel\Tests\Fixtures\PermissionList;
use ZeroForLaravel\Tests\Fixtures\PermissionQueryController;
use ZeroForLaravel\Tests\Fixtures\PermissionUser;
use ZeroForLaravel\Tests\PostgresTestCase;

uses(PostgresTestCase::class);

final class AccessWatchMiddleware
{
    public static bool $allow = true;

    public static ?Closure $after = null;

    /** @var list<array{admin: mixed, facade: mixed, transaction: int, locked: mixed}> */
    public static array $observations = [];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        self::$observations[] = [
            'admin' => $request->user()?->getAttribute('is_admin'),
            'facade' => Auth::user()?->getAttribute('is_admin'),
            'transaction' => DB::transactionLevel(),
            'locked' => DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory' AND granted)"),
        ];
        abort_unless(self::$allow, 403);

        $response = $next($request);
        $after = self::$after;
        self::$after = null;
        if ($after instanceof Closure) {
            $after();
        }

        return $response;
    }
}

/** An explicit trap proving that watches only invoke the registered query terminal. */
final class AccessWatchNeverController
{
    public function show(PermissionList $list): never
    {
        throw new LogicException('The HTTP controller must never execute for access watch '.$list->getTable());
    }
}

/** @return array<string, RegisteredQuery> */
function accessWatchRegistrations(): array
{
    $registry = new ModelRegistry([PermissionList::class]);
    app()->instance(ModelRegistry::class, $registry);
    $definition = (new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry))
        ->compile(PermissionQueryController::class, 'show')[0];
    $registrations = eval(substr((new ControllerQueryGenerator(new ZodSchemaDriver))->php([$definition]), 5));
    if (! is_array($registrations)) {
        throw new LogicException('Expected generated registrations.');
    }

    foreach ($registrations as $name => $registration) {
        if (! is_string($name) || ! $registration instanceof RegisteredQuery) {
            throw new LogicException('Expected generated query registration.');
        }
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries($registrations));

    return $registrations;
}

/** @return array{query: string, args: array{list: int}, generation: string} */
function accessWatchInput(int $list = 7, string $generation = 'page-1'): array
{
    $name = array_key_first(resolve(RegisteredQueries::class)->all());
    if ($name === null) {
        throw new LogicException('Missing registered access query.');
    }

    return ['query' => $name, 'args' => ['list' => $list], 'generation' => $generation];
}

beforeEach(function (): void {
    app()->register(PermissionServiceProvider::class);
    PermissionSchema::create(DB::connection());
    Schema::create('permission_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('department_id');
        $table->boolean('is_admin')->default(false);
    });
    Schema::create('permission_test_lists', function (Blueprint $table): void {
        $table->id();
        $table->string('owner_id');
        $table->string('department_id');
        $table->string('name');
    });
    config([
        'permissions.models' => ['default' => __DIR__.'/../../config/permissions/scoped-rbac-abac.conf'],
        'permissions.subject.class' => PermissionUser::class,
        'permissions.subject.attributes' => [
            'department_id' => ['column' => 'department_id', 'type' => 'string'],
            'is_admin' => ['column' => 'is_admin', 'type' => 'boolean'],
        ],
        'permissions.resources' => [PermissionList::class => [
            'type' => 'todo-list', 'domain' => ['column' => 'id', 'prefix' => 'list:'],
            'attributes' => [
                'owner_id' => ['column' => 'owner_id', 'type' => 'id'],
                'department_id' => ['column' => 'department_id', 'type' => 'string'],
            ],
        ]],
        'zero.context_resolver' => ContextResolver::class,
    ]);
    (new ProjectionManager)->rebuild();
    PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    PermissionUser::create(['id' => 84, 'department_id' => 'support']);
    PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Private']);
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
    AccessWatchMiddleware::$allow = true;
    AccessWatchMiddleware::$after = null;
    AccessWatchMiddleware::$observations = [];
    Route::get('/access-lists/{list}', [PermissionQueryController::class, 'show'])
        ->middleware([SubstituteBindings::class, AccessWatchMiddleware::class]);
    Route::post('/native-query', [\ZeroForLaravel\Http\ZeroController::class, 'query']);
    accessWatchRegistrations();
    \Pest\Laravel\actingAs(PermissionUser::findOrFail(42));
});

afterEach(function (): void {
    if (! getenv('ZERO_TEST_PG_DATABASE')) {
        return;
    }

    Sleep::fake(false);
    Date::setTestNow();
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'permission_%'") as $row) {
        if ($row instanceof stdClass && is_string($row->tablename)) {
            Schema::dropIfExists($row->tablename);
        }
    }

    foreach (DB::select("SELECT proname, pg_get_function_identity_arguments(oid) AS args FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname LIKE 'zero_permissions_%'") as $row) {
        if ($row instanceof stdClass && is_string($row->proname) && is_string($row->args)) {
            DB::connection()->getPdo()->exec('DROP FUNCTION IF EXISTS "'.$row->proname.'"('.$row->args.') CASCADE');
        }
    }
});

test('native access checks run under the projection transaction lock and never run the controller', function (): void {
    $generated = array_values(resolve(RegisteredQueries::class)->all())[0];
    if (! $generated instanceof RegisteredQuery) {
        throw new LogicException('Expected a controller query.');
    }

    Route::get('/access-trap/{list}', [AccessWatchNeverController::class, 'show'])
        ->middleware([SubstituteBindings::class, AccessWatchMiddleware::class]);
    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        'trap' => new RegisteredQuery(AccessWatchNeverController::class.'@show', $generated->component, $generated->prop,
            $generated->parameters, $generated->declaration(...), guards: $generated->guards),
    ]));
    accessNativeQuery(accessNativeInput())->assertOk()->assertJsonMissingPath('queries.0.error');
    expect(DB::table('permission_access_results')->value('state'))->toBe('allowed');
    expect(AccessWatchMiddleware::$observations)->toHaveCount(1)
        ->and(AccessWatchMiddleware::$observations[0])->toMatchArray(['transaction' => 1, 'locked' => true]);
    expect(DB::table('permission_access_checks')->count())->toBe(1);
    $plan = implode("\n", (new PublicationManager(resolve(ModelRegistry::class)))->plan('access_watch_publication'));
    expect($plan)->not->toContain('permission_access_watches', 'permission_events');
});

test('browser session cookies survive native access checks and replayed web middleware', function (): void {
    /** @var PostgresTestCase $this */
    $sessions = sys_get_temp_dir().'/zero-access-sessions-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($sessions);
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'auth.providers.users.model' => PermissionUser::class,
        'session.driver' => 'file',
        'session.files' => $sessions,
        'session.lottery' => [1, 1],
        'zero.api_key' => 'native-access-test',
    ]);
    Auth::forgetGuards();
    $kernel = resolve(Kernel::class);
    if (! $kernel instanceof \Illuminate\Foundation\Http\Kernel) {
        throw new LogicException('Expected the Laravel HTTP kernel.');
    }

    $kernel->prependToMiddlewarePriority(CaptureTransportCookies::class);
    Route::post('/access-session-login', function (): JsonResponse {
        Auth::login(PermissionUser::findOrFail(42));

        return response()->json(['session' => session()->getId()]);
    })->middleware('web');
    Route::get('/access-lists/{list}', [PermissionQueryController::class, 'show'])
        ->middleware(['web', 'auth', SubstituteBindings::class]);
    Route::post('/native-query', [\ZeroForLaravel\Http\ZeroController::class, 'query'])
        ->middleware([\ZeroForLaravel\Http\AuthenticateZeroCache::class, 'web', 'auth']);
    try {
        $login = $this->postJson('/access-session-login')->assertOk();
        $session = $login->json('session');
        $cookie = $login->getCookie(config()->string('session.cookie'), false);
        if (! is_string($session) || $cookie === null) {
            throw new LogicException('Expected a browser session cookie.');
        }

        $value = $cookie->getValue();
        if ($value === null) {
            throw new LogicException('Expected an encrypted session cookie value.');
        }

        Auth::forgetGuards();
        $input = accessNativeInput();
        $this->withUnencryptedCookie($cookie->getName(), $value)->withHeader('X-Api-Key', 'native-access-test');
        accessNativeQuery($input)->assertOk()->assertJsonMissingPath('queries.0.error');
        expect(DB::table('permission_access_results')->value('state'))->toBe('allowed');
        $first = DB::table('permission_access_checks')->value('owner');
        // A second browser session for the same user must not reuse its predecessor's approval.
        $this->withUnencryptedCookie($cookie->getName(), '');
        Auth::forgetGuards();
        $again = $this->postJson('/access-session-login')->assertOk();
        $nextCookie = $again->getCookie(config()->string('session.cookie'), false);
        if ($nextCookie === null || $nextCookie->getValue() === null) {
            throw new LogicException('Expected a second browser session cookie.');
        }

        Auth::forgetGuards();
        $this->withUnencryptedCookie($nextCookie->getName(), $nextCookie->getValue());
        accessNativeQuery($input)->assertOk()->assertJsonMissingPath('queries.0.error');
        expect(DB::table('permission_access_checks')->where('owner', '!=', $first)->count())->toBe(1);
    } finally {
        File::deleteDirectory($sessions);
    }
});

test('native access rejects untrusted actors unknown queries unguarded queries and protected fields', function (): void {
    accessNativeQuery([...accessNativeInput(), 'user_id' => 84])->assertOk()->assertJsonPath('queries.0.error', 'parse');
    $input = accessNativeInput();
    $input['bindings'][0]['query'] = 'client-supplied';
    accessNativeQuery($input)->assertOk()->assertJsonPath('queries.0.error', 'parse');
    $input = accessNativeInput();
    $input['bindings'][0]['args']['user_id'] = 84;
    accessNativeQuery($input)->assertOk()->assertJsonPath('queries.0.error', 'parse');

    $registered = array_values(resolve(RegisteredQueries::class)->all())[0];
    if (! $registered instanceof RegisteredQuery) {
        throw new LogicException('Expected a controller query.');
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        'unguarded' => new RegisteredQuery($registered->action, $registered->component, $registered->prop,
            $registered->parameters, $registered->declaration(...)),
    ]));
    accessNativeQuery(accessNativeInput())->assertOk()->assertJsonPath('queries.0.error', 'parse');
    Auth::forgetGuards();
    accessNativeQuery([])->assertOk()->assertJsonPath('queries.0.details.status', 401);
    expect(DB::table('permission_access_checks')->count())->toBe(0);
});

test('a concurrent source revocation cannot commit between the initial Gate and durable registration', function (): void {
    $settings = DB::connection()->getConfig();
    if (! is_array($settings) || ! is_string($settings['host']) || ! is_string($settings['database'])
        || ! is_string($settings['username']) || ! is_string($settings['password'])
        || (! is_int($settings['port']) && ! is_string($settings['port']))) {
        throw new LogicException('Invalid PostgreSQL test connection.');
    }

    $writer = new Process([PHP_BINARY, '-r', <<<'PHP'
        $pdo = new PDO(getenv('ACCESS_DSN'), getenv('ACCESS_USER'), getenv('ACCESS_PASSWORD'));
        $pdo->exec("SET application_name = 'access_watch_concurrent_writer'; SET lock_timeout = '10s'");
        echo "connected\n";
        $pdo->exec("UPDATE permission_test_users SET department_id = 'support' WHERE id = 42");
        echo "committed\n";
        PHP,
    ], null, [
        'ACCESS_DSN' => 'pgsql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'],
        'ACCESS_USER' => $settings['username'], 'ACCESS_PASSWORD' => $settings['password'],
    ]);
    AccessWatchMiddleware::$after = function () use ($writer): void {
        // The real binding and Gate have succeeded; store has not inserted yet.
        expect(DB::table('permission_access_checks')->count())->toBe(0);
        $writer->start();
        expect($writer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'connected')))->toBeTrue();
        $blocked = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = 'access_watch_concurrent_writer' AND wait_event = 'advisory')") === true) {
                $blocked = true;
                break;
            }

            Sleep::usleep(10000);
        }

        expect($blocked)->toBeTrue();
    };
    accessNativeQuery(accessNativeInput())->assertOk()->assertJsonMissingPath('queries.0.error');
    expect($writer->wait())->toBe(0)->and($writer->getOutput())->toContain('committed');
    expect(DB::table('permission_access_checks')->count())->toBe(1);
    expect(DB::table('permission_access_results')->value('state'))->toBe('allowed');

    $next = accessNativeInput();
    accessNativeQuery($next)->assertOk()->assertJsonMissingPath('queries.0.error');
    expect(DB::table('permission_access_results')->where('generation', $next['generation'])->value('state'))->toBe('denied');
});

test('native checks reload admin attributes for request Auth middleware and Gates and restore original auth', function (): void {
    Gate::policy(PermissionList::class, PermissionAdminPolicy::class);
    DB::table('permission_test_users')->where('id', 42)->update(['is_admin' => true]);
    $captured = PermissionUser::findOrFail(42);
    \Pest\Laravel\actingAs($captured);
    accessWatchRegistrations();
    accessNativeQuery(accessNativeInput())->assertOk();
    DB::table('permission_test_users')->where('id', 42)->update(['is_admin' => false, 'department_id' => 'support']);
    $next = accessNativeInput();
    accessNativeQuery($next)->assertOk()->assertJsonMissingPath('queries.0.error');
    expect(DB::table('permission_access_results')->where('generation', $next['generation'])->first())->toMatchObject(['state' => 'denied', 'status' => 403]);
    $observation = array_pop(AccessWatchMiddleware::$observations);
    expect($observation)->toMatchArray(['admin' => false, 'facade' => false]);
    expect(Auth::user())->toBe($captured)->and($captured->is_admin)->toBeTrue();
});

test('concealed denial and deleted resources have identical native outcomes', function (): void {
    config(['permissions.denied_status' => 404]);
    accessWatchRegistrations();
    DB::table('permission_test_users')->where('id', 42)->update(['department_id' => 'support']);
    accessNativeQuery(accessNativeInput())->assertOk();
    DB::table('permission_test_lists')->where('id', 7)->delete();
    accessNativeQuery(accessNativeInput())->assertOk();
    $outcomes = DB::table('permission_access_results')->get(['state', 'status']);
    expect($outcomes)->toHaveCount(2);
    expect($outcomes[0])->toEqual($outcomes[1])->toMatchObject(['state' => 'denied', 'status' => 404]);
});

test('unknown configuration failures never become confirmed native denial', function (): void {
    accessNativeQuery(accessNativeInput())->assertOk();
    config(['permissions.subject.prefix' => 'stale:']);
    $input = accessNativeInput();
    accessNativeQuery($input)->assertOk()->assertJsonMissingPath('queries.0.error');
    expect(DB::table('permission_access_results')->where('generation', $input['generation'])->first())
        ->toMatchObject(['state' => 'unknown', 'status' => null]);
});

/** @return array{generation: string, bindings: list<array{binding: string, query: string, args: array{list: int}}> } */
function accessNativeInput(int $list = 7): array
{
    $input = accessWatchInput($list);

    return ['generation' => (string) \Illuminate\Support\Str::uuid(), 'bindings' => [[
        'binding' => (string) \Illuminate\Support\Str::uuid(), 'query' => $input['query'], 'args' => $input['args'],
    ]]];
}

/** @param array<string, mixed> $input
 * @return TestResponse<Response>
 */
function accessNativeQuery(array $input, string $name = '__zero_access_status'): TestResponse
{
    return \Pest\Laravel\postJson('/native-query', ['transform', [['id' => 'check', 'name' => $name, 'args' => [$input]]]]);
}

test('native access checks stream safe typed decisions and recover from initially denied routes', function (): void {
    $input = accessNativeInput();
    Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
    accessNativeQuery($input)->assertOk()->assertJsonPath('queries.0.ast.table', 'permission_access_results')->assertJsonMissingPath('queries.0.error');
    expect(DB::table('permission_access_results')->where('generation', $input['generation'])->first())
        ->toMatchObject(['state' => 'denied', 'status' => 403]);
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
    $next = accessNativeInput();
    accessNativeQuery($next)->assertOk()->assertJsonMissingPath('queries.0.error');
    expect(DB::table('permission_access_results')->where('generation', $next['generation'])->first())
        ->toMatchObject(['state' => 'allowed', 'status' => null]);
    expect(array_keys((array) DB::table('permission_access_results')->first()))->toBe(['id', 'generation', 'binding', 'state', 'status']);
    expect(AccessWatchMiddleware::$observations)->toHaveCount(2);
});

test('native access generations are idempotent scoped and never renew from background retransforms', function (): void {
    $input = accessNativeInput();
    $first = accessNativeQuery($input)->assertOk()->json('queries.0.ast');
    $expiry = DB::table('permission_access_checks')->value('expires_at');
    $observations = count(AccessWatchMiddleware::$observations);
    expect(accessNativeQuery($input)->assertOk()->json('queries.0.ast'))->toBe($first);
    expect(DB::table('permission_access_checks')->value('expires_at'))->toBe($expiry);
    expect(count(AccessWatchMiddleware::$observations))->toBe($observations);

    $changed = $input;
    $changed['bindings'][0]['args']['list'] = 8;
    accessNativeQuery($changed)->assertOk()->assertJsonPath('queries.0.error', 'parse');
    \Pest\Laravel\actingAs(PermissionUser::findOrFail(84));
    expect(accessNativeQuery($input)->assertOk()->json('queries.0.ast'))->not->toBe($first);
    expect(DB::table('permission_access_results')->where('generation', $input['generation'])->count())->toBe(2);
});

test('the targeted access migration upgrades already published legacy checks without dropping their publication', function (): void {
    accessNativeQuery(accessNativeInput())->assertOk()->assertJsonMissingPath('queries.0.error');
    Schema::table('permission_access_checks', fn (Blueprint $table) => $table->dropColumn(['global_key', 'dependencies']));
    DB::statement("ALTER TABLE permission_access_checks ADD COLUMN revision varchar(36) NOT NULL DEFAULT 'legacy'");
    $publication = 'access_upgrade_test';
    try {
        (new PublicationManager(resolve(ModelRegistry::class)))->sync($publication);
        $migration = require __DIR__.'/../../database/migrations/2026_11_03_000003_targeted_access_checks.php';
        if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
            throw new LogicException('Expected an executable access migration.');
        }

        $migration->up();
        (new PublicationManager(resolve(ModelRegistry::class)))->sync($publication);
        $input = accessNativeInput();
        accessNativeQuery($input)->assertOk()->assertJsonMissingPath('queries.0.error');
        expect(DB::table('permission_access_results')->where('generation', $input['generation'])->value('state'))->toBe('allowed');
        expect(DB::table('pg_publication_tables')->where('pubname', $publication)->where('tablename', 'permission_access_checks')->count())->toBe(1);
    } finally {
        DB::statement('DROP PUBLICATION IF EXISTS access_upgrade_test');
    }
});

test('native access checks preserve rolled back grants without a global broadcast table', function (): void {
    $input = accessNativeInput();
    $ast = accessNativeQuery($input)->assertOk()->json('queries.0.ast');
    expect(Schema::hasTable('permission_access_revision'))->toBeFalse();
    try {
        DB::transaction(function (): void {
            Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        expect(accessNativeQuery($input)->assertOk()->json('queries.0.ast'))->toBe($ast);
    }

    accessNativeQuery(accessNativeInput())->assertOk();
    expect(DB::table('permission_access_results')->pluck('state')->all())->toBe(['allowed', 'allowed']);
    PermissionUser::whereKey(42)->update(['department_id' => 'support']);
    (new ProjectionManager)->rebuild();
    $next = accessNativeInput();
    accessNativeQuery($next)->assertOk();
    expect(DB::table('permission_access_results')->where('generation', $next['generation'])->value('state'))->toBe('denied');
});

test('native access checks retain route middleware binding and fresh actor semantics', function (): void {
    $input = accessNativeInput();
    AccessWatchMiddleware::$allow = false;
    accessNativeQuery($input)->assertOk();
    expect(DB::table('permission_access_results')->where('generation', $input['generation'])->first())->toMatchObject(['state' => 'denied', 'status' => 403]);
    AccessWatchMiddleware::$allow = true;
    $missing = accessNativeInput(8);
    accessNativeQuery($missing)->assertOk();
    expect(DB::table('permission_access_results')->where('generation', $missing['generation'])->first())->toMatchObject(['state' => 'denied', 'status' => 404]);
    $stale = PermissionUser::findOrFail(42);
    PermissionUser::whereKey(42)->update(['is_admin' => true]);
    \Pest\Laravel\actingAs($stale);
    accessNativeQuery(accessNativeInput())->assertOk();
    expect(AccessWatchMiddleware::$observations[count(AccessWatchMiddleware::$observations) - 1] ?? null)
        ->toMatchArray(['admin' => true, 'facade' => true, 'transaction' => 1, 'locked' => true]);
    expect(Auth::user()?->getAttribute('is_admin'))->toBeFalse();
});

test('native access bounds generations batches and cleanup without publishing authority metadata', function (): void {
    config(['permissions.access.max_bindings' => 1]);
    for ($i = 0; $i < 4; $i++) {
        accessNativeQuery(accessNativeInput())->assertOk()->assertJsonMissingPath('queries.0.error');
    }

    accessNativeQuery(accessNativeInput())->assertOk()->assertJsonPath('queries.0.details.status', 429);
    DB::table('permission_access_checks')->update(['expires_at' => now()->subSecond()]);
    accessNativeQuery(accessNativeInput())->assertOk()->assertJsonMissingPath('queries.0.error');
    expect(DB::table('permission_access_results')->count())->toBe(1);
    $input = accessNativeInput();
    $input['bindings'][] = $input['bindings'][0];
    accessNativeQuery($input)->assertOk()->assertJsonPath('queries.0.error', 'parse');
    $input = accessNativeInput();
    $input['owner'] = 'foreign';
    accessNativeQuery($input)->assertOk()->assertJsonPath('queries.0.error', 'parse');
    $plan = implode("\n", (new PublicationManager(resolve(ModelRegistry::class)))->plan('access_native_publication'));
    expect($plan)->toContain('permission_access_results', 'permission_access_checks', 'permission_installation');
    expect($plan)->not->toContain('permission_access_revision');

    $generated = (new \ZeroForLaravel\Generation\TypeScriptGenerator)->generate(resolve(ModelRegistry::class));
    expect($generated)->toContain('permission_access_results');
    expect($generated)->not->toContain('permission_access_revision', 'dependencies');
    expect($generated)->not->toContain('permission_access_checks', 'fingerprint', 'expires_at');
});
