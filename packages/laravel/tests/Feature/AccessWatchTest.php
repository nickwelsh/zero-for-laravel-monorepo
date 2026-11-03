<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
use ZeroForLaravel\Access\AccessController;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Http\ContextResolver;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Publication\PublicationManager;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
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

/** @param TestResponse<Response> $response */
function accessWatchId(TestResponse $response): string
{
    $id = $response->assertCreated()->json('id');
    if (! is_string($id)) {
        throw new LogicException('Expected a watch ID.');
    }

    return $id;
}

/** @param TestResponse<Response> $response
 * @return list<array{id: string, generation: string, revision: string, state: string, status?: int}>
 */
function accessWatchStates(TestResponse $response): array
{
    $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')->assertHeader('X-Accel-Buffering', 'no');
    $states = [];
    foreach (explode("\n", $response->streamedContent()) as $line) {
        if (str_starts_with($line, 'data: ')) {
            $state = json_decode(substr($line, 6), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($state) || ! is_string($state['id'] ?? null) || ! is_string($state['generation'] ?? null)
                || ! is_string($state['revision'] ?? null) || ! is_string($state['state'] ?? null)
                || (isset($state['status']) && ! is_int($state['status']))) {
                throw new LogicException('Invalid SSE access state.');
            }

            $normalized = ['id' => $state['id'], 'generation' => $state['generation'],
                'revision' => $state['revision'], 'state' => $state['state']];
            if (isset($state['status'])) {
                $normalized['status'] = $state['status'];
            }

            $states[] = $normalized;
        }
    }

    return $states;
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
        'permissions.access.stream_seconds' => 0,
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
    Route::post('/zero/access', [AccessController::class, 'store']);
    Route::get('/zero/access/events', [AccessController::class, 'events']);
    Route::delete('/zero/access/{id}', [AccessController::class, 'destroy']);
    accessWatchRegistrations();
    \Pest\Laravel\actingAs(PermissionUser::findOrFail(42));
});

afterEach(function (): void {
    Sleep::fake(false);
    \Illuminate\Support\Facades\Date::setTestNow();
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

test('access registration runs under the projection transaction lock and never runs the controller', function (): void {
    $generated = array_values(resolve(RegisteredQueries::class)->all())[0];
    Route::get('/access-trap/{list}', [AccessWatchNeverController::class, 'show'])
        ->middleware([SubstituteBindings::class, AccessWatchMiddleware::class]);
    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        'trap' => new RegisteredQuery(AccessWatchNeverController::class.'@show', $generated->component, $generated->prop,
            $generated->parameters, $generated->declaration(...), guards: $generated->guards),
    ]));
    $state = \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertCreated()->assertJsonPath('state', 'allowed')
        ->assertJsonPath('generation', 'page-1')->assertJsonMissingPath('status')->json();
    expect($state)->toHaveKeys(['id', 'generation', 'revision', 'state']);
    expect(AccessWatchMiddleware::$observations)->toHaveCount(1)
        ->and(AccessWatchMiddleware::$observations[0])->toMatchArray(['transaction' => 1, 'locked' => true]);
    expect(DB::table('permission_access_watches')->count())->toBe(1);
    $plan = implode("\n", (new PublicationManager(resolve(ModelRegistry::class)))->plan('access_watch_publication'));
    expect($plan)->not->toContain('permission_access_watches', 'permission_events');
});

test('browser session cookies survive access registration and the replayed web middleware', function (): void {
    /** @var PostgresTestCase $this */
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'auth.providers.users.model' => PermissionUser::class,
        'session.driver' => 'file',
        'session.files' => sys_get_temp_dir(),
    ]);
    Auth::forgetGuards();
    $kernel = resolve(\Illuminate\Contracts\Http\Kernel::class);
    if (! $kernel instanceof \Illuminate\Foundation\Http\Kernel) {
        throw new LogicException('Expected the Laravel HTTP kernel.');
    }

    $kernel->prependToMiddlewarePriority(\ZeroForLaravel\Http\CaptureTransportCookies::class);
    Route::post('/access-session-login', function (): \Illuminate\Http\JsonResponse {
        Auth::login(PermissionUser::findOrFail(42));

        return response()->json(['session' => session()->getId()]);
    })->middleware('web');
    Route::get('/access-lists/{list}', [PermissionQueryController::class, 'show'])
        ->middleware(['web', 'auth', SubstituteBindings::class]);
    Route::post('/session-access', [AccessController::class, 'store'])
        ->middleware([\ZeroForLaravel\Http\CaptureTransportCookies::class, 'web', 'auth']);
    $login = $this->postJson('/access-session-login')->assertOk();
    $session = $login->json('session');
    $cookie = $login->getCookie(config()->string('session.cookie'), false);
    if (! is_string($session) || $cookie === null) {
        throw new LogicException('Expected a browser session cookie.');
    }

    try {
        $value = $cookie->getValue();
        if ($value === null) {
            throw new LogicException('Expected an encrypted session cookie value.');
        }

        Auth::forgetGuards();
        $this->withUnencryptedCookie($cookie->getName(), $value)
            ->postJson('/session-access', accessWatchInput())->assertCreated()->assertJsonPath('state', 'allowed');
    } finally {
        \Illuminate\Support\Facades\File::delete(sys_get_temp_dir().'/'.$session);
    }
});

