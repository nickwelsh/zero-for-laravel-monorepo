<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use ZeroForLaravel\Attributes\ResolvesContext;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\AuthorizationResponse;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionChecks;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\PolicyCompiler;
use ZeroForLaravel\Permissions\Revocation;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\PermissionList;
use ZeroForLaravel\Tests\Fixtures\PermissionListPolicy;
use ZeroForLaravel\Tests\Fixtures\PermissionUser;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

enum PermissionDomainContext: string
{
    case ACTOR = 'actor';
    case DOMAIN = 'domain';
}

final class PermissionDomainResolver
{
    public static ?string $domain = '7';

    #[ResolvesContext(PermissionDomainContext::ACTOR)]
    public function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    #[ResolvesContext(PermissionDomainContext::DOMAIN)]
    public function domain(): ?string
    {
        return self::$domain;
    }
}

final class PermissionActorDomainResolver
{
    public static bool $throws = false;

    /** @var list<array{request: ?string, auth: ?string, injected: ?string, shared: bool}> */
    public static array $observations = [];

    #[ResolvesContext(PermissionDomainContext::ACTOR)]
    public function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    #[ResolvesContext(PermissionDomainContext::DOMAIN)]
    public function domain(Request $request, Authenticatable $injected): ?string
    {
        $actor = $request->user();
        $auth = Auth::user();
        $domain = $actor instanceof PermissionUser ? $actor->getRawOriginal('department_id') : null;
        $authDomain = $auth instanceof PermissionUser ? $auth->getRawOriginal('department_id') : null;
        $injectedDomain = $injected instanceof PermissionUser ? $injected->getRawOriginal('department_id') : null;
        self::$observations[] = ['request' => is_string($domain) ? $domain : null,
            'auth' => is_string($authDomain) ? $authDomain : null, 'injected' => is_string($injectedDomain) ? $injectedDomain : null,
            'shared' => $auth === $actor && $injected === $actor];
        if (self::$throws) {
            throw new LogicException('Domain resolution failed.');
        }

        return is_string($injectedDomain) ? $injectedDomain : null;
    }
}

class PermissionClassPolicy extends PermissionListPolicy
{
    public function create(PermissionUser $user): Response
    {
        return Casbin::inspect($user, 'create', PermissionList::class)->asNotAuthorized();
    }

    public function view(PermissionUser $user, PermissionList $list): Response
    {
        return Casbin::inspect($user, 'view', $list)->asNotFound()->onRevoked(
            Revocation::redirectToRoute('permission.index')->message('Access was revoked.')
        );
    }
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
        'zero.context' => PermissionDomainContext::class,
        'zero.context_resolver' => PermissionDomainResolver::class,
        'permissions.subject.class' => PermissionUser::class,
        'permissions.subject.attributes' => ['department_id' => ['column' => 'department_id', 'type' => 'string']],
        'permissions.resources' => [PermissionList::class => [
            'type' => 'todo-list', 'domain' => ['column' => 'id', 'prefix' => 'list:', 'context' => 'domain'],
            'attributes' => ['owner_id' => ['column' => 'owner_id', 'type' => 'id'], 'department_id' => ['column' => 'department_id', 'type' => 'string']],
        ]],
    ]);
    PermissionDomainResolver::$domain = '7';
    PermissionActorDomainResolver::$throws = false;
    PermissionActorDomainResolver::$observations = [];
    Gate::policy(PermissionList::class, PermissionClassPolicy::class);
    Route::get('/permission-index', fn (): string => 'index')->name('permission.index');
});

