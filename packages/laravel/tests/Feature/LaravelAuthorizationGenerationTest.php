<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Generation\Mutations\MutationContracts;
use ZeroForLaravel\Generation\NavigationGenerator;
use ZeroForLaravel\Generation\TableCompiler;
use ZeroForLaravel\Generation\TableGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Inertia\ZeroInertiaServiceProvider;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRegistry;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\CustomAuthorizationArgumentsController;
use ZeroForLaravel\Tests\Fixtures\CustomAuthorizationController;
use ZeroForLaravel\Tests\Fixtures\DynamicRevocationPolicy;
use ZeroForLaravel\Tests\Fixtures\LaravelAuthorizationController;
use ZeroForLaravel\Tests\Fixtures\LaravelRevocationPolicy;
use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;
use ZeroForLaravel\Tests\Fixtures\OwnerUser;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\Fixtures\TasksTable;
use ZeroForLaravel\Tests\Fixtures\VisibleTask;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    /** @var TestCase $this */
    app()->register(SurveyorServiceProvider::class);
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    $this->actingAs((new User)->setKeyType('string')->forceFill(['id' => 'alice']));
});

test('ordinary Laravel authorization is captured and no-prop page shells remain valid', function (): void {
    $compiler = resolve(ControllerQueryCompiler::class);
    $query = $compiler->compile(LaravelAuthorizationController::class, 'show')[0];
    expect($query->expression->kind)->toBe('guarded')
        ->and($query->guards[0]['parameter'])->toBe('task')
        ->and($query->guards[0]['ability'])->toBe('view')
        ->and($query->php())->toContain("guardedBy(request()->user(), 'view')");
    foreach (['shell', 'emptyShell', 'boundShell', 'classShell'] as $method) {
        expect($compiler->compile(LaravelAuthorizationController::class, $method))->toBe([]);
    }
});

test('query and mutation authorization reject overridden trait methods and argument interpretation', function (): void {
    $queries = resolve(ControllerQueryCompiler::class);
    $mutations = resolve(ControllerMutationCompiler::class);
    foreach ([CustomAuthorizationController::class, CustomAuthorizationArgumentsController::class] as $class) {
        expect(fn () => $queries->compile($class, 'show'))->toThrow(LogicException::class, 'custom authorize or parseAbilityAndArguments')
            ->and(fn () => $mutations->compile($class, 'update'))->toThrow(LogicException::class, 'custom authorize or parseAbilityAndArguments');
    }

    foreach (['dynamic', 'named', 'inferred'] as $method) {
        expect(fn () => $queries->compile(LaravelAuthorizationController::class, $method))->toThrow(LogicException::class);
    }

    foreach (['dynamicMutation', 'namedMutation'] as $method) {
        expect(fn () => $mutations->compile(LaravelAuthorizationController::class, $method))->toThrow(LogicException::class);
    }
});

test('a live class guard fingerprints the class policy and requires maintainable page paths and a trusted domain', function (): void {
    Route::get('/class-index', [LaravelAuthorizationController::class, 'classIndex'])->name('tasks.index');
    Route::get('/class-shell', [LaravelAuthorizationController::class, 'shell'])->name('tasks.shell');
    Gate::policy(Task::class, LaravelRevocationPolicy::class);
    $compiler = resolve(ControllerQueryCompiler::class);
    expect(fn () => $compiler->compile(LaravelAuthorizationController::class, 'classIndex'))->toThrow(LogicException::class, 'domain.context');
    config(['permissions.resources.'.Task::class.'.domain.context' => 'user_id']);
    $queries = $compiler->compile(LaravelAuthorizationController::class, 'classIndex');
    $guard = $queries[0]->guards[0];
    expect($guard['parameter'])->toBeNull()
        ->and($guard['fingerprint'])->toBe((new \ZeroForLaravel\Permissions\PolicyCompiler)->compileClass(Task::class, 'viewAny')->fingerprint)
        ->and((new NavigationGenerator)->typescript($queries))->toContain('"scope":"page","revoked":{"route":"tasks.shell"}');
    expect(fn () => $compiler->compile(LaravelAuthorizationController::class, 'conditionalClassIndex'))->toThrow(LogicException::class, 'unconditional')
        ->and(fn () => $compiler->compile(LaravelAuthorizationController::class, 'classPivotIndex'))->toThrow(LogicException::class, 'intermediate tables')
        ->and(fn () => $compiler->compile(LaravelAuthorizationController::class, 'classStandalone'))->toThrow(LogicException::class, 'Inertia page action');

    // Public roots have no maintained permission projection to anchor a class guard.
    app()->instance(ModelRegistry::class, new ModelRegistry([VisibleTask::class]));
    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(LaravelAuthorizationController::class, 'classPublicIndex'))->toThrow(LogicException::class, 'portable permission resource');
});

