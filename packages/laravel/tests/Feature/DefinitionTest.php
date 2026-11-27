<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;
use ZeroForLaravel\Tests\Fixtures\OwnerPolicy;
use ZeroForLaravel\Tests\Fixtures\OwnerUser;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\VisibleTask;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

test('creation defaults remain separate from authorization internals', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $metadata = $registry->get('Task');
    expect($metadata->writable)->toBe(['user_id', 'list_id', 'title', 'done', 'completed_at']);
    expect((new ZeroQuery($metadata, $registry))->pendingAttributes()['user_id'])->toBeInstanceOf(ContextReference::class);
    expect($metadata->toArray())->not->toHaveKeys(['authorization', 'authorizationRelations', 'serverScopes', 'policyVisibility']);
    expect($metadata->relations)->toBe([]);

    $source = (new TypeScriptGenerator)->generate($registry);
    expect($source)->not->toContain('permission_index', 'permission_resources_', 'owner_id', 'serverScopes');
});

test('a protected query requires an explicit trusted actor and rejects context substitution', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $base = new ZeroQuery($registry->get('Task'), $registry);
    $compiler = new QueryCompiler($registry);
    expect(fn (): array => $compiler->compileDeclaration($base, new ResolvedContext(['user_id' => 'alice'], 'alice')))
        ->toThrow(LogicException::class, 'visibleTo');
    expect(fn (): ZeroQuery => $base->visibleTo(null))->toThrow(AuthorizationException::class);

    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = $base->visibleTo($alice);
    expect($query)->toBe($base);
    expect(fn (): array => $compiler->compileDeclaration($query, new ResolvedContext(['user_id' => 'bob'], 'bob')))
        ->toThrow(AuthorizationException::class);
    expect(fn (): array => $compiler->compileDeclaration($query, new ResolvedContext([])))
        ->toThrow(AuthorizationException::class);
    $ast = $compiler->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('permission_index', 'user:alice', 'a_owner_id');
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->not->toContain('__visibility');
});

test('only a resource binding requires policy coverage for a declared read', function (): void {
    Gate::policy(VisibleTask::class, OwnerPolicy::class);
    $compile = function (): array {
        $registry = new ModelRegistry([VisibleTask::class]);
        $query = (new ZeroQuery($registry->get('VisibleTask'), $registry))->where('title', 'Overdue');

        return (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext([], 'alice'));
    };
    expect($compile()['where'])->toEqual([
        'type' => 'simple', 'op' => '=',
        'left' => ['type' => 'column', 'name' => 'title'],
        'right' => ['type' => 'literal', 'value' => 'Overdue'],
    ]);

    config(['permissions.resources.'.VisibleTask::class => config('permissions.resources.'.Task::class)]);
    expect($compile)->toThrow(LogicException::class, 'require visibleTo');
});

test('scalar ownership decisions use managed grants and fresh database identities', function (): void {
    OwnerPermissions::install();
    $alice = OwnerUser::findOrFail('alice');
    $task = Task::create(['user_id' => 'alice', 'title' => 'Owned']);
    expect(Gate::forUser($alice)->allows('view', $task))->toBeTrue();
    Casbin::model('owner')->removePolicy('owner:alice', 'tasks', 'view', 'owner', 'allow');
    expect(Gate::forUser($alice)->allows('view', $task))->toBeFalse();
    Casbin::model('owner')->addPolicy('owner:alice', 'tasks', 'view', 'owner', 'allow');
    DB::table('tasks')->where('id', $task->id)->update(['user_id' => 'bob']);
    expect(Gate::forUser($alice)->allows('view', $task))->toBeFalse();
});