test('class Gates use explicit trusted domains without any resource rows and do not recursively invoke policies', function (): void {
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    request()->setUserResolver(fn () => $actor);
    Casbin::addPolicy('role:creator', 'list:7', 'todo-list', 'create', 'any', 'allow');
    Casbin::addGroupingPolicy('user:42', 'role:creator', 'list:7');
    Casbin::addGroupingPolicy('user:42', 'role:creator', 'list:8');
    expect(PermissionList::count())->toBe(0)
        ->and(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue();
    PermissionDomainResolver::$domain = '8';
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeFalse();
    PermissionDomainResolver::$domain = '7';
    Casbin::removeGroupingPolicy('user:42', 'role:creator', 'list:7');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeFalse();
    Casbin::addGroupingPolicy('user:42', 'role:creator', 'list:7');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue();
});

test('class inspection rejects missing domain bindings null domains and mismatched context actors', function (): void {
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $other = PermissionUser::create(['id' => 84, 'department_id' => 'engineering']);
    request()->setUserResolver(fn () => $other);
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'create', 'any', 'allow');
    expect(Casbin::inspect($actor, 'create', PermissionList::class)->denied())->toBeTrue();
    request()->setUserResolver(fn (): PermissionUser => new PermissionUser);
    expect(Casbin::inspect($actor, 'create', PermissionList::class)->denied())->toBeTrue();
    request()->setUserResolver(fn (): null => null);
    expect(Casbin::inspect($actor, 'create', PermissionList::class)->denied())->toBeTrue();
    request()->setUserResolver(fn () => $actor);
    PermissionDomainResolver::$domain = null;
    expect(Casbin::inspect($actor, 'create', PermissionList::class)->denied())->toBeTrue();
    PermissionDomainResolver::$domain = '7';
    config(['permissions.resources.'.PermissionList::class.'.domain.context' => null]);
    expect(fn () => Casbin::inspect($actor, 'create', PermissionList::class))->toThrow(LogicException::class, 'explicit');
});

test('class decisions fail closed for instance attributes and conservatively preserve potential explicit denies', function (): void {
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    request()->setUserResolver(fn () => $actor);
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'create', 'owner', 'allow');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeFalse();
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'create', 'any', 'allow');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue();
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'create', 'department', 'deny');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeFalse();
    Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'create', 'department', 'deny');
    Casbin::addPolicy('user:42', 'list:8', 'todo-list', 'create', 'any', 'deny');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue();
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'create', 'any', 'deny');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeFalse();
});

test('class subject attributes are refreshed and explicit server contexts remain actor bound', function (): void {
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $context = new ResolvedContext(['actor' => '42', 'domain' => '7'], '42');
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'create', 'any', 'allow');
    expect(Casbin::inspect($actor, 'create', PermissionList::class, $context)->allowed())->toBeTrue();
    $foreign = new ResolvedContext(['actor' => '84', 'domain' => '7'], '84');
    expect(Casbin::inspect($actor, 'create', PermissionList::class, $foreign)->denied())->toBeTrue();
    $actor->delete();
    expect(Casbin::inspect($actor, 'create', PermissionList::class, $context)->denied())->toBeTrue();
});

test('implicit class domains use the persisted actor in Request Auth and injection and restore the caller', function (bool $customResolver): void {
    config(['zero.context_resolver' => PermissionActorDomainResolver::class]);
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    request()->setUserResolver(fn () => $actor);
    Auth::guard()->setUser($actor);
    if ($customResolver) {
        Auth::resolveUsersUsing(fn () => $actor);
    }

    $requestResolver = request()->getUserResolver();
    $authResolver = Auth::userResolver();
    Casbin::addPolicy('user:42', 'list:engineering', 'todo-list', 'create', 'any', 'allow');
    PermissionUser::whereKey(42)->update(['department_id' => 'support']);

    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeFalse();
    expect(Casbin::inspect($actor, 'create', PermissionList::class,
        new ResolvedContext(['actor' => '42', 'domain' => 'engineering'], '42'))->allowed())->toBeTrue();
    Casbin::addPolicy('user:42', 'list:support', 'todo-list', 'create', 'any', 'allow');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue();

    foreach (PermissionActorDomainResolver::$observations as $observation) {
        expect($observation)->toBe(['request' => 'support', 'auth' => 'support', 'injected' => 'support', 'shared' => true]);
    }

    expect(request()->getUserResolver())->toBe($requestResolver)
        ->and(Auth::userResolver())->toBe($authResolver)
        ->and(resolve(Authenticatable::class))->toBe($actor)
        ->and(request()->user())->toBe($actor)
        ->and(Auth::user())->toBe($actor);
})->with([false, true]);

