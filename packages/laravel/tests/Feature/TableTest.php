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
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\PolicyCompiler;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedGroup;
use ZeroForLaravel\Tables\AllowedRelation;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableURLState;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
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
        ])->defaultGroupBy([['field' => 'done', 'direction' => 'desc'], ['field' => 'list', 'direction' => 'asc']])->maxGroupDepth(2)->defaultSort('title');
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

    $path = $table->path([['field' => 'done', 'value' => false], ['field' => 'list', 'value' => null]], $table->defaultGrouping, leaf: true);
    expect($table->sql($context, null, $path)->count())->toBe(2);
    expect($table->rows(null, [], $table->ordering([['field' => 'rating', 'direction' => 'desc']]))->ast()['orderBy'])->toBe([['rating', 'desc'], ['id', 'asc']]);
    expect(fn (): Builder => $table->sql(new ResolvedContext(['user_id' => 'bob'], 'bob')))->toThrow(AuthorizationException::class);
});

test('snapshot totals and group membership bind amount, date and a visible nested tag to the same task', function (): void {
    $id = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    foreach ([11, 12, 13, 14, 15] as $list) {
        DB::table('lists')->insert(['id' => $id($list), 'user_id' => 'alice', 'name' => (string) $list]);
    }

    DB::table('tags')->insert(['id' => $id(20), 'user_id' => 'alice', 'name' => 'eligible']);
    foreach ([
        [11, 600, 2026, 'alice'], [11, 700, 2026, 'alice'],
        [12, 600, 2020, 'alice'], [12, 20, 2026, 'alice'],
        [13, 300, 2026, 'alice'], [13, 300, 2026, 'alice'],
        [14, 600, 2026, 'bob'], [15, 600, 2026, 'alice'],
    ] as $i => [$list, $amount, $year, $owner]) {
        $task = $id(100 + $i);
        DB::table('tasks')->insert(['id' => $task, 'user_id' => $owner, 'list_id' => $id($list), 'title' => 'gift', 'rating' => $amount, 'completed_at' => "$year-08-01"]);
        if ($list !== 15) {
            DB::table('task_tag')->insert(['task_id' => $task, 'tag_id' => $id(20)]);
        }
    }

    Tag::addGlobalScope('context-label', fn (\Illuminate\Database\Eloquent\Builder $tags) => $tags->where('name', ContextReference::current(ZeroContext::USER_ID)));
    $table = Table::for(TaskList::class)->visibleTo($alice)->allowedGroups([AllowedGroup::field('name')])->allowedRelations([
        AllowedRelation::named('tasks')->lookup('title', 1)->allowedFilters([
            AllowedFilter::number('amount', 'rating')->operators(['gt']), AllowedFilter::date('received', 'completed_at')->operators(['gte']),
        ])->allowedRelations([AllowedRelation::named('tags')->choices('id', 'name')]),
    ]);
    $filter = ['kind' => 'relation', 'field' => 'tasks', 'operator' => 'some', 'filter' => ['kind' => 'and', 'children' => [
        ['kind' => 'condition', 'field' => 'amount', 'operator' => 'gt', 'value' => 500],
        ['kind' => 'condition', 'field' => 'received', 'operator' => 'gte', 'value' => '2026-01-01T00:00:00Z'],
        ['kind' => 'relation', 'field' => 'tags', 'operator' => 'has', 'value' => $id(20)],
    ]]];
    $context = new ResolvedContext(['user_id' => 'eligible'], 'alice');
    expect($table->sql($context, $filter)->pluck('id')->all())->toBe([$id(11)]);
    expect($table->sql($context, $filter, [['field' => 'name', 'value' => '11']])->count())->toBe(1);
    expect($table->sql(new ResolvedContext(['user_id' => 'different'], 'alice'), $filter)->count())->toBe(0);

    $native = (new QueryCompiler(resolve(ModelRegistry::class)))->compileDeclaration($table->rows($filter), $context);
    expect(json_encode($native, JSON_THROW_ON_ERROR))->toContain('eligible', 'user:alice');
});

