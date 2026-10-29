<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use ZeroForLaravel\Domain\DomainRegistry;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\CrudExecutor;
use ZeroForLaravel\Mutation\MutationProcessor;
use ZeroForLaravel\Tests\Fixtures\CompleteTasks;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\PostgresTestCase;
use ZeroForLaravel\ZeroContext;

uses(PostgresTestCase::class);

/** @param list<string> $ids
 * @return array<string, mixed>
 */
function completeTasksProcess(PostgresTestCase $test, array $ids, int $number = 1): array
{
    app()->instance(DomainRegistry::class, new DomainRegistry(['completeTasks' => CompleteTasks::class]));
    $registry = new ModelRegistry($test->models());
    $processor = new MutationProcessor($registry, new CrudExecutor($registry), 'test', 'zero_test_0');
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);

    return $processor->process(['clientGroupID' => 'domain-group', 'requestID' => 'request', 'pushVersion' => 1, 'timestamp' => 1,
        'mutations' => [['clientID' => 'domain-client', 'id' => $number, 'type' => 'custom', 'timestamp' => 1, 'name' => 'actions.completeTasks', 'args' => [['ids' => $ids]]]],
    ], ['appID' => 'test', 'schema' => 'zero_test_0'], new ZeroContext(['user_id' => 'alice']), $user);
}

test('named actions use native progress, deduplication and after-commit dispatch', function (): void {
    /** @var PostgresTestCase $this */
    CompleteTasks::$committed = [];
    $task = Task::create(['user_id' => 'alice', 'title' => 'Complete']);
    $id = $task->getKey();
    expect($id)->toBeString();
    if (! is_string($id)) {
        return;
    }

    $response = completeTasksProcess($this, [$id]);
    expect(data_get($response, 'mutations.0.result'))->toEqual((object) []);
    expect($task->fresh()?->done)->toBeTrue();
    expect(CompleteTasks::$committed)->toBe([$id]);

    $response = completeTasksProcess($this, [$id]);
    expect(data_get($response, 'mutations.0.result.error'))->toBe('alreadyProcessed');
    expect(CompleteTasks::$committed)->toBe([$id]);
});

test('domain rejections roll back all records and after-commit work and remain durable', function (): void {
    /** @var PostgresTestCase $this */
    CompleteTasks::$committed = [];
    $first = Task::create(['user_id' => 'alice', 'title' => 'First']);
    $second = Task::create(['user_id' => 'alice', 'title' => 'reject']);
    $ids = [$first->getKey(), $second->getKey()];
    if (! is_string($ids[0]) || ! is_string($ids[1])) {
        throw new LogicException;
    }

    $response = completeTasksProcess($this, $ids);
    expect(data_get($response, 'mutations.0.result.details.code'))->toBe('blocked');
    expect(Task::where('done', true)->count())->toBe(0);
    expect(CompleteTasks::$committed)->toBe([]);
    expect(DB::table('zero_test_0.mutations')->count())->toBe(1);
    expect(data_get(completeTasksProcess($this, $ids), 'mutations.0.result.error'))->toBe('alreadyProcessed');
});

test('domain inputs have bounded validation and authoritative ownership', function (): void {
    /** @var PostgresTestCase $this */
    $response = completeTasksProcess($this, ['invalid']);
    expect(data_get($response, 'mutations.0.result.details.fields'))->toHaveKey('ids.0');
    $task = Task::create(['user_id' => 'bob', 'title' => 'Private']);
    $id = $task->getKey();
    if (! is_string($id)) {
        throw new LogicException;
    }

    expect(data_get(completeTasksProcess($this, [$id], 2), 'mutations.0.result.details.code'))->toBe('forbidden');
    expect(data_get(completeTasksProcess($this, [$id, $id, $id, $id], 3), 'mutations.0.result.details.code'))->toBe('validation');
});

test('generation emits explicit domain contracts and typed native bindings', function (): void {
    /** @var PostgresTestCase $this */
    config()->set('zero.route_prefix', '/api/domain/');
    app()->instance(DomainRegistry::class, new DomainRegistry(['completeTasks' => CompleteTasks::class]));
    $source = (new TypeScriptGenerator)->generate(new ModelRegistry($this->models()));
    expect($source)->toContain('domainDefinitions', 'DomainInputs', 'createDomainMutations(schema, runtime.mutators', '"maxItems": 3', 'resultURL: "/api/domain/results"');
});