test('generated navigation discovers literal page shells but does not speculate required ordinary props', function (): void {
    Route::get('/shell', [LaravelAuthorizationController::class, 'shell'])->name('shell');
    Route::get('/empty-shell', [LaravelAuthorizationController::class, 'emptyShell'])->name('empty');
    Route::get('/ordinary-shell', [LaravelAuthorizationController::class, 'ordinaryShell'])->name('ordinary');
    Route::get('/bound-shell/{task}', [LaravelAuthorizationController::class, 'boundShell'])->name('bound');
    $source = (new NavigationGenerator)->typescript([]);
    $routes = json_decode(substr($source, strlen('export const navigation = '), -strlen(" as const;\n")), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($routes)) {
        throw new LogicException('Expected generated routes.');
    }

    $named = array_column($routes, null, 'name');
    expect(data_get($named, 'shell.component'))->toBe('tasks/shell')
        ->and(data_get($named, 'shell.client'))->toBeTrue()
        ->and(data_get($named, 'empty.client'))->toBeTrue()
        ->and(data_get($named, 'ordinary.component'))->toBe('tasks/shell')
        ->and(data_get($named, 'ordinary.client'))->toBeFalse()
        ->and(data_get($named, 'bound.client'))->toBeFalse()
        ->and(data_get($named, 'shell.uri'))->toBe('shell')
        ->and(data_get($named, 'shell.domain'))->toBeNull();
});

test('mutation authorizations execute on the server and coalescing preserves false and absent input', function (): void {
    $models = resolve(ModelRegistry::class);
    $compiler = resolve(ControllerMutationCompiler::class);
    $create = $compiler->compile(LaravelAuthorizationController::class, 'store');
    $update = $compiler->compile(LaravelAuthorizationController::class, 'update');
    $generator = new ControllerMutationGenerator;
    $php = $generator->php([$create, $update]);
    expect($php)->not->toMatch('/[\t ]+$/m')
        ->and($php)->not->toContain('$this->authorize')
        ->and($create->guards[0])->toContain("Gate::authorize('controller-create'")
        ->and((new MutationContracts)->server($create)->typescript())->toContain('"done": boolean', '"title": string');
    $factory = eval(substr($php, 5));
    if (! $factory instanceof Closure) {
        throw new LogicException('Expected generated registration factory.');
    }

    $registry = $factory($models, new ControllerRouteDispatcher);
    if (! $registry instanceof ControllerMutationRegistry) {
        throw new LogicException('Expected generated mutation registry.');
    }

    Route::post('/authorized-create', [LaravelAuthorizationController::class, 'store']);
    Route::post('/authorized-update/{task}', [LaravelAuthorizationController::class, 'update'])->middleware(SubstituteBindings::class);
    Gate::define('controller-create', fn (): bool => true);
    Gate::define('controller-update', fn (): bool => true);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    foreach ([[], ['done' => false], ['done' => true]] as $input) {
        $wire = ['version' => $create->fingerprint, 'input' => ['title' => 'Saved', ...$input], 'route' => [],
            'prepared' => ['timestamp' => 1700000000123, 'ids' => ['s0' => (string) Str::uuid()]]];
        $result = $models->connection()->transaction(fn (): array => $registry->execute($create->name(), $wire, $context));
        expect($result)->toBe(['data' => ['done' => $input['done'] ?? false, 'title' => 'Saved']]);
    }

    Gate::define('controller-create', fn (): bool => false);
    expect(fn () => $models->connection()->transaction(fn (): array => $registry->execute($create->name(), $wire, $context)))->toThrow(AuthorizationException::class);
    expect(Task::count())->toBe(3);

    $task = Task::firstOrFail();
    $wire = ['version' => $update->fingerprint, 'input' => ['title' => 'Updated'], 'route' => ['task' => $task->id],
        'prepared' => ['timestamp' => 1700000000123, 'ids' => []]];
    $models->connection()->transaction(fn (): array => $registry->execute($update->name(), $wire, $context));
    expect($task->fresh()?->title)->toBe('Updated');
    Gate::define('controller-update', fn (): bool => false);
    expect(fn () => $models->connection()->transaction(fn (): array => $registry->execute($update->name(), $wire, $context)))->toThrow(AuthorizationException::class);
});

