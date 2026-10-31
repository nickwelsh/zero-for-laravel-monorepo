<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Assert;
use stdClass;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRegistry;
use ZeroForLaravel\Mutation\OperationException;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

/** @return array{ModelRegistry,MutationProgram,ControllerMutationRegistry,array{version:string,input:array<string,mixed>,route:array<string,string>,prepared:array{timestamp:int,ids:array<string,string>}}} */
function mutationRegistration(mixed $test, string $method): array
{
    if (! $test instanceof TestCase) {
        throw new LogicException('Expected booted test case.');
    }

    app()->register(\Laravel\Surveyor\SurveyorServiceProvider::class);
    $models = new ModelRegistry($test->models());
    $compiler = new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), $models);
    $program = $compiler->compile(ControllerMutationController::class, $method);
    $path = tempnam(sys_get_temp_dir(), 'mutation-manifest-');
    try {
        file_put_contents($path, (new ControllerMutationGenerator)->php([$program]));
        $factory = require $path;
        if (! $factory instanceof Closure) {
            throw new LogicException('Invalid generated manifest.');
        }

        $registry = $factory($models, new ControllerRouteDispatcher);
        if (! $registry instanceof ControllerMutationRegistry) {
            throw new LogicException('Invalid generated registry.');
        }
    } finally {
        \Illuminate\Support\Facades\File::delete($path);
    }

    $wire = ['version' => $program->fingerprint, 'input' => ['title' => '  Hello  ', 'done' => true], 'route' => [],
        'prepared' => ['timestamp' => 1700000000123, 'ids' => array_combine(array_keys($program->sites), array_map(fn (): string => (string) Str::uuid(), $program->sites))]];

    return [$models, $program, $registry, $wire];
}

test('lowered controller creates once with stable identities, validation and atomic child writes', function (): void {
    Route::post('/mutation-tasks', [ControllerMutationController::class, 'store']);
    [$models, $program, $registry, $wire] = mutationRegistration($this, 'store');
    $result = $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice'])));
    expect($result)->toBe(['data' => ['id' => $wire['prepared']['ids']['s0']]]);
    expect(Task::count())->toBe(2);
    expect(Task::findOrFail($wire['prepared']['ids']['s0'])->title)->toBe('Hello');
    expect(Task::findOrFail($wire['prepared']['ids']['s1'])->title)->toBe($wire['prepared']['ids']['s0']);
    expect(Task::findOrFail($wire['prepared']['ids']['s0'])->user_id)->toBe('alice');
    expect(Task::findOrFail($wire['prepared']['ids']['s0'])->created_at?->getTimestamp())->toBe(1700000000);
    Task::creating(function (Task $task): void {
        if ($task->title !== 'Hello') {
            throw new AuthorizationException;
        }
    });
    try {
        foreach ($wire['prepared']['ids'] as &$id) {
            $id = (string) Str::uuid();
        }

        unset($id);
        expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice']))))->toThrow(AuthorizationException::class);
        expect(Task::count())->toBe(2);
    } finally {
        Task::flushEventListeners();
    }

    Task::saving(fn (): false => false);
    try {
        expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice']))))->toThrow(ValidationException::class);
        expect(Task::count())->toBe(2);
    } finally {
        Task::flushEventListeners();
    }
});

test('checked helpers preserve read your writes and actual binding and Gates', function (): void {
    Route::post('/mutation-tasks/{task}', [ControllerMutationController::class, 'complete'])->middleware(SubstituteBindings::class);
    [$models, $program, $registry, $wire] = mutationRegistration($this, 'complete');
    $task = Task::create(['title' => 'Before', 'user_id' => 'alice', 'done' => false]);
    $wire['route'] = ['task' => $task->id];
    Gate::define('controller-complete', fn ($user = null, $task = null): true => true);
    $result = $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice'])));
    expect($result)->toBe(['data' => ['before' => false, 'after' => true]]);
    expect($task->fresh()?->done)->toBeTrue();
    Gate::define('controller-complete', fn ($user = null, $task = null): false => false);
    expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice']))))->toThrow(AuthorizationException::class);
});

test('mutation contracts reject unknown code, protected metadata and distinguish void from null', function (): void {
    [$models] = mutationRegistration($this, 'noResult');
    $compiler = new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), $models);
    foreach (['unsupported', 'dependent'] as $method) {
        expect(fn (): MutationProgram => $compiler->compile(ControllerMutationController::class, $method))->toThrow(LogicException::class, 'Unsupported expression');
    }

    foreach (['authoritative' => ['data' => null], 'noResult' => []] as $method => $expected) {
        Route::post('/'.$method, [ControllerMutationController::class, $method]);
        [$models, $program, $registry, $wire] = mutationRegistration($this, $method);
        expect($models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice']))))->toBe($expected);
        $wire['input']['title'] = 'forbidden';
        expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice']))))->toThrow(AuthorizationException::class);
    }

    expect(fn () => ControllerMutationRegistry::json(new stdClass))->toThrow(OperationException::class);
    expect(fn () => ControllerMutationRegistry::json(INF))->toThrow(OperationException::class);
});