test('implicit class actor scopes restore Request and Auth after resolver exceptions', function (bool $authenticated): void {
    config(['zero.context_resolver' => PermissionActorDomainResolver::class]);
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    request()->setUserResolver(fn () => $actor);
    if ($authenticated) {
        Auth::guard()->setUser($actor);
    }

    Auth::resolveUsersUsing(fn () => $actor);
    $requestResolver = request()->getUserResolver();
    $authResolver = Auth::userResolver();
    $driver = Auth::getDefaultDriver();
    PermissionUser::whereKey(42)->update(['department_id' => 'support']);
    PermissionActorDomainResolver::$throws = true;

    expect(fn () => Casbin::inspect($actor, 'create', PermissionList::class))->toThrow(LogicException::class, 'Domain resolution failed.');
    expect(request()->getUserResolver())->toBe($requestResolver)
        ->and(Auth::userResolver())->toBe($authResolver)
        ->and(Auth::getDefaultDriver())->toBe($driver)
        ->and(resolve(Authenticatable::class))->toBe($actor)
        ->and(request()->user())->toBe($actor)
        ->and(Auth::user())->toBe($authenticated ? $actor : null);
    expect(PermissionActorDomainResolver::$observations)->toBe([['request' => 'support', 'auth' => 'support', 'injected' => 'support', 'shared' => true]]);
})->with([true, false]);

test('implicit class scopes restore identity when SessionGuard listeners switch guards and throw', function (bool $restoring, bool $authorization): void {
    config(['zero.context_resolver' => PermissionActorDomainResolver::class]);
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $other = PermissionUser::create(['id' => 84, 'department_id' => 'other']);
    $driver = Auth::getDefaultDriver();
    config(['auth.guards.secondary' => config('auth.guards.'.$driver)]);
    Auth::guard()->setUser($actor);
    Auth::guard('secondary')->setUser($other);
    request()->setUserResolver(fn (?string $name = null) => $name === 'secondary' ? $other : $actor);
    Auth::resolveUsersUsing(fn (?string $name = null) => $name === 'secondary' ? $other : $actor);
    $requestResolver = request()->getUserResolver();
    $authResolver = Auth::userResolver();
    PermissionUser::whereKey(42)->update(['department_id' => 'support']);
    Casbin::addPolicy('user:42', 'list:support', 'todo-list', 'create', 'any', 'allow');
    $exception = $authorization ? new AuthorizationException('Authentication listener failed.') : new LogicException('Authentication listener failed.');
    $observations = [];
    Event::listen(Authenticated::class, function (Authenticated $event) use ($actor, $restoring, $exception, &$observations): void {
        $original = $event->user === $actor;
        $observations[] = ['original' => $original, 'shared' => $event->user === request()->user()
            && $event->user === Auth::user() && $event->user === resolve(Authenticatable::class), 'driver' => Auth::getDefaultDriver()];
        if ($original === $restoring) {
            Auth::shouldUse('secondary');

            throw $exception;
        }
    });

    expect(Auth::guard())->toBeInstanceOf(SessionGuard::class);
    if ($authorization) {
        expect(Casbin::inspect($actor, 'create', PermissionList::class)->denied())->toBeTrue();
    } else {
        expect(fn () => Casbin::inspect($actor, 'create', PermissionList::class))->toThrow(LogicException::class, 'Authentication listener failed.');
    }

    expect(request()->getUserResolver())->toBe($requestResolver)
        ->and(Auth::userResolver())->toBe($authResolver)
        ->and(Auth::getDefaultDriver())->toBe($driver)
        ->and(request()->user())->toBe($actor)
        ->and(Auth::user())->toBe($actor)
        ->and(resolve(Authenticatable::class))->toBe($actor)
        ->and(request()->user('secondary'))->toBe($other)
        ->and(Auth::guard('secondary')->user())->toBe($other);
    expect($observations)->toBe([
        ['original' => false, 'shared' => true, 'driver' => $driver],
        ['original' => true, 'shared' => true, 'driver' => $driver],
    ]);
})->with(['setup' => false, 'restoration' => true])->with(['authorization rejection' => true, 'ordinary exception' => false]);