test('ordinary null model reads reject writes while coalescing and isset remain null tolerant', function (): void {
    $compiler = resolve(ControllerMutationCompiler::class);
    $required = $compiler->compile(LaravelAuthorizationController::class, 'requiredPrerequisite');
    $optional = $compiler->compile(LaravelAuthorizationController::class, 'optionalPrerequisite');
    $factory = eval(substr((new ControllerMutationGenerator)->php([$required, $optional]), 5));
    if (! $factory instanceof Closure) {
        throw new LogicException('Expected generated registration factory.');
    }

    $models = resolve(ModelRegistry::class);
    $registry = $factory($models, new ControllerRouteDispatcher);
    if (! $registry instanceof ControllerMutationRegistry) {
        throw new LogicException('Expected generated mutation registry.');
    }

    Route::post('/required-prerequisite', [LaravelAuthorizationController::class, 'requiredPrerequisite']);
    Route::post('/optional-prerequisite', [LaravelAuthorizationController::class, 'optionalPrerequisite']);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $wire = ['version' => $optional->fingerprint, 'input' => [], 'route' => [],
        'prepared' => ['timestamp' => 1700000000123, 'ids' => []]];
    expect($models->connection()->transaction(fn (): array => $registry->execute($optional->name(), $wire, $context)))
        ->toBe(['data' => ['label' => 'Default label', 'present' => false]]);

    $wire['version'] = $required->fingerprint;
    $wire['prepared']['ids'] = ['s0' => (string) Str::uuid()];
    expect(fn (): array => $models->connection()->transaction(fn (): array => $registry->execute($required->name(), $wire, $context)))
        ->toThrow(LogicException::class, 'Invalid compiled property access.');
    expect(Task::count())->toBe(0);

    $prerequisite = new TaskList;
    $prerequisite->forceFill(['id' => '00000000-0000-4000-8000-000000000001', 'user_id' => 'alice', 'name' => 'allowed'])->save();
    $models->connection()->transaction(fn (): array => $registry->execute($required->name(), $wire, $context));
    expect(Task::pluck('title')->all())->toBe(['Created with prerequisite']);
    $prerequisite->forceFill(['name' => 'blocked'])->save();
    $wire['prepared']['ids'] = ['s0' => (string) Str::uuid()];
    $models->connection()->transaction(fn (): array => $registry->execute($required->name(), $wire, $context));
    expect(Task::count())->toBe(1);
});

test('query generation omits unused context and date imports and trailing whitespace', function (): void {
    $query = resolve(ControllerQueryCompiler::class)->compile(LaravelAuthorizationController::class, 'show')[0];
    $generator = new ControllerQueryGenerator(new ZodSchemaDriver);
    expect($query->typescript())->toStartWith('({args}) => ')
        ->and($generator->typescript([$query], './schema'))->not->toContain('encodeDate')
        ->and($generator->php([$query]))->not->toMatch('/[\t ]+$/m');
    $literal = resolve(ControllerQueryCompiler::class)->compile(LaravelAuthorizationController::class, 'literalText')[0];
    expect($literal->typescript())->toStartWith('() => ')
        ->and(explode("\n", $generator->typescript([$literal], './schema'))[2])->not->toContain('encodeDate');
    expect($generator->typescript([], './schema'))->not->toContain('createBuilder', 'Context', 'definitions')
        ->and((new ControllerMutationGenerator)->typescript([]))->not->toContain("from 'zod'");
    config(['zero.route_prefix' => 'zero']);
    $table = (new TableCompiler(resolve(ModelRegistry::class)))->compile('tasks', TasksTable::class);
    $source = (new TableGenerator)->typescript([$table], './schema')['tables/tasks.ts'];
    expect($source)->not->toContain('encodeDate', '({args, ctx}:')
        ->and($source)->toContain('({args}:')
        ->and($source)->not->toMatch('/[\t ]+$/m');
});