test('access registration rejects untrusted actors unknown queries unguarded queries and protected fields', function (): void {
    \Pest\Laravel\postJson('/zero/access', [...accessWatchInput(), 'user_id' => 84])->assertUnprocessable();
    \Pest\Laravel\postJson('/zero/access', [...accessWatchInput(), 'query' => 'client-supplied'])->assertUnprocessable();
    \Pest\Laravel\postJson('/zero/access', [...accessWatchInput(), 'args' => ['list' => 7, 'user_id' => 84]])->assertUnprocessable();

    $registered = array_values(resolve(RegisteredQueries::class)->all())[0];
    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        'unguarded' => new RegisteredQuery($registered->action, $registered->component, $registered->prop,
            $registered->parameters, $registered->declaration(...)),
    ]));
    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertUnprocessable();
    Auth::forgetGuards();
    \Pest\Laravel\postJson('/zero/access', [])->assertUnauthorized();
    \Pest\Laravel\getJson('/zero/access/events?watches=invalid')->assertUnauthorized();
    \Pest\Laravel\deleteJson('/zero/access/invalid')->assertUnauthorized();
    expect(DB::table('permission_access_watches')->count())->toBe(0);
});

test('access registration replays route middleware bindings and initial Gate and only saves allowed watches', function (): void {
    AccessWatchMiddleware::$allow = false;
    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertForbidden();
    AccessWatchMiddleware::$allow = true;
    \Pest\Laravel\postJson('/zero/access', accessWatchInput(999))->assertNotFound();
    Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertForbidden();
    expect(DB::table('permission_access_watches')->count())->toBe(0);
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
        expect(DB::table('permission_access_watches')->count())->toBe(0);
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
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    expect($writer->wait())->toBe(0)->and($writer->getOutput())->toContain('committed');
    expect(DB::table('permission_access_watches')->count())->toBe(1);
    expect(accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$id))[0]['state'])->toBe('denied');
});

test('SSE snapshots every connection and reconnect and follows durable revocation and regrant', function (): void {
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    $url = '/zero/access/events?watches='.$id;
    $first = accessWatchStates(\Pest\Laravel\getJson($url))[0];
    expect($first['state'])->toBe('allowed');
    $again = accessWatchStates(\Pest\Laravel\withHeader('Last-Event-ID', $first['revision'])->getJson($url));
    expect($again)->toBe([$first]);
    Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
    $revoked = accessWatchStates(\Pest\Laravel\getJson($url))[0];
    expect($revoked)->toMatchArray(['id' => $id, 'state' => 'denied', 'status' => 403, 'generation' => 'page-1'])
        ->and($revoked['revision'])->not->toBe($first['revision']);
    DB::table('permission_events')->delete();
    expect(accessWatchStates(\Pest\Laravel\getJson($url))[0]['state'])->toBe('denied');
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
    expect(accessWatchStates(\Pest\Laravel\getJson($url))[0]['state'])->toBe('allowed');
});

test('SSE uses fresh actor attributes for request Auth middleware and admin Gates and restores original auth', function (): void {
    Gate::policy(PermissionList::class, PermissionAdminPolicy::class);
    DB::table('permission_test_users')->where('id', 42)->update(['is_admin' => true]);
    $captured = PermissionUser::findOrFail(42);
    \Pest\Laravel\actingAs($captured);
    accessWatchRegistrations();
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    DB::table('permission_test_users')->where('id', 42)->update(['is_admin' => false, 'department_id' => 'support']);
    $state = accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$id))[0];
    expect($state)->toMatchArray(['state' => 'denied', 'status' => 403]);
    $observation = array_pop(AccessWatchMiddleware::$observations);
    expect($observation)->toMatchArray(['admin' => false, 'facade' => false]);
    expect(Auth::user())->toBe($captured)->and($captured->is_admin)->toBeTrue();
    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertForbidden();
});

test('concealed denial and deleted resources have identical access states except revision', function (): void {
    config(['permissions.denied_status' => 404]);
    accessWatchRegistrations();
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    DB::table('permission_test_users')->where('id', 42)->update(['department_id' => 'support']);
    $denied = accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$id))[0];
    DB::table('permission_test_lists')->where('id', 7)->delete();
    $deleted = accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$id))[0];
    unset($denied['revision'], $deleted['revision']);
    expect($denied)->toBe($deleted)->toMatchArray(['state' => 'denied', 'status' => 404]);
});

test('unknown configuration and database failures never become revoked access', function (): void {
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    config(['permissions.subject.prefix' => 'stale:']);
    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertStatus(503)->assertExactJson(['state' => 'unknown']);
    expect(DB::table('permission_access_watches')->count())->toBe(1);
    $unknown = accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$id))[0];
    expect($unknown)->toMatchArray(['state' => 'unknown']);
    expect($unknown)->not->toHaveKey('status');
    config(['permissions.subject.prefix' => 'user:']);
    $stream = \Pest\Laravel\getJson('/zero/access/events?watches='.$id)->assertOk();
    Schema::drop('permission_events');
    $unknown = accessWatchStates($stream)[0];
    expect($unknown)->toMatchArray(['state' => 'unknown']);
    expect($unknown)->not->toHaveKey('status');
});

