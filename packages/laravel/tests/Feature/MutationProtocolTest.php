<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use PhpParser\ParserFactory;
use stdClass;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRegistry;
use ZeroForLaravel\Mutation\MutationProcessor;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\RedirectMutationController;
use ZeroForLaravel\Tests\Fixtures\RedirectMutationRoutes;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\OwnerPostgresTestCase as PostgresTestCase;

uses(PostgresTestCase::class);

beforeEach(function (): void {
    /** @var PostgresTestCase $this */
    $this->actingAs((new User)->setKeyType('string')->forceFill(['id' => 'alice']));
    app()->register(SurveyorServiceProvider::class);
    $models = new ModelRegistry($this->models());
    app()->instance(ModelRegistry::class, $models);
    $programs = [];
    foreach (['store', 'structured', 'authoritative', 'noResult'] as $method) {
        Route::post('/protocol/'.$method, [ControllerMutationController::class, $method]);
        $programs[] = mutationProtocolProgram($method);
    }

    RedirectMutationRoutes::register();
    $programs[] = mutationProtocolProgram('confirmed', RedirectMutationController::class);

    $file = tempnam(sys_get_temp_dir(), 'protocol-mutations-');
    try {
        file_put_contents($file, (new ControllerMutationGenerator)->php($programs));
        $factory = require $file;
        if (! $factory instanceof Closure) {
            throw new LogicException('Expected generated controller registrations.');
        }

        app()->instance(ControllerMutationRegistry::class, $factory($models, new ControllerRouteDispatcher));
    } finally {
        File::delete($file);
    }
});

afterEach(function (): void {
    Task::flushEventListeners();
});

function mutationProtocolProgram(string $method, string $controller = ControllerMutationController::class): MutationProgram
{
    return (new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), resolve(ModelRegistry::class)))
        ->compile($controller, $method);
}

/** @param list<array<string, mixed>> $mutations
 * @param  array<string, mixed>|null  $params
 * @return array<string, mixed>
 */
function mutationProtocolProcess(array $mutations, ?array $params = null, int $version = 1, string $identity = 'alice', string $group = 'group-a', string $app = 'test', string $schema = 'zero_test_0'): array
{
    $user = (new User)->setKeyType('string')->forceFill(['id' => $identity]);
    $request = Request::create('/zero/mutate', 'POST');
    $request->setUserResolver(fn (): User => $user);

    app()->instance('request', $request);
    $processor = new MutationProcessor(resolve(ModelRegistry::class), $app, $schema);

    return $processor->process([
        'clientGroupID' => $group, 'pushVersion' => $version, 'timestamp' => 1,
        'requestID' => 'request-a', 'mutations' => $mutations,
    ], $params ?? ['appID' => $app, 'schema' => $schema], new ResolvedContext(['user_id' => $identity], $identity));
}