test('explicit creation sites and metadata remain separate from request input', function (): void {
    Route::post('/explicit', [ControllerMutationController::class, 'explicit']);
    [$models, $program, $registry, $wire] = mutationRegistration($this, 'explicit');
    expect(array_keys($program->sites))->toBe(['key:task']);
    $wire['input']['id'] = 'attacker';
    $wire['input']['user_id'] = 'attacker';
    $result = $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice'])));
    expect($result)->toBe(['data' => ['id' => $wire['prepared']['ids']['key:task']]]);
    expect(Task::firstOrFail()->user_id)->toBe('alice');
    $wire['prepared']['ids']['key:task'] = 'invalid';
    expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice']))))->toThrow(ValidationException::class);
});

test('controller FormRequests isolate invocation input from transport query and retain the authenticated user', function (): void {
    Route::post('/isolated', [ControllerMutationController::class, 'store']);
    [$models, $program, $registry, $wire] = mutationRegistration($this, 'store');
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    \Pest\Laravel\actingAs($user);
    $request = Request::create('/zero/mutate?title=Forged&user_id=bob&schema=zero_test_0', 'POST');
    app()->instance('request', $request);
    $seen = null;
    Task::creating(function () use (&$seen): void {
        $seen = request()->user()?->getAuthIdentifier();
    });
    try {
        $wire['input'] = ['title' => '  Actual  '];
        $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice'])));
        expect(Task::firstOrFail()->title)->toBe('Actual');
        expect(Task::firstOrFail()->user_id)->toBe('alice');
        expect($seen)->toBe('alice');
        $wire['input'] = [];
        expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice']))))
            ->toThrow(ValidationException::class);
        expect(Task::count())->toBe(1);
    } finally {
        Task::flushEventListeners();
    }
});

test('concealment makes missing binding, denied policies and validation indistinguishable', function (): void {
    Route::post('/concealed/{task}', [ControllerMutationController::class, 'concealed'])->middleware(SubstituteBindings::class);
    [$models, $program, $registry, $wire] = mutationRegistration($this, 'concealed');
    $task = Task::create(['title' => 'Private', 'user_id' => 'alice']);
    Gate::define('controller-complete', fn ($user = null, $task = null): false => false);
    foreach ([['task' => $task->id], ['task' => 'missing']] as $route) {
        $wire['route'] = $route;
        foreach ([['title' => 'OK'], []] as $input) {
            $wire['input'] = $input;
            try {
                $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'alice'])));
                Assert::fail('Expected concealed rejection.');
            } catch (OperationException $error) {
                expect($error->operationCode)->toBe('unavailable');
                expect($error->getMessage())->toBe('The requested operation is unavailable.');
            }
        }
    }

    $compiler = new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), $models);
    expect(fn (): MutationProgram => $compiler->compile(ControllerMutationController::class, 'tainted'))->toThrow(LogicException::class, 'Unsupported expression');
});

test('explicit result contracts preserve empty objects and reject invalid outcomes before commit', function (): void {
    Route::post('/structured', [ControllerMutationController::class, 'structured']);
    [$models, $program, $registry, $wire] = mutationRegistration($this, 'structured');
    $context = new ZeroContext(['user_id' => 'alice']);
    $result = $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, $context));
    expect(json_encode($result, JSON_THROW_ON_ERROR))->toBe('{"data":{"items":["saved"],"meta":{}}}');
    expect(Task::count())->toBe(1);
    $wire['input']['title'] = 'invalid';
    expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, $context)))
        ->toThrow(OperationException::class, 'declared JSON shape');
    expect(Task::count())->toBe(1);
});

test('bounded repeated creation uses item keys rather than traversal order and rejects duplicates atomically', function (): void {
    Route::post('/bulk', [ControllerMutationController::class, 'bulk']);
    [$models, $program, $registry, $wire] = mutationRegistration($this, 'bulk');
    $wire['input'] = ['items' => [
        ['key' => 'second', 'title' => 'Second', 'at' => null],
        ['key' => 'first', 'title' => 'First', 'at' => '2026-10-01T12:30:00.000Z'],
    ]];
    $context = new ZeroContext(['user_id' => 'alice']);
    $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, $context));
    $ids = Task::orderBy('title')->pluck('id')->all();
    expect(Task::where('title', 'First')->firstOrFail()->completed_at?->toISOString())->toBe('2026-10-01T12:30:00.000000Z');
    Task::query()->forceDelete();
    $wire['input']['items'] = array_reverse($wire['input']['items']);
    $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, $context));
    expect(Task::orderBy('title')->pluck('id')->all())->toBe($ids);
    $wire['input']['items'][1]['key'] = $wire['input']['items'][0]['key'];
    expect(fn () => $models->connection()->transaction(fn () => $registry->execute($program->name(), $wire, $context)))
        ->toThrow(ValidationException::class);
    expect(Task::count())->toBe(2);
});