test('nested implicit class inspection preserves named guards and the enclosing actor', function (): void {
    config(['zero.context_resolver' => PermissionActorDomainResolver::class]);
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $other = PermissionUser::create(['id' => 84, 'department_id' => 'other']);
    $driver = Auth::getDefaultDriver();
    config(['auth.guards.secondary' => config('auth.guards.'.$driver)]);
    Auth::guard()->setUser($actor);
    Auth::guard('secondary')->setUser($other);
    request()->setUserResolver(fn (?string $name = null) => $name === 'secondary' ? $other : $actor);
    Auth::resolveUsersUsing(fn (?string $name = null) => $name === 'secondary' ? $other : $actor);
    $requestResolver = request()->getUserResolver();
    $authResolver = Auth::userResolver();
    PermissionUser::whereKey(42)->update(['department_id' => 'support']);
    Casbin::addPolicy('user:42', 'list:support', 'todo-list', 'create', 'any', 'allow');
    $nested = false;
    $observations = [];
    Event::listen(Authenticated::class, function (Authenticated $event) use ($actor, $other, &$nested, &$observations): void {
        if ($nested || $event->user === $actor) {
            return;
        }

        $nested = true;
        $observations[] = Gate::forUser($event->user)->allows('create', PermissionList::class);
        $observations[] = $event->user === request()->user() && $event->user === Auth::user()
            && $event->user === resolve(Authenticatable::class);
        $observations[] = request()->user('secondary') === $other && (Auth::userResolver())('secondary') === $other;
    });

    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue()
        ->and($observations)->toBe([true, true, true])
        ->and(request()->getUserResolver())->toBe($requestResolver)
        ->and(Auth::userResolver())->toBe($authResolver)
        ->and(Auth::getDefaultDriver())->toBe($driver)
        ->and(request()->user())->toBe($actor)
        ->and(Auth::user())->toBe($actor)
        ->and(resolve(Authenticatable::class))->toBe($actor);
});

test('class policy compilation captures the literal resource and rejects instance or foreign class arguments', function (): void {
    $compiler = new PolicyCompiler;
    $plan = $compiler->compileClass(PermissionList::class, 'create');
    expect($plan->classResource)->toBeTrue()->and($plan->action)->toBe('create')->and($plan->deniedStatus)->toBe(403);
    expect(fn (): \ZeroForLaravel\Permissions\PolicyPlan => $compiler->compile(PermissionList::class, 'create'))->toThrow(LogicException::class, 'direct Casbin');
    $policy = new class extends PermissionClassPolicy
    {
        public function create(PermissionUser $user): Response
        {
            return Casbin::inspect($user, 'create', PermissionUser::class);
        }
    };
    Gate::policy(PermissionList::class, $policy::class);
    expect(fn (): \ZeroForLaravel\Permissions\PolicyPlan => $compiler->compileClass(PermissionList::class, 'create'))->toThrow(LogicException::class, 'not portable');
});

test('revocation declarations are inert immutable metadata and preserve initial allow and denial semantics', function (): void {
    $declaration = Revocation::redirectToRoute('not-registered');
    $withMessage = $declaration->message('Gone');
    expect($declaration->message)->toBeNull()->and($withMessage->message)->toBe('Gone');
    $allowed = (new AuthorizationResponse(true))->onRevoked($withMessage);
    $denied = (new AuthorizationResponse(false))->asNotFound()->onRevoked($withMessage);
    expect($allowed->allowed())->toBeTrue()->and($allowed->status())->toBeNull()
        ->and($denied->denied())->toBeTrue()->and($denied->status())->toBe(404)
        ->and($denied->revocation())->toBe($withMessage);
    $plan = (new PolicyCompiler)->compile(PermissionList::class);
    expect($plan->deniedStatus)->toBe(404)->and($plan->revocation?->toArray())->toBe([
        'route' => 'permission.index', 'message' => 'Access was revoked.',
    ]);
});

