<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use InvalidArgumentException;
use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\QueryProcessor;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

test('declarations mutate aliases, clone independent branches, do not execute SQL, and cannot become snapshot props', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    app()->instance(ModelRegistry::class, $registry);
    $connection = $registry->connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $base = Task::zeroQuery()->withoutGlobalScopes();
    $ascending = (clone $base)->where('title', '0')->orderBy('id', 'asc')->limit(0);
    $descending = (clone $base)->orderBy('id', 'desc')->first();
    $single = $base->find('task-a');
    $collection = (clone $base)->limit(1)->get();
    expect($single->format()['singular'])->toBeTrue()
        ->and(data_get($single->ast(), 'where.right.value'))->toBe('task-a')
        ->and($collection->format()['singular'])->toBeFalse();
    expect($base->ast())->toBe(['table' => 'tasks']);
    expect($ascending->where('done', false))->toBe($ascending);
    expect($ascending->ast()['limit'])->toBe(0);
    expect($descending->format()['singular'])->toBeTrue();
    expect($connection->getQueryLog())->toBe([]);
    expect(fn (): string => json_encode($base, JSON_THROW_ON_ERROR))->toThrow(LogicException::class, 'extracted');

    $cursor = $base->cursorPaginate(2)->atPosition(['__cursor' => ['id' => 'original']]);
    $export = $cursor->ast();
    data_set($export, 'start.row.id', 'changed');
    expect(data_get($cursor->ast(), 'start.row.id'))->toBe('original');
});

test('declaration compilation enforces visibility on results and negative existence predicates', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    app()->instance(ModelRegistry::class, $registry);
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = Task::zeroQuery()->visibleTo($alice)->with('comments', fn (ZeroQuery $comments): ZeroQuery => $comments->visibleTo($alice))
        ->whereDoesntHave('tags', fn (ZeroQuery $tags): ZeroQuery => $tags->visibleTo($alice));
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect($ast)->not->toHaveKey('limit');
    expect($ast)->not->toHaveKey('orderBy');
    expect(json_encode(data_get($ast, 'where.conditions.0'), JSON_THROW_ON_ERROR))->toContain('permission_index', 'user:alice');
    expect(data_get($ast, 'where.conditions.1.op'))->toBe('IS');
    expect(json_encode(data_get($ast, 'related.0.subquery.where'), JSON_THROW_ON_ERROR))->toContain('permission_index', 'user:alice');
    expect(data_get($ast, 'where.conditions.2.op'))->toBe('NOT EXISTS');
    expect(json_encode(data_get($ast, 'where.conditions.2.related.subquery.where.related.subquery.where'), JSON_THROW_ON_ERROR))->toContain('permission_index', 'user:alice');
    expect(fn (): array => (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext([])))
        ->toThrow(AuthorizationException::class);
});

test('declarations reject unsupported columns and native junction bounds', function (): void {
    /** @var TestCase $this */
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    expect(fn (): ZeroQuery => Task::zeroQuery()->where('secret', 'value'))->toThrow(InvalidArgumentException::class);
    expect(fn (): ZeroQuery => Task::zeroQuery()->where('id', 'IN', [null]))->toThrow(InvalidArgumentException::class, 'whereIn');
    expect(fn (): ZeroQuery => Task::zeroQuery()->limit(-1))->toThrow(InvalidArgumentException::class);
    expect(fn (): ZeroQuery => Task::zeroQuery()->with('tags', fn ($query) => $query->limit(2)))->toThrow(LogicException::class);
    expect(fn (): ZeroQuery => Task::zeroQuery()->whereExists('tags', ['scalar' => true]))->toThrow(LogicException::class, 'whereHas');
});

test('unregistered controller queries are rejected', function (): void {
    /** @var TestCase $this */
    app()->instance(RegisteredQueries::class, new RegisteredQueries([]));
    $result = (new QueryProcessor(new QueryCompiler(new ModelRegistry($this->models()))))->process(['transform', [
        ['id' => 'missing', 'name' => 'q_unknown', 'args' => [(object) []]],
    ]], new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect($result['kind'])->toBe('QueryResponse');
    expect(data_get($result, 'queries.0.error'))->toBe('parse');
    expect(data_get($result, 'queries.0.details.fields'))->toHaveKey('name');
    expect(data_get($result, 'userID'))->toBe('alice');
});