test('junction membership distinguishes any and every distinct visible key', function (): void {
    $id = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
    foreach ([[11, 'alice'], [12, 'alice'], [13, 'bob']] as [$n, $owner]) {
        DB::table('tags')->insert(['id' => $id($n), 'name' => "Tag $n", 'user_id' => $owner]);
    }

    DB::table('tasks')->insert(['id' => $id(5), 'user_id' => 'alice', 'title' => 'None visible']);
    foreach ([[1, 11], [2, 12], [3, 11], [3, 12], [4, 11], [5, 13]] as [$task, $tag]) {
        DB::table('task_tag')->insert(['task_id' => $id($task), 'tag_id' => $id($tag)]);
    }

    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    // A picker is optional; raw typed IDs have the same meaning.
    $table = taskTable()->allowedRelations([AllowedRelation::named('tags')]);
    $node = ['kind' => 'relation', 'field' => 'tags'];
    foreach ([
        [['operator' => 'has', 'value' => $id(11)], [1, 3]],
        [['operator' => 'has', 'value' => $id(13)], []],
        [['operator' => 'any', 'value' => [$id(11), $id(12)]], [1, 2, 3]],
        [['operator' => 'all', 'value' => [$id(11), $id(12), $id(11)]], [3]],
    ] as [$operation, $expected]) {
        expect($table->sql($context, $node + $operation)->orderBy('id')->pluck('id')->all())->toBe(array_map($id, $expected));
    }

    $either = ['kind' => 'or', 'children' => [$node + ['operator' => 'has', 'value' => $id(12)], $node + ['operator' => 'has', 'value' => $id(11)]]];
    expect($table->where('rating', '>=', 2)->sql($context, $either)->orderBy('id')->pluck('id')->all())->toBe([$id(1), $id(2)]);
    expect(fn (): Builder => $table->sql($context, $node + ['operator' => 'none']))->toThrow(ValidationException::class);
});

test('table counts intersect Eloquent global scopes with policy visibility and reject untracked membership', function (): void {
    Task::addGlobalScope('unfinished', fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('done', false));
    $table = taskTable();
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    expect($table->sql($context)->count())->toBe(2);
    $ast = (new QueryCompiler(resolve(ModelRegistry::class)))->compileDeclaration($table->rows(), $context);
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('permission_index', 'done', 'deleted_at');
    expect(fn (): \ZeroForLaravel\Permissions\PolicyPlan => (new PolicyCompiler)->compile(Task::class))->toThrow(LogicException::class, 'detached permission projections');

    Task::addGlobalScope('top', fn (\Illuminate\Database\Eloquent\Builder $query) => $query->limit(1));
    expect(fn (): Builder => $table->sql($context))->toThrow(LogicException::class, 'unbounded');
    Task::addGlobalScope('raw', fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereRaw('1 = 0'));
    expect(fn (): Table => Table::for(Task::class))->toThrow(LogicException::class, 'Unrecognized query operation');
});

