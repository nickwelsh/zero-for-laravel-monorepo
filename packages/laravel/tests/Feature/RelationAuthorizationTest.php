<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

test('related private rows require their own explicit visibility declaration', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new Query($registry->get('Task'), $registry))->visibleTo($alice)->related('list');
    (new QueryCompiler($registry))->compileDeclaration($query, new ZeroContext(['user_id' => 'alice']));
})->throws(LogicException::class, 'visibleTo');

test('junction predicates enforce private target visibility without introducing result bounds', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new Query($registry->get('Task'), $registry))->visibleTo($alice)
        ->whereExists('tags', fn (Query $tags): Query => $tags->visibleTo($alice)->where('name', 'Reading'));
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ZeroContext(['user_id' => 'alice']));
    $encoded = json_encode($ast, JSON_THROW_ON_ERROR);
    expect($encoded)->toContain('task_tag', 'tags', 'permission_index', 'Reading', 'user:alice');
    expect($encoded)->not->toContain('"limit"', '"orderBy"', '__visibility');
    expect($ast)->not->toHaveKey('related');
});

test('nested negative exists retains visibility on every private target', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new Query($registry->get('Task'), $registry))->visibleTo($alice)
        ->where(fn ($op) => $op->not($op->exists('list', fn (Query $list): Query => $list->visibleTo($alice)
            ->whereExists('tasks', fn (Query $tasks): Query => $tasks->visibleTo($alice)
                ->whereExists('comments', fn (Query $comments): Query => $comments->visibleTo($alice)->where('body', 'hidden'))))));
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ZeroContext(['user_id' => 'alice']));
    $encoded = json_encode($ast, JSON_THROW_ON_ERROR);
    expect($encoded)->toContain('NOT EXISTS', 'hidden');
    // Root task, list, nested task and comment each receive their own resource projection.
    expect(substr_count($encoded, '"table":"permission_resources_'))->toBeGreaterThanOrEqual(4);
    expect($encoded)->not->toContain('__visibility');
});