test('watches are actor owned and foreign mixed missing and expired selections are concealed', function (): void {
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    \Pest\Laravel\actingAs(PermissionUser::findOrFail(84));
    \Pest\Laravel\getJson('/zero/access/events?watches='.$id)->assertNotFound();
    \Pest\Laravel\deleteJson('/zero/access/'.$id)->assertNotFound();
    \Pest\Laravel\actingAs(PermissionUser::findOrFail(42));
    $other = '00000000-0000-4000-8000-000000000000';
    \Pest\Laravel\getJson('/zero/access/events?watches='.$id.','.$other)->assertNotFound();
    DB::table('permission_access_watches')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
    \Pest\Laravel\getJson('/zero/access/events?watches='.$id)->assertNotFound();
    \Pest\Laravel\deleteJson('/zero/access/'.$id)->assertNotFound();
});

test('quota expiry and release bound durable watches without losing generation identity', function (): void {
    config(['permissions.access.max_watches' => 2]);
    $first = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput(generation: 'old')));
    $second = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput(generation: 'new')));
    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertStatus(429);
    $states = accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$first.','.$second));
    expect(array_column($states, 'generation'))->toContain('old', 'new');
    \Pest\Laravel\deleteJson('/zero/access/'.$first)->assertNoContent();
    \Pest\Laravel\deleteJson('/zero/access/'.$first)->assertNotFound();

    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertCreated();
    DB::table('permission_access_watches')->update(['expires_at' => now()->subSecond()]);
    \Pest\Laravel\postJson('/zero/access', accessWatchInput())->assertCreated();
    expect(DB::table('permission_access_watches')->count())->toBe(1);
});

test('reconnect renews selected active owned watches but never abandoned foreign or expired leases', function (): void {
    config(['permissions.access.ttl_seconds' => 10]);
    $start = CarbonImmutable::now()->startOfSecond();
    \Illuminate\Support\Facades\Date::setTestNow($start);
    $active = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput(generation: 'active')));
    $abandoned = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput(generation: 'abandoned')));
    \Illuminate\Support\Facades\Date::setTestNow($start->addSeconds(8));
    expect(accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$active))[0]['state'])->toBe('allowed');
    expect(DB::table('permission_access_watches')->where('id', $active)->value('expires_at'))->toBe($start->addSeconds(18)->format('Y-m-d H:i:s'));
    \Pest\Laravel\actingAs(PermissionUser::findOrFail(84));
    \Pest\Laravel\getJson('/zero/access/events?watches='.$active)->assertNotFound();
    expect(DB::table('permission_access_watches')->where('id', $active)->value('expires_at'))->toBe($start->addSeconds(18)->format('Y-m-d H:i:s'));
    \Pest\Laravel\actingAs(PermissionUser::findOrFail(42));
    \Illuminate\Support\Facades\Date::setTestNow($start->addSeconds(12));
    expect(accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$active))[0]['state'])->toBe('allowed');
    \Pest\Laravel\getJson('/zero/access/events?watches='.$abandoned)->assertNotFound();
    expect(DB::table('permission_access_watches')->where('id', $abandoned)->value('expires_at'))->toBe($start->addSeconds(10)->format('Y-m-d H:i:s'));
});

test('rolled back authorization writes do not emit a changed decision or revision', function (): void {
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    $initial = accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$id));
    try {
        DB::transaction(function (): void {
            Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
            throw new RuntimeException('Rollback authorization');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('Rollback authorization');
    }

    expect(accessWatchStates(\Pest\Laravel\getJson('/zero/access/events?watches='.$id)))->toBe($initial);
});

test('an open SSE connection wakes on committed revisions emits heartbeat and stops when released', function (): void {
    $id = accessWatchId(\Pest\Laravel\postJson('/zero/access', accessWatchInput()));
    config(['permissions.access.stream_seconds' => 3, 'permissions.access.poll_milliseconds' => 20, 'permissions.access.heartbeat_seconds' => 1]);
    $polls = 0;
    Sleep::fake();
    Sleep::whenFakingSleep(function () use (&$polls, $id): void {
        $polls++;
        if ($polls === 1) {
            Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
            time_nanosleep(1, 100000000);
        } elseif ($polls === 2) {
            Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
        } else {
            DB::table('permission_access_watches')->where('id', $id)->delete();
        }
    });
    $response = \Pest\Laravel\getJson('/zero/access/events?watches='.$id);
    $states = accessWatchStates($response);
    expect(array_column($states, 'state'))->toBe(['allowed', 'denied', 'allowed']);
    expect($response->streamedContent())->toContain(": heartbeat\n\n", 'event: access', 'id: '.$states[0]['revision']);
    expect($polls)->toBe(3);
});