test('table capabilities reject malformed or broadened query input', function (): void {
    $table = taskTable()->allowedRelations([AllowedRelation::named('tags')->choices('id', 'name')]);
    $tags = ['kind' => 'relation', 'field' => 'tags'];
    foreach ([
        ['kind' => 'relation', 'field' => 'comments', 'operator' => 'none'],
        ['kind' => 'relation', 'field' => 'list.tasks', 'operator' => 'none'],
        $tags + ['operator' => 'some', 'filter' => ['kind' => 'condition', 'field' => 'title', 'operator' => 'equals', 'value' => 'private']],
        $tags + ['operator' => 'some'], $tags + ['operator' => 'none', 'value' => null],
        $tags + ['operator' => 'has', 'value' => ['x']], $tags + ['operator' => 'any', 'value' => []],
        $tags + ['operator' => 'all', 'value' => [1]], $tags + ['operator' => 'all', 'value' => [null]],
        $tags + ['operator' => 'any', 'value' => array_fill(0, 101, 'x')],
        ['kind' => 'or', 'children' => [$tags + ['operator' => 'all', 'value' => array_map(strval(...), range(1, 100))]]],
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

    foreach ([
        ['done', 'list'],
        [['field' => 'done']],
        [['field' => 'done', 'direction' => 'sideways']],
        [['field' => 'secret', 'direction' => 'asc']],
        [['field' => 'done', 'direction' => 'asc', 'extra' => true]],
        [['field' => 'done', 'direction' => 'asc'], ['field' => 'done', 'direction' => 'desc']],
        [...$table->defaultGrouping, ['field' => 'done', 'direction' => 'asc']],
    ] as $invalid) {
        expect(fn (): array => $table->grouping($invalid))->toThrow(ValidationException::class);
    }

    expect($table->grouping(array_reverse($table->defaultGrouping)))->toBe(array_reverse($table->defaultGrouping));
    expect(fn (): array => $table->path([['field' => 'list', 'value' => null]], $table->defaultGrouping))->toThrow(ValidationException::class);
    expect(fn (): array => $table->ordering([['field' => 'secret', 'direction' => 'asc']]))->toThrow(ValidationException::class);
    expect(fn (): Table => Table::for(Task::class)->allowedFilters([AllowedFilter::number('title')]))->toThrow(InvalidArgumentException::class);
    expect(fn (): Table => Table::for(Task::class)->allowedFilters([AllowedFilter::exact('done')->nullable()]))->toThrow(InvalidArgumentException::class);
    expect(fn (): Table => Table::for(Task::class)->allowedGroups([AllowedGroup::belongsTo('tags')->label('name')]))->toThrow(InvalidArgumentException::class);
});

test('URL views normalize independent namespaces without changing mandatory membership', function (): void {
    $table = taskTable();
    $sort = [['field' => 'rating', 'direction' => 'desc'], ['field' => 'title', 'direction' => 'asc']];
    $groupBy = array_reverse($table->defaultGrouping);
    $encoded = TableURLState::encode($table, ['filter' => null, 'sort' => $sort, 'groupBy' => $groupBy, 'expanded' => ['g:[{"field":"list","value":null}]']]);
    $query = 'other=keep&table.people='.urlencode($encoded).'&table.companies='.urlencode('{"version":2,"state":{"sort":[]}}');
    $people = TableURLState::read($table, $query, 'table.people');
    $companies = TableURLState::read($table, $query, 'table.companies', ['groupBy' => []]);
    expect($people['sort'])->toBe($sort)
        ->and($people['groupBy'])->toBe($groupBy)
        ->and($people['expanded'])->toBe(['g:[{"field":"list","value":null}]'])
        ->and($companies['sort'])->toBe([])
        ->and($companies['groupBy'])->toBe([]);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    expect($table->sql($context, $people['filter'])->count())->toBe(3);
    $fallback = TableURLState::normalize($table, ['groupBy' => []]);
    foreach (['table.people=null', 'table.people='.urlencode($encoded).'&table.people=other', 'table.people='.urlencode('{"version":2,"state":{"tenant":"bob"}}'), 'table.people='.urlencode('{"version":1,"state":{"sort":[]}}'), 'table.people='.urlencode('{"version":2,"state":{"groupBy":["done"]}}')] as $invalid) {
        expect(TableURLState::read($table, $invalid, 'table.people', ['groupBy' => []]))->toBe($fallback);
    }
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
    $unsupported->base->has('tags');

    expect(fn (): Builder => $unsupported->sql($context))->toThrow(LogicException::class, 'portable SQL lowering');
});

test('table SQL compares UUID fields as replicated strings instead of coercing input', function (): void {
    $id = '00000000-0000-4000-8000-00000000abcd';
    DB::table('tasks')->insert(['id' => $id, 'user_id' => 'alice', 'title' => 'UUID row', 'done' => false]);
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $table = Table::for(Task::class)->visibleTo($alice)->allowedFilters([AllowedFilter::exact('id')]);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    foreach ([[$id, 1], [strtoupper($id), 0], ['not-a-uuid', 0]] as [$value, $expected]) {
        expect($table->sql($context, ['kind' => 'condition', 'field' => 'id', 'operator' => 'equals', 'value' => $value])->count())->toBe($expected);
    }
});
