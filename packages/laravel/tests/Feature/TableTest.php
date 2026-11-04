<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedGroup;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\OwnerPostgresTestCase;

uses(OwnerPostgresTestCase::class);

function taskTable(): Table
{
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);

    return Table::for(Task::class)->visibleTo($alice)
        ->allowedFilters([
            AllowedFilter::text('title')->operators(['equals', 'notEquals', 'contains', 'startsWith', 'endsWith']),
            AllowedFilter::exact('completed', 'done'),
            AllowedFilter::exact('list', 'list_id')->nullable(),
            AllowedFilter::number('rating')->operators(['equals', 'notEquals', 'in', 'gt', 'gte', 'lt', 'lte'])->nullable(),
            AllowedFilter::date('completed_at')->operators(['equals', 'gt', 'lte'])->nullable(),
        ])->allowedSorts(['title', 'rating'])->allowedGroups([
            AllowedGroup::field('done'), AllowedGroup::belongsTo('list')->label('name'),
        ])->defaultGroupBy(['done', 'list'])->maxGroupDepth(2)->defaultSort('title');
}

beforeEach(function (): void {
    Schema::table('tasks', fn (Blueprint $table) => $table->integer('rating')->nullable());
    app()->instance(ModelRegistry::class, new ModelRegistry([Task::class, TaskList::class, Tag::class, Comment::class]));
    foreach ([
        ['alice', 'Alpha%_\\é', false, 2, null],
        ['alice', 'alphaX_\\e', true, 7, '2026-11-04 10:00:00'],
        ['alice', 'éclair', false, null, null],
        ['bob', 'Alpha%_\\é', false, 99, null],
    ] as $index => [$owner, $title, $done, $rating, $completed]) {
        DB::table('tasks')->insert(['id' => sprintf('00000000-0000-4000-8000-%012d', $index + 1), 'user_id' => $owner,
            'title' => $title, 'done' => $done, 'rating' => $rating, 'completed_at' => $completed]);
    }
});

test('table predicates preserve authorized membership, literal matching, nulls and date offsets', function (): void {
    $table = taskTable();
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $condition = fn (string $field, string $operator, mixed $value): array => ['kind' => 'condition', 'field' => $field, 'operator' => $operator, 'value' => $value];
    $cases = [
        [$condition('title', 'contains', '%_\\'), [1]],
        [$condition('title', 'startsWith', 'Alpha'), [1]],
        [$condition('title', 'endsWith', 'é'), [1]],
        [$condition('title', 'notEquals', 'Alpha%_\\é'), [2, 3]],
        [$condition('rating', 'gt', 2), [2]],
        [$condition('rating', 'gte', 2), [1, 2]],
        [$condition('rating', 'lt', 7), [1]],
        [$condition('rating', 'lte', 7), [1, 2]],
        [$condition('rating', 'in', [7, 3]), [2]],
        [$condition('rating', 'notEquals', 2), [2]],
        [['kind' => 'condition', 'field' => 'rating', 'operator' => 'isNull'], [3]],
        [['kind' => 'condition', 'field' => 'rating', 'operator' => 'isNotNull'], [1, 2]],
        [$condition('completed_at', 'equals', '2026-11-04T12:00:00+02:00'), [2]],
        [['kind' => 'or', 'children' => [$condition('rating', 'gt', 6), $condition('title', 'contains', '%')]], [1, 2]],
    ];
    foreach ($cases as [$filter, $indices]) {
        $expected = array_map(fn (int $index): string => sprintf('00000000-0000-4000-8000-%012d', $index), $indices);
        expect($table->sql($context, $filter)->orderBy('id')->pluck('id')->all())->toBe($expected);
        $ast = (new QueryCompiler(resolve(ModelRegistry::class)))->compileDeclaration($table->rows($filter), $context);
        expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('permission_index', 'user:alice');
    }

    $path = $table->path([['field' => 'done', 'value' => false], ['field' => 'list', 'value' => null]], ['done', 'list'], leaf: true);
    expect($table->sql($context, null, $path)->count())->toBe(2);
    expect($table->rows(null, [], $table->ordering([['field' => 'rating', 'direction' => 'desc']]))->ast()['orderBy'])->toBe([['rating', 'desc'], ['id', 'asc']]);
    expect(fn (): Builder => $table->sql(new ResolvedContext(['user_id' => 'bob'], 'bob')))->toThrow(AuthorizationException::class);
});

test('table capabilities reject malformed or broadened query input', function (): void {
    $table = taskTable();
    foreach ([
        ['kind' => 'condition', 'field' => 'secret', 'operator' => 'equals', 'value' => 'x'],
        ['kind' => 'condition', 'field' => 'completed', 'operator' => 'contains', 'value' => 'x'],
        ['kind' => 'condition', 'field' => 'completed', 'operator' => 'equals', 'value' => 'false'],
        ['kind' => 'condition', 'field' => 'list', 'operator' => 'equals', 'value' => null],
        ['kind' => 'condition', 'field' => 'list', 'operator' => 'in', 'value' => [null]],
        ['kind' => 'condition', 'field' => 'list', 'operator' => 'in', 'value' => []],
        ['kind' => 'condition', 'field' => 'title', 'operator' => 'contains', 'value' => ''],
        ['kind' => 'condition', 'field' => 'title', 'operator' => 'equals', 'value' => str_repeat('a', 1001)],
        ['kind' => 'condition', 'field' => 'completed_at', 'operator' => 'equals', 'value' => '2026-11-04T10:00:00'],
        ['kind' => 'and', 'children' => []],
        ['kind' => 'or', 'children' => array_fill(0, 101, ['kind' => 'condition', 'field' => 'completed', 'operator' => 'equals', 'value' => false])],
    ] as $filter) {
        expect(fn (): ZeroQuery => $table->rows($filter))->toThrow(ValidationException::class);
    }

    expect(fn (): array => $table->grouping(['done', 'done']))->toThrow(ValidationException::class);
    expect(fn (): array => $table->path([['field' => 'list', 'value' => null]], ['done', 'list']))->toThrow(ValidationException::class);
    expect(fn (): array => $table->ordering([['field' => 'secret', 'direction' => 'asc']]))->toThrow(ValidationException::class);
    expect(fn (): Table => Table::for(Task::class)->allowedFilters([AllowedFilter::number('title')]))->toThrow(InvalidArgumentException::class);
    expect(fn (): Table => Table::for(Task::class)->allowedFilters([AllowedFilter::exact('done')->nullable()]))->toThrow(InvalidArgumentException::class);
    expect(fn (): Table => Table::for(Task::class)->allowedGroups([AllowedGroup::belongsTo('tags')->label('name')]))->toThrow(InvalidArgumentException::class);
});

test('nullable filter operations do not depend on declaration order and unsupported scopes fail explicitly', function (): void {
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $table = Table::for(Task::class)->visibleTo($alice)->allowedFilters([
        AllowedFilter::number('rating')->nullable()->operators(['gt'])->operators(['lt']),
    ]);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    expect($table->sql($context, ['kind' => 'condition', 'field' => 'rating', 'operator' => 'isNull'])->pluck('title')->all())->toBe(['éclair']);
    expect(fn (): ZeroQuery => $table->rows(['kind' => 'condition', 'field' => 'rating', 'operator' => 'gt', 'value' => 1]))->toThrow(ValidationException::class);
    $unsupported = Table::for(Task::class)->visibleTo($alice);
    $unsupported->base->whereExists('tags');
    expect(fn (): Builder => $unsupported->sql($context))->toThrow(LogicException::class, 'portable SQL lowering');
});