test('revocation compilation rejects dynamic resource dependent declarations and non GET routes', function (): void {
    $policy = new class extends PermissionClassPolicy
    {
        public function view(PermissionUser $user, PermissionList $list): Response
        {
            return Casbin::inspect($user, 'view', $list)->onRevoked(Revocation::redirectToRoute('permission.index')->message($user->getTable()));
        }
    };
    Gate::policy(PermissionList::class, $policy::class);
    expect(fn (): \ZeroForLaravel\Permissions\PolicyPlan => (new PolicyCompiler)->compile(PermissionList::class))->toThrow(LogicException::class, 'string literals');
    Gate::policy(PermissionList::class, PermissionClassPolicy::class);
    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('permission.index');
    if ($route === null) {
        throw new LogicException('Expected the fixture route.');
    }

    $route->methods = ['POST'];
    expect(fn (): \ZeroForLaravel\Permissions\PolicyPlan => (new PolicyCompiler)->compile(PermissionList::class))->toThrow(LogicException::class, 'registered GET');
});

test('permission payloads distinguish omitted IDs from string identities and refuse null or protected context', function (): void {
    $checks = new PermissionChecks(new ModelRegistry([PermissionList::class]));
    config(['permissions.access.abilities' => [PermissionList::class => ['create', 'update']]]);
    expect($checks->parse(['model' => 'PermissionList', 'ability' => 'create']))->toBe(['class' => PermissionList::class, 'ability' => 'create']);
    expect($checks->parse(['model' => 'PermissionList', 'ability' => 'update', 'id' => '7']))->toBe(['class' => PermissionList::class, 'ability' => 'update', 'id' => '7']);
    foreach ([['id' => null], ['id' => 7], ['id' => ''], ['id' => []], ['domain' => '7'], ['user_id' => '42']] as $extra) {
        expect(fn (): array => $checks->parse(['model' => 'PermissionList', 'ability' => 'update', ...$extra]))->toThrow(ValidationException::class);
    }

    expect(fn (): array => $checks->parse(['model' => 'PermissionList', 'ability' => 'delete']))->toThrow(ValidationException::class);
    expect(fn (): array => $checks->parse(['model' => PermissionList::class, 'ability' => 'create']))->toThrow(ValidationException::class);
});

test('class checks reuse every published portable permission family', function (string $profile, string $grant, string $role): void {
    config(['permissions.models.default' => dirname(__DIR__, 2).'/config/permissions/'.$profile.'.conf']);
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    request()->setUserResolver(fn () => $actor);
    Casbin::addPolicy(...explode(', ', $grant));
    if ($role !== '') {
        Casbin::addGroupingPolicy(...explode(', ', $role));
    }

    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue();
    expect(PermissionList::count())->toBe(0);
})->with([
    'ACL' => ['global-acl', 'user:42, todo-list, create, any, allow', ''],
    'RBAC' => ['global-rbac', 'role:creator, todo-list, create, any, allow', 'user:42, role:creator'],
    'domain ACL' => ['domain-acl', 'user:42, list:7, todo-list, create, any, allow', ''],
    'domain RBAC' => ['scoped-rbac-abac', 'role:creator, list:7, todo-list, create, any, allow', 'user:42, role:creator, list:7'],
    'role-free ABAC' => ['abac', 'list:7, todo-list, create, any, allow', ''],
]);

test('global class resources require neither a domain context binding nor a configured Zero resolver', function (): void {
    config([
        'permissions.models.default' => dirname(__DIR__, 2).'/config/permissions/global-acl.conf',
        'permissions.resources.'.PermissionList::class.'.domain' => [],
        'zero.context' => null,
        'zero.context_resolver' => null,
    ]);
    $actor = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    Casbin::addPolicy('user:42', 'todo-list', 'create', 'any', 'allow');
    expect(Gate::forUser($actor)->allows('create', PermissionList::class))->toBeTrue();
});

test('revocation compilation never evaluates callback expressions to discover their metadata', function (): void {
    $policy = new class extends PermissionClassPolicy
    {
        public function view(PermissionUser $user, PermissionList $list): Response
        {
            return Casbin::inspect($user, 'view', $list)->onRevoked((fn (): Revocation => throw new LogicException('Must not execute'))());
        }
    };
    Gate::policy(PermissionList::class, $policy::class);
    expect(fn (): \ZeroForLaravel\Permissions\PolicyPlan => (new PolicyCompiler)->compile(PermissionList::class))->toThrow(LogicException::class, 'not callbacks or dynamic expressions');
});
