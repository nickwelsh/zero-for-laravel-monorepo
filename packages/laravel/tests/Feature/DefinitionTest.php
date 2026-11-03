<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use LogicException;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

test('external configuration owns mutation fills without exporting authorization internals', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $metadata = $registry->get('Task');
    expect($metadata->writable)->toBe(['list_id', 'title', 'done', 'completed_at']);
    expect($metadata->serverFill)->toBe(['user_id' => 'user_id']);
    expect($metadata->toArray())->not->toHaveKeys(['authorization', 'authorizationRelations', 'serverScopes', 'policyVisibility']);
    expect($metadata->relations)->toBe([]);

    $source = (new TypeScriptGenerator)->generate($registry);
    expect($source)->not->toContain('permission_index', 'permission_resources_', 'owner_id', 'serverScopes');
});

test('a protected query requires an explicit trusted actor and rejects context substitution', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $base = new Query($registry->get('Task'), $registry);
    $compiler = new QueryCompiler($registry);
    expect(fn (): array => $compiler->compileDeclaration($base, new ZeroContext(['user_id' => 'alice'])))
        ->toThrow(LogicException::class, 'visibleTo');
    expect(fn (): Query => $base->visibleTo(null))->toThrow(AuthorizationException::class);

    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = $base->visibleTo($alice);
    expect($base->ast())->toBe(['table' => 'tasks']);
    expect(fn (): array => $compiler->compileDeclaration($query, new ZeroContext(['user_id' => 'bob'])))
        ->toThrow(AuthorizationException::class);
    expect(fn (): array => $compiler->compileDeclaration($query, new ZeroContext([])))
        ->toThrow(AuthorizationException::class);
    $ast = $compiler->compileDeclaration($query, new ZeroContext(['user_id' => 'alice']));
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('permission_index', 'user:alice', 'a_owner_id');
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->not->toContain('__visibility');
});

test('public reads cannot be used to bypass a bound private resource', function (): void {
    config(['zero.public_models' => [Task::class]]);
    new ModelRegistry([Task::class]);
})->throws(LogicException::class, 'never both');

test('scalar ownership decisions use managed grants and fresh database identities', function (): void {
    OwnerPermissions::install();
    $alice = \ZeroForLaravel\Tests\Fixtures\OwnerUser::findOrFail('alice');
    $task = Task::create(['user_id' => 'alice', 'title' => 'Owned']);
    expect(\Illuminate\Support\Facades\Gate::forUser($alice)->allows('view', $task))->toBeTrue();
    \ZeroForLaravel\Permissions\Casbin::model('owner')->removePolicy('owner:alice', 'tasks', 'view', 'owner', 'allow');
    expect(\Illuminate\Support\Facades\Gate::forUser($alice)->allows('view', $task))->toBeFalse();
    \ZeroForLaravel\Permissions\Casbin::model('owner')->addPolicy('owner:alice', 'tasks', 'view', 'owner', 'allow');
    \Illuminate\Support\Facades\DB::table('tasks')->where('id', $task->id)->update(['user_id' => 'bob']);
    expect(\Illuminate\Support\Facades\Gate::forUser($alice)->allows('view', $task))->toBeFalse();
});