/** @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mutationProtocolMutation(int $id, array $input = ['title' => 'Hello'], string $method = 'store', string $controller = ControllerMutationController::class): array
{
    $program = mutationProtocolProgram($method, $controller);
    $ids = [];
    foreach (array_keys($program->sites) as $index => $site) {
        $ids[$site] = '019a0000-0000-7000-8000-'.str_pad((string) ($id * 10 + $index), 12, '0', STR_PAD_LEFT);
    }

    return ['type' => 'custom', 'id' => $id, 'clientID' => 'client-a', 'name' => $program->name(), 'timestamp' => 1,
        'args' => [['version' => $program->fingerprint, 'input' => (object) $input, 'route' => (object) [], 'prepared' => ['timestamp' => 1700000000123, 'ids' => (object) $ids]]]];
}

test('cancelled eloquent events are durable rejections', function (): void {
    Task::saving(fn (): false => false);
    $result = mutationProtocolProcess([mutationProtocolMutation(1)]);
    expect(data_get($result, 'mutations.0.result.details.code'))->toBe('validation');
    expect(Task::count())->toBe(0);
    expect(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(1);
});

test('the committed redirect instruction survives retry without creating another row', function (): void {
    $mutation = mutationProtocolMutation(1, ['title' => 'Open'], 'confirmed', RedirectMutationController::class);
    $result = mutationProtocolProcess([$mutation]);
    $data = ['kind' => 'visit', 'route' => 'todos.show', 'parameters' => ['todo' => Task::firstOrFail()->id]];
    expect(data_get($result, 'mutations.0.result.data'))->toBe($data);
    expect(data_get(mutationProtocolProcess([$mutation]), 'mutations.0.result.error'))->toBe('alreadyProcessed');

    $persisted = DB::table('zero_test_0.mutations')->value('result');
    expect(is_string($persisted) ? json_decode($persisted, true, flags: JSON_THROW_ON_ERROR) : null)->toEqual(['data' => $data]);
    expect(Task::count())->toBe(1);
});

test('controller results are persisted and duplicate invocations never execute or dispatch twice', function (): void {
    $committed = [];
    Task::created(function (Task $task) use (&$committed): void {
        DB::afterCommit(function () use ($task, &$committed): void {
            $committed[] = $task->id;
        });
    });
    $mutation = mutationProtocolMutation(1);
    $result = mutationProtocolProcess([$mutation]);
    $id = Task::firstOrFail()->id;
    expect(data_get($result, 'mutations.0.result.data'))->toBe(['id' => $id]);
    $persisted = DB::table('zero_test_0.mutations')->value('result');
    expect($persisted)->toBe(json_encode(['data' => ['id' => $id]], JSON_THROW_ON_ERROR));
    expect($committed)->toBe([$id]);
    expect(data_get(mutationProtocolProcess([$mutation]), 'mutations.0.result.error'))->toBe('alreadyProcessed');
    expect(Task::count())->toBe(1);
    expect($committed)->toBe([$id]);
    expect(DB::table('zero_test_0.mutations')->value('result'))->toBe($persisted);
    expect(mutationProtocolProcess([mutationProtocolMutation(3)])['reason'])->toBe('oooMutation');
    expect(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1);
});

test('validation rejection is durable and does not block later controller mutations', function (): void {
    $result = mutationProtocolProcess([mutationProtocolMutation(1, []), mutationProtocolMutation(2)]);
    expect(data_get($result, 'mutations.0.result.details.code'))->toBe('validation');
    expect(data_get($result, 'mutations.0.result.details.fields'))->toHaveKey('title');
    expect(Task::count())->toBe(1);
    expect(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(2);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(2);
});

test('late controller rejection rolls back all records and after commit work before persisting failure', function (): void {
    $committed = [];
    Task::created(function (Task $task) use (&$committed): void {
        DB::afterCommit(function () use ($task, &$committed): void {
            $committed[] = $task->id;
        });
        if ($task->title !== 'Hello') {
            throw new AuthorizationException;
        }
    });
    $mutation = mutationProtocolMutation(1, ['title' => 'Hello', 'done' => true]);
    $result = mutationProtocolProcess([$mutation]);
    expect(data_get($result, 'mutations.0.result.details.code'))->toBe('forbidden');
    expect(Task::count())->toBe(0);
    expect($committed)->toBe([]);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(1);
    expect(data_get(mutationProtocolProcess([$mutation]), 'mutations.0.result.error'))->toBe('alreadyProcessed');
});

test('invalid controller results roll back writes and callbacks and permanently advance progress', function (): void {
    $committed = false;
    Task::created(function () use (&$committed): void {
        DB::afterCommit(function () use (&$committed): void {
            $committed = true;
        });
    });
    $mutation = mutationProtocolMutation(1, ['title' => 'invalid'], 'structured');
    expect(data_get(mutationProtocolProcess([$mutation]), 'mutations.0.result.details.code'))->toBe('invalid_result');
    expect(Task::count())->toBe(0);
    expect($committed)->toBeFalse();
    expect(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(1);
});

test('database failures roll back application writes and remain retryable', function (): void {
    Task::created(function (): void {
        DB::statement('SELECT definitely_missing_function()');
    });
    $mutation = mutationProtocolMutation(1);
    expect(mutationProtocolProcess([$mutation])['reason'])->toBe('database');
    expect(Task::count())->toBe(0);
    expect(DB::table('zero_test_0.clients')->count())->toBe(0);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(0);
    Task::flushEventListeners();
    expect(mutationProtocolProcess([$mutation])['kind'])->toBe('MutateResponse');
});

test('constraint failures are durable application rejections and roll back earlier writes', function (): void {
    $mutation = mutationProtocolMutation(1, ['title' => 'Hello', 'done' => true]);
    $ids = data_get($mutation, 'args.0.prepared.ids');
    if (! $ids instanceof stdClass) {
        throw new LogicException('Expected prepared identities.');
    }

    Task::forceCreate(['id' => $ids->s1, 'user_id' => 'alice', 'title' => 'Existing']);
    expect(data_get(mutationProtocolProcess([$mutation]), 'mutations.0.result.details.code'))->toBe('constraint');
    expect(Task::count())->toBe(1);
    expect(Task::firstOrFail()->title)->toBe('Existing');
    expect(DB::table('zero_test_0.mutations')->count())->toBe(1);
});

test('void successes do not persist results while explicit null does', function (): void {
    $result = mutationProtocolProcess([mutationProtocolMutation(1, method: 'noResult'), mutationProtocolMutation(2, method: 'authoritative')]);
    expect(data_get($result, 'mutations.0.result'))->toEqual((object) []);
    expect(data_get($result, 'mutations.1.result'))->toEqual((object) ['data' => null]);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(1);
    expect(DB::table('zero_test_0.mutations')->value('result'))->toBe('{"data":null}');
});

test('unregistered controller mutations are durable rejections', function (): void {
    $mutation = mutationProtocolMutation(1);
    $mutation['name'] = 'm_unknown';
    expect(data_get(mutationProtocolProcess([$mutation]), 'mutations.0.result.details.code'))->toBe('unknown_mutation');
    expect(Task::count())->toBe(0);
    expect(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(1);
});

test('request schema app and protocol version are verified before accessing bookkeeping', function (): void {
    $mutation = mutationProtocolMutation(1);
    expect(mutationProtocolProcess([$mutation], ['schema' => 'public', 'appID' => 'other'])['reason'])->toBe('parse');
    expect(mutationProtocolProcess([$mutation], version: 2)['reason'])->toBe('unsupportedPushVersion');
    expect(DB::table('zero_test_0.clients')->count())->toBe(0);
});

test('result cleanup is scoped to the current group and preserves progress', function (): void {
    mutationProtocolProcess([mutationProtocolMutation(1)]);
    DB::table('zero_test_0.mutations')->insert(['clientGroupID' => 'other', 'clientID' => 'client-a', 'mutationID' => 1, 'result' => '{}']);
    $cleanup = ['type' => 'custom', 'id' => 0, 'clientID' => 'client-a', 'name' => '_zero_cleanupResults', 'timestamp' => 1,
        'args' => [['clientGroupID' => 'group-a', 'clientID' => 'client-a', 'upToMutationID' => 1]]];
    expect(mutationProtocolProcess([$cleanup])['kind'])->toBe('MutateResponse');
    expect(DB::table('zero_test_0.mutations')->pluck('clientGroupID')->all())->toBe(['other']);
    expect(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1);
});

test('mutation progress and cleanup are bound to the authenticated Zero identity', function (): void {
    mutationProtocolProcess([mutationProtocolMutation(1)]);
    DB::table('zero_test_0.mutations')->insert(['clientGroupID' => 'group-a', 'clientID' => 'client-b', 'mutationID' => 1, 'result' => '{}']);

    $collision = mutationProtocolProcess([mutationProtocolMutation(2)], identity: 'bob');
    expect($collision['kind'])->toBe('PushFailed')
        ->and(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1)
        ->and(DB::table('zero_test_0.mutations')->count())->toBe(2)
        ->and(Task::count())->toBe(1);
    expect(mutationProtocolProcess([mutationProtocolMutation(2)], app: 'other')['kind'])->toBe('PushFailed')
        ->and(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1);

    $single = ['type' => 'custom', 'id' => 0, 'clientID' => 'client-a', 'name' => '_zero_cleanupResults', 'timestamp' => 1,
        'args' => [['clientGroupID' => 'group-a', 'clientID' => 'client-a', 'upToMutationID' => 1]]];
    $bulk = ['type' => 'custom', 'id' => 0, 'clientID' => 'client-a', 'name' => '_zero_cleanupResults', 'timestamp' => 1,
        'args' => [['type' => 'bulk', 'clientGroupID' => 'group-a', 'clientIDs' => ['client-a', 'client-b']]]];
    expect(mutationProtocolProcess([$single], identity: 'bob')['kind'])->toBe('MutateResponse')
        ->and(mutationProtocolProcess([$bulk], identity: 'bob')['kind'])->toBe('MutateResponse')
        ->and(DB::table('zero_test_0.mutations')->count())->toBe(2);

    expect(mutationProtocolProcess([mutationProtocolMutation(2)])['kind'])->toBe('MutateResponse')
        ->and(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(2)
        ->and(Task::count())->toBe(2);
    mutationProtocolProcess([$single]);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(2);
});

test('unbound legacy bookkeeping cannot be claimed or cleaned by a callback identity', function (): void {
    DB::table('zero_test_0.clients')->insert(['clientGroupID' => 'legacy', 'clientID' => 'client-a', 'lastMutationID' => 1]);
    DB::table('zero_test_0.mutations')->insert(['clientGroupID' => 'legacy', 'clientID' => 'client-a', 'mutationID' => 1, 'result' => '{}']);

    expect(mutationProtocolProcess([mutationProtocolMutation(2)], identity: 'alice', group: 'legacy')['kind'])->toBe('PushFailed')
        ->and(DB::table('zero_test_0.clients')->where('clientGroupID', 'legacy')->value('lastMutationID'))->toBe(1)
        ->and(Task::count())->toBe(0);

    $cleanup = ['type' => 'custom', 'id' => 0, 'clientID' => 'client-a', 'name' => '_zero_cleanupResults', 'timestamp' => 1,
        'args' => [['clientGroupID' => 'legacy', 'clientID' => 'client-a', 'upToMutationID' => 1]]];
    expect(mutationProtocolProcess([$cleanup], identity: 'alice', group: 'legacy')['kind'])->toBe('MutateResponse')
        ->and(DB::table('zero_test_0.mutations')->where('clientGroupID', 'legacy')->count())->toBe(1);
});

test('policy not-found denial remains a durable concealed mutation failure', function (): void {
    Task::creating(function (): void {
        throw (new AuthorizationException)->withStatus(404);
    });
    $mutation = mutationProtocolMutation(1);
    $result = mutationProtocolProcess([$mutation]);
    expect(data_get($result, 'mutations.0.result.details.code'))->toBe('not_found')
        ->and(data_get($result, 'mutations.0.result.message'))->toBe('The record is unavailable.')
        ->and(Task::count())->toBe(0)
        ->and(DB::table('zero_test_0.clients')->value('lastMutationID'))->toBe(1)
        ->and(DB::table('zero_test_0.mutations')->count())->toBe(1);
    expect(data_get(mutationProtocolProcess([$mutation]), 'mutations.0.result.error'))->toBe('alreadyProcessed');
});