test('coalescing result contracts remove nullability without coercing false empty strings or zero', function (): void {
    $program = resolve(ControllerMutationCompiler::class)->compile(LaravelAuthorizationController::class, 'defaults');
    $contract = (new MutationContracts)->server($program);
    expect($contract->fields['note']->typescript())->toBe('string')
        ->and($contract->fields['flag']->typescript())->toBe('boolean')
        ->and($contract->fields['count']->typescript())->toBe('number')
        ->and($contract->fields['label']->typescript())->toBe('string');
    $factory = eval(substr((new ControllerMutationGenerator)->php([$program]), 5));
    if (! $factory instanceof Closure) {
        throw new LogicException('Expected generated registration factory.');
    }

    $models = resolve(ModelRegistry::class);
    $registry = $factory($models, new ControllerRouteDispatcher);
    if (! $registry instanceof ControllerMutationRegistry) {
        throw new LogicException('Expected generated mutation registry.');
    }

    Route::post('/coalescing-defaults', [LaravelAuthorizationController::class, 'defaults']);
    foreach ([
        [[], 'Default label'],
        [['note' => null, 'flag' => null, 'count' => null, 'settings' => null], 'Default label'],
        [['note' => '0', 'flag' => false, 'count' => 0, 'settings' => []], 'Default label'],
        [['note' => '', 'flag' => false, 'count' => 0, 'settings' => ['label' => '']], ''],
        [['settings' => ['label' => null]], 'Default label'],
        [['settings' => ['label' => 'Custom']], 'Custom'],
    ] as [$input, $label]) {
        $wire = ['version' => $program->fingerprint, 'input' => $input, 'route' => [],
            'prepared' => ['timestamp' => 1700000000123, 'ids' => []]];
        $result = $models->connection()->transaction(fn (): array => $registry->execute($program->name(), $wire, new ResolvedContext(['user_id' => 'alice'], 'alice')));
        expect($result)->toBe(['data' => ['note' => $input['note'] ?? 'Default', 'flag' => $input['flag'] ?? true, 'count' => $input['count'] ?? 7, 'label' => $label]]);
    }
});

test('revocation metadata agrees across guarded server props and navigation without redirecting collections', function (): void {
    /** @var TestCase $this */
    OwnerPermissions::install();
    config(['zero.inertia' => true]);
    app()->register(ZeroInertiaServiceProvider::class);
    $this->actingAs(OwnerUser::findOrFail('alice'));
    Gate::policy(Task::class, LaravelRevocationPolicy::class);
    Route::get('/revocation-shell', [LaravelAuthorizationController::class, 'shell'])->name('tasks.shell');
    Route::get('/revocation/{task}', [LaravelAuthorizationController::class, 'guardedCollection'])->middleware(SubstituteBindings::class);
    // Name lookups are normally refreshed by Laravel after route registration.
    Route::getRoutes()->refreshNameLookups();
    $queries = resolve(ControllerQueryCompiler::class)->compile(LaravelAuthorizationController::class, 'guardedCollection');
    $registrations = eval(substr((new ControllerQueryGenerator(new ZodSchemaDriver))->php($queries), 5));
    if (! is_array($registrations)) {
        throw new LogicException('Expected generated registrations.');
    }

    foreach ($registrations as $name => $registration) {
        if (! is_string($name) || ! $registration instanceof RegisteredQuery) {
            throw new LogicException('Expected a registered query.');
        }
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries($registrations));
    $task = Task::create(['title' => 'Private', 'user_id' => 'alice']);
    $this->get('/revocation/'.$task->id, ['X-Inertia' => 'true'])->assertOk()
        ->assertJsonPath('props.__zero.bindings.task.access.status', 404)
        ->assertJsonPath('props.__zero.bindings.task.access.revoked.route', 'tasks.shell')
        ->assertJsonPath('props.__zero.bindings.task.access.revoked.message', 'Access was revoked.')
        ->assertJsonMissingPath('props.__zero.bindings.tasks.access');
    $source = (new NavigationGenerator)->typescript($queries);
    $routes = json_decode(substr($source, strlen('export const navigation = '), -strlen(" as const;\n")), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($routes)) {
        throw new LogicException('Expected generated routes.');
    }

    $page = array_values(array_filter($routes, fn (mixed $route): bool => data_get($route, 'component') === 'tasks/show'))[0];
    expect(data_get($page, 'bindings.task.access'))->toBe(['status' => 404, 'revoked' => ['route' => 'tasks.shell', 'message' => 'Access was revoked.']])
        ->and(data_get($page, 'bindings.tasks'))->not->toHaveKey('access');
    $this->actingAs(OwnerUser::findOrFail('bob'));
    $this->get('/revocation/'.$task->id, ['X-Inertia' => 'true'])->assertNotFound();
});

test('revocation generation rejects missing or non-GET destinations and dynamic metadata', function (): void {
    Gate::policy(Task::class, LaravelRevocationPolicy::class);
    $compiler = resolve(ControllerQueryCompiler::class);
    expect(fn () => $compiler->compile(LaravelAuthorizationController::class, 'show'))->toThrow(LogicException::class, 'registered GET route');
    Route::post('/post-only', [LaravelAuthorizationController::class, 'shell'])->name('tasks.shell');
    Route::getRoutes()->refreshNameLookups();
    expect(fn () => $compiler->compile(LaravelAuthorizationController::class, 'show'))->toThrow(LogicException::class, 'registered GET route');
    Gate::policy(Task::class, DynamicRevocationPolicy::class);
    expect(fn () => $compiler->compile(LaravelAuthorizationController::class, 'show'))->toThrow(LogicException::class, 'string literals');
});
