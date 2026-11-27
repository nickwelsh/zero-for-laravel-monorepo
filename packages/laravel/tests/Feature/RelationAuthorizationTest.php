<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use LogicException;
use PhpParser\ParserFactory;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\TableCompiler;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedRelation;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableURLState;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\PredicateTask;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\Fixtures\TaskReportTable;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

test('table capabilities describe only declared paths and typed public filters', function (): void {
    /** @var TestCase $this */
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    $table = Table::for(TaskList::class)->allowedRelations([
        AllowedRelation::named('tasks')->lookup('title', 3)->allowedRelations([
            AllowedRelation::named('tags')->label('Categories')->choices('id', 'name', 'eligible', 'selected')
                ->allowedFilters([AllowedFilter::exact('caption', 'name')]),
        ]),
    ]);

    $caps = json_decode(json_encode($table->relationCapabilities(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($table->relations))->toBe(['tasks']);
    expect(data_get($caps, 'tasks.lookup'))->toBe(['field' => 'title', 'limit' => 3]);
    expect(data_get($caps, 'tasks.relations.tags.cardinality'))->toBe('many');
    expect(data_get($caps, 'tasks.relations.tags.label'))->toBe('Categories');
    expect(data_get($caps, 'tasks.relations.tags.filters.caption.field'))->toBe('name');
    expect(data_get($caps, 'tasks.relations.tags.keyColumn.type'))->toBe('string');
    expect(data_get($caps, 'tasks.relations.tags.choices'))->toBe(['value' => 'id', 'label' => 'name', 'list' => 'eligible', 'resolve' => 'selected']);
});

test('a nested table rule compiles to one visible record at each hop without a preview limit', function (): void {
    /** @var TestCase $this */
    app()->instance(ModelRegistry::class, $registry = new ModelRegistry($this->models()));
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $table = Table::for(TaskList::class)->visibleTo($alice)->allowedRelations([
        AllowedRelation::named('tasks')->lookup('title', 3)->allowedFilters([AllowedFilter::exact('complete', 'done')])
            ->allowedRelations([AllowedRelation::named('tags')->choices('id', 'name')]),
    ]);
    $filter = ['kind' => 'relation', 'field' => 'tasks', 'operator' => 'some', 'filter' => ['kind' => 'and', 'children' => [
        ['kind' => 'condition', 'field' => 'complete', 'operator' => 'equals', 'value' => true],
        ['kind' => 'relation', 'field' => 'tags', 'operator' => 'has', 'value' => 'tag-a'],
    ]]];
    $expected = (new ZeroQuery($registry->get('TaskList'), $registry))->visibleTo($alice)
        ->whereHas('tasks', fn (ZeroQuery $tasks): ZeroQuery => $tasks->visibleTo($alice)->where('done', true)
            ->whereHas('tags', fn (ZeroQuery $tags): ZeroQuery => $tags->visibleTo($alice)->where('id', 'tag-a')))
        ->with('tasks', fn (ZeroQuery $tasks): ZeroQuery => $tasks->visibleTo($alice)->orderBy('id')->limit(4))->orderBy('id');
    $compiler = new QueryCompiler($registry);
    expect($compiler->compileDeclaration($table->rows($filter), $context))->toEqual($compiler->compileDeclaration($expected, $context));
});

test('relation operator permissions are independent of a visible lookup and are enforced on saved and executable rules', function (): void {
    /** @var TestCase $this */
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $table = Table::for(Task::class)->visibleTo($alice)->allowedRelations([
        AllowedRelation::named('comments')->lookup('body', 2),
        AllowedRelation::named('tags')->operators(['has'])->choices('id', 'name'),
        AllowedRelation::named('list')->allowedFilters([AllowedFilter::exact('name')])->operators([]),
    ]);
    $caps = $table->relationCapabilities();
    expect($caps['comments']['operators'])->toBe([]);
    expect($caps['tags']['operators'])->toBe(['has']);
    expect($caps['list']['operators'])->toBe([]);
    expect(fn (): AllowedRelation => AllowedRelation::named('tags')->operators(['none']))->toThrow(InvalidArgumentException::class);

    $allowed = ['kind' => 'relation', 'field' => 'tags', 'operator' => 'has', 'value' => 'tag-a'];
    expect(TableURLState::normalize($table, ['filter' => $allowed])['filter'])->toBe($allowed);
    $ast = $table->rows($allowed)->ast();
    expect(data_get($ast, 'related.0.subquery.table'))->toBe('comments');
    foreach (['comments', 'tags', 'list'] as $field) {
        $forbidden = ['kind' => 'relation', 'field' => $field, 'operator' => 'any', 'value' => ['tag-a']];
        expect(fn (): array => TableURLState::normalize($table, ['filter' => $forbidden]))->toThrow(ValidationException::class);
        expect(fn (): ZeroQuery => $table->rows($forbidden))->toThrow(ValidationException::class);
    }
});

test('a junction preview bounds eligible links rather than truncating links before visibility', function (): void {
    /** @var TestCase $this */
    app()->instance(ModelRegistry::class, $registry = new ModelRegistry($this->models()));
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $table = Table::for(Task::class)->visibleTo($alice)->allowedRelations([AllowedRelation::named('tags')->lookup('name', 3)]);
    $compiled = (new QueryCompiler($registry))->compileDeclaration($table->rows(), new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect(data_get($compiled, 'related.0.subquery.table'))->toBe('task_tag');
    expect(data_get($compiled, 'related.0.subquery.limit'))->toBe(4);
    expect(json_encode(data_get($compiled, 'related.0.subquery.where'), JSON_THROW_ON_ERROR))->toContain('tags', 'permission_index', 'user:alice');
    expect(json_encode(data_get($compiled, 'related.0.subquery.related'), JSON_THROW_ON_ERROR))->toContain('tags', 'permission_index', 'user:alice');
});

test('URL state round-trips protected relation rules on an unbound compiled table', function (): void {
    /** @var TestCase $this */
    config(['zero.route_prefix' => 'zero']);
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    $table = resolve(TableCompiler::class)->compile('task-report', TaskReportTable::class)->table;
    $rule = ['kind' => 'and', 'children' => [
        ['kind' => 'relation', 'field' => 'tags', 'operator' => 'all', 'value' => ['a', 'b']],
        ['kind' => 'relation', 'field' => 'list', 'operator' => 'some', 'filter' => ['kind' => 'condition', 'field' => 'name', 'operator' => 'equals', 'value' => 'Work']],
    ]];
    $encoded = TableURLState::encode($table, ['filter' => $rule]);
    $restored = TableURLState::read($table, 'report='.urlencode($encoded), 'report');
    expect(TableURLState::encode($table, $restored))->toBe($encoded);
    expect(json_encode($restored['filter'], JSON_THROW_ON_ERROR))->toBe(json_encode($rule, JSON_THROW_ON_ERROR));

    $invalid = str_replace('"a","b"', '"a",false', $encoded);
    expect(TableURLState::read($table, 'report='.urlencode($invalid), 'report'))->toBe(TableURLState::normalize($table));
    $remapped = Table::for(Task::class)->allowedRelations([
        AllowedRelation::named('tags')->choices('id', 'name'),
        AllowedRelation::named('list')->allowedFilters([AllowedFilter::text('name', 'user_id')]),
    ]);
    expect(TableURLState::read($remapped, 'report='.urlencode($encoded), 'report')['filter'])->toBeNull();
    expect(fn (): ZeroQuery => $table->rows($restored['filter']))->toThrow(AuthorizationException::class);
    expect(fn () => $table->sql(new ResolvedContext([], 'alice'), $restored['filter']))->toThrow(AuthorizationException::class);
});

test('public-root table definitions bind protected descendants to the current actor', function (): void {
    config(['zero.route_prefix' => 'zero']);
    app()->instance(ModelRegistry::class, $registry = new ModelRegistry([PredicateTask::class, Comment::class]));
    $definition = resolve(TableCompiler::class)->compile('public-comments', PublicCommentTable::class);
    foreach (['alice', 'bob'] as $id) {
        $actor = (new User)->setKeyType('string')->forceFill(['id' => $id]);
        $query = $definition->bind($actor)->rows(['kind' => 'relation', 'field' => 'comments', 'operator' => 'has', 'value' => 'comment-a']);
        $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext([], $id));
        expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('user:'.$id, 'published');
    }
});

test('table declarations fail before serving when a related scope would truncate membership', function (): void {
    /** @var TestCase $this */
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    Comment::addGlobalScope('latest-only', fn (Builder $comments) => $comments->limit(1));
    expect(fn (): Table => Table::for(Task::class)->allowedRelations([AllowedRelation::named('comments')]))->toThrow(LogicException::class, 'membership-only');
});

test('related private rows require their own explicit visibility declaration', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new ZeroQuery($registry->get('Task'), $registry))->visibleTo($alice)->with('list');
    (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
})->throws(LogicException::class, 'visibleTo');

test('junction predicates enforce private target visibility without introducing result bounds', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new ZeroQuery($registry->get('Task'), $registry))->visibleTo($alice)
        ->whereHas('tags', fn (ZeroQuery $tags): ZeroQuery => $tags->visibleTo($alice)->where('name', 'Reading'));
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
    $encoded = json_encode($ast, JSON_THROW_ON_ERROR);
    expect($encoded)->toContain('task_tag', 'tags', 'permission_index', 'Reading', 'user:alice');
    expect($encoded)->not->toContain('"limit"', '"orderBy"', '__visibility');
    expect($ast)->not->toHaveKey('related');
});

