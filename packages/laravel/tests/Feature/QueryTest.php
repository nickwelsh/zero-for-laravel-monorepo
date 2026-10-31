<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\QueryProcessor;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

test('declarations are immutable, do not execute SQL, and cannot become snapshot props', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    app()->instance(ModelRegistry::class, $registry);
    $connection = $registry->connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $base = Task::zeroQuery();
    $ascending = $base->where('title', '0')->orderBy('id', 'asc')->limit(0);
    $descending = $base->orderBy('id', 'desc')->one();
    expect($base->ast())->toBe(['table' => 'tasks']);
    expect($ascending->ast()['limit'])->toBe(0);
    expect($descending->format()['singular'])->toBeTrue();
    expect($connection->getQueryLog())->toBe([]);
    expect(fn (): string => json_encode($base, JSON_THROW_ON_ERROR))->toThrow(LogicException::class, 'extracted');

    $cursor = $base->start(['id' => 'original'])->related('comments', fn ($query) => $query->start(['id' => 'child']));
    $export = $cursor->ast();
    data_set($export, 'start.row.id', 'changed');
    data_set($export, 'related.0.subquery.start.row.id', 'changed child');
    expect(data_get($cursor->ast(), 'start.row.id'))->toBe('original');
    expect(data_get($cursor->ast(), 'related.0.subquery.start.row.id'))->toBe('child');
});

test('declaration compilation enforces visibility on results and negative existence predicates', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    app()->instance(ModelRegistry::class, $registry);
    $query = Task::zeroQuery()->related('comments')
        ->where(fn ($expression) => $expression->not($expression->exists('tags')));
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ZeroContext(['user_id' => 'alice']));
    expect($ast)->not->toHaveKey('limit');
    expect($ast)->not->toHaveKey('orderBy');
    expect(data_get($ast, 'where.conditions.0.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.conditions.1.op'))->toBe('IS');
    expect(data_get($ast, 'related.0.subquery.where.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.conditions.2.op'))->toBe('NOT EXISTS');
    expect(data_get($ast, 'where.conditions.2.related.subquery.where.related.subquery.where.right.value'))->toBe('alice');
    expect(fn (): array => (new QueryCompiler($registry))->compileDeclaration($query, new ZeroContext([])))
        ->toThrow(AuthorizationException::class);
});

test('declarations reject unsupported columns and native junction bounds', function (): void {
    /** @var TestCase $this */
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    expect(fn (): Query => Task::zeroQuery()->where('secret', 'value'))->toThrow(InvalidArgumentException::class);
    expect(fn (): Query => Task::zeroQuery()->where('id', 'IN', [null]))->toThrow(ValidationException::class);
    expect(fn (): Query => Task::zeroQuery()->limit(-1))->toThrow(InvalidArgumentException::class);
    expect(fn (): Query => Task::zeroQuery()->related('tags', fn ($query) => $query->limit(2)))->toThrow(LogicException::class);
    expect(fn (): Query => Task::zeroQuery()->whereExists('tags', ['scalar' => true]))->toThrow(InvalidArgumentException::class);
});

test('unregistered controller queries are rejected', function (): void {
    /** @var TestCase $this */
    app()->instance(RegisteredQueries::class, new RegisteredQueries([]));
    $result = (new QueryProcessor(new QueryCompiler(new ModelRegistry($this->models()))))->process(['transform', [
        ['id' => 'missing', 'name' => 'q_unknown', 'args' => [(object) []]],
    ]], new ZeroContext(['user_id' => 'alice']));
    expect($result['kind'])->toBe('QueryResponse');
    expect(data_get($result, 'queries.0.error'))->toBe('parse');
    expect(data_get($result, 'queries.0.details.fields'))->toHaveKey('name');
    expect(data_get($result, 'userID'))->toBe('alice');
});