test('nested negative exists retains visibility on every private target', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new ZeroQuery($registry->get('Task'), $registry))->visibleTo($alice)
        ->whereDoesntHave('list', fn (ZeroQuery $list): ZeroQuery => $list->visibleTo($alice)
            ->whereHas('tasks', fn (ZeroQuery $tasks): ZeroQuery => $tasks->visibleTo($alice)
                ->whereHas('comments', fn (ZeroQuery $comments): ZeroQuery => $comments->visibleTo($alice)->where('body', 'hidden'))));
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
    $encoded = json_encode($ast, JSON_THROW_ON_ERROR);
    expect($encoded)->toContain('NOT EXISTS', 'hidden');
    // Root task, list, nested task and comment each receive their own resource projection.
    expect(substr_count($encoded, '"table":"permission_resources_'))->toBeGreaterThanOrEqual(4);
    expect($encoded)->not->toContain('__visibility');
});

test('generation covers private relation paths introduced by applied Eloquent globals', function (): void {
    /** @var TestCase $this */
    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), new ModelRegistry($this->models()));
    Task::addGlobalScope('list-membership', fn (Builder $tasks) => $tasks->whereHas('list'));
    expect(fn (): array => $compiler->compile(GlobalScopeReads::class, 'index'))->toThrow(LogicException::class, 'visibleTo');
    expect($compiler->compile(GlobalScopeReads::class, 'removed'))->toHaveCount(1);

    TaskList::addGlobalScope('current-actor', new VisibleToCurrentUser);
    expect($compiler->compile(GlobalScopeReads::class, 'index')[0]->typescript())->toContain('eb.exists("list"');

    TaskList::addGlobalScope('nested', function (Builder $lists): void {
        $lists->whereHas('tasks', fn (Builder $tasks) => $tasks->withoutGlobalScope('list-membership'));
    });
    // The root actor covers Task, not a new Task query opened by TaskList's global.
    expect(fn (): array => $compiler->compile(GlobalScopeReads::class, 'index'))->toThrow(LogicException::class, 'Task: protected query paths require visibleTo');

    TaskList::addGlobalScope('nested', fn (Builder $lists) => $lists->whereHas('tasks'));
    Task::addGlobalScope(VisibleToCurrentUser::class);
    expect(fn (): array => $compiler->compile(GlobalScopeReads::class, 'index'))->toThrow(LogicException::class, 'Recursive');

    TaskList::addGlobalScope('nested', function (Builder $lists): void {
        $lists->whereHas('tasks', fn (Builder $tasks) => $tasks->withoutGlobalScope('list-membership'));
    });
    expect($compiler->compile(GlobalScopeReads::class, 'index'))->toHaveCount(1);

    Task::addGlobalScope('list-membership', function (Builder $tasks): void {
        $tasks->whereHas('list', fn (Builder $lists) => $lists->withoutGlobalScope('current-actor'));
    });
    expect(fn (): array => $compiler->compile(GlobalScopeReads::class, 'index'))->toThrow(LogicException::class, 'visibleTo');

    Task::addGlobalScope('list-membership', function (Builder $tasks): void {
        $tasks->whereHas('list', /** @param Builder<TaskList> $lists */ fn (Builder $lists) => $lists->withoutGlobalScope('current-actor')->visibleTo(Auth::user()));
    });
    expect($compiler->compile(GlobalScopeReads::class, 'index'))->toHaveCount(1);
});

test('context scalar resolution is not a symbolic operand in an Inertia controller', function (): void {
    /** @var TestCase $this */
    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), new ModelRegistry($this->models()));
    expect(fn (): array => $compiler->compile(GlobalScopeReads::class, 'scalar'))->toThrow(LogicException::class, 'shared Eloquent scope');
});

final class GlobalScopeReads
{
    public function index(Request $request): Response
    {
        return Inertia::render('scopes', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->get()]);
    }

    public function removed(Request $request): Response
    {
        return Inertia::render('scopes', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->withoutGlobalScope('list-membership')->get()]);
    }

    public function scalar(Request $request): Response
    {
        return Inertia::render('scopes', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->where('user_id', ContextReference::current(ZeroContext::USER_ID))->get()]);
    }
}

final class PublicCommentTable
{
    public function definition(): Table
    {
        return Table::for(PredicateTask::class)->allowedRelations([AllowedRelation::named('comments')->choices('id', 'body')->operators(['has'])]);
    }
}
