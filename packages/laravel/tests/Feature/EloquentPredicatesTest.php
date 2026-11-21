<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use ZeroForLaravel\Attributes\ZeroPublic;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\OwnerTestCase;

uses(OwnerTestCase::class);

beforeEach(function (): void {
    /** @var OwnerTestCase $this */
    app()->register(SurveyorServiceProvider::class);
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    request()->setUserResolver(fn () => (new User)->setKeyType('string')->forceFill(['id' => 'alice']));
});

test('Eloquent array predicates preserve boolean groups and ignore an empty nested predicate', function (): void {
    $query = Task::zeroQuery()->withoutGlobalScopes()
        ->where(['title' => 'mine', 'list_id' => null])
        ->orWhere([['id', '<>', 'hidden'], ['done', '=', false]])
        ->orWhere([]);
    $where = $query->ast()['where'];
    expect(data_get($where, 'type'))->toBe('or')
        ->and(data_get($where, 'conditions.*.type'))->toBe(['and', 'simple', 'simple'])
        ->and(data_get($where, 'conditions.0.conditions.*.op'))->toBe(['=', 'IS'])
        ->and(data_get($where, 'conditions.1.op'))->toBe('!=');
});

test('empty and null-containing memberships obey SQL even when the column is nullable', function (): void {
    $base = Task::zeroQuery()->withoutGlobalScopes();
    expect(data_get((clone $base)->whereIn('list_id', [null, 'a'])->ast(), 'where.right.value'))->toBe(['a']);
    foreach ([[], [null], [null, 'a']] as $list) {
        $where = (clone $base)->whereNotIn('list_id', $list)->ast()['where'];
        expect(data_get($where, 'left.type'))->toBe('literal')
            ->and(data_get($where, 'right.value'))->toBe($list === [] ? 1 : 0);
    }
    expect(data_get($base->whereIn('list_id', [])->ast(), 'where.right.value'))->toBe(0);
});

test('generated factories and direct declarations agree on conditional groups, order and relationship refinements', function (): void {
    $definition = resolve(ControllerQueryCompiler::class)->compile(EloquentPredicateQueries::class, 'filtered')[0];
    $factory = eval('return '.$definition->php().';');
    if (! is_callable($factory)) {
        throw new LogicException('Expected generated factory.');
    }
    foreach ([true, false] as $flag) {
        $expected = (new EloquentPredicateQueries)->filtered(request(), $flag);
        $query = $factory(['flag' => $flag]);
        if (! $query instanceof ZeroQuery) {
            throw new LogicException('Expected query declaration.');
        }
        expect($query->constructionAst())->toBe($expected->constructionAst())
            ->and(data_get($query->constructionAst(), 'limit'))->toBe(4)
            ->and(data_get($query->constructionAst(), 'orderBy'))->toBe([['created_at', 'desc'], ['title', 'asc'], ['id', 'desc']]);
    }
    expect($definition->typescript())->toContain('eb.exists("comments"', '.related("comments"', 'eb.or(', '"NOT IN"', '"IS NOT"', 'args["flag"]', '1767225600000');
});

test('dotted negative paths negate the outer existence, and counts or uncovered paths cannot widen access', function (): void {
    $base = Task::zeroQuery();
    $outer = (clone $base)->doesntHave('list.tasks')->constructionAst();
    expect(data_get($outer, 'where.op'))->toBe('NOT EXISTS')
        ->and(data_get($outer, 'where.related.subquery.where.op'))->toBe('EXISTS');
    $leaf = (clone $base)->has('list.tasks', '=', 0)->constructionAst();
    expect(data_get($leaf, 'where.op'))->toBe('EXISTS')
        ->and(data_get($leaf, 'where.related.subquery.where.op'))->toBe('NOT EXISTS');
    expect(fn () => $base->has('comments', '>=', 2))->toThrow(InvalidArgumentException::class, 'existence or absence');
    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(EloquentPredicateQueries::class, 'counted'))->toThrow(LogicException::class, 'existence or absence');
    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(EloquentPredicateQueries::class, 'uncovered'))->toThrow(LogicException::class, 'visibleTo');
});

test('relationship constraints intersect the entire callback, not just its first OR term', function (): void {
    app()->instance(ModelRegistry::class, new ModelRegistry([PredicateTask::class, Comment::class]));
    $query = (new EloquentPredicateQueries)->constrained(request());
    $child = data_get($query->constructionAst(), 'where.related.subquery.where');
    expect(data_get($child, 'type'))->toBe('and')
        ->and(data_get($child, 'conditions.0.op'))->toBe('IN')
        ->and(data_get($child, 'conditions.1.type'))->toBe('or');
    $factory = resolve(ControllerQueryCompiler::class)->compile(EloquentPredicateQueries::class, 'constrained')[0];
    expect($factory->typescript())->toContain('eb.and(eb.cmp("body", "IN", ["published"]), eb.or(');
});

#[ZeroPublic]
final class PredicateTask extends Model
{
    use HasZeroQueries;

    protected $table = 'tasks';

    /** @return HasMany<Comment, $this> */
    public function publishedComments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id')->whereIn('body', ['published']);
    }
}

final class EloquentPredicateQueries
{
    public function constrained(Request $request): ZeroQuery
    {
        return PredicateTask::zeroQuery()->whereHas('publishedComments', fn ($q) => $q->visibleTo($request->user())->where('id', 'a')->orWhere('id', 'b'))->get();
    }

    public function filtered(Request $request, bool $flag): ZeroQuery
    {
        return Task::zeroQuery()->visibleTo($request->user())
            ->where([['title', 'not like', 'secret%'], ['done', '=', false]])
            ->whereIn('completed_at', ['2026-01-01', null])
            ->when($flag, fn ($q) => $q->whereNotBetween('title', ['b', 'h']), fn ($q) => $q->orWhereNotIn('list_id', ['blocked']))
            ->unless($flag, fn ($q, bool $value) => $q->where(['done' => $value]))
            ->orWhereNotNull('list_id')
            ->withWhereHas('comments', fn ($q) => $q->visibleTo($request->user())->whereKey(['a', 'b']), '>', 0)
            ->latest()->oldest('title')->orderByDesc('id')->take(4)->get();
    }

    public function counted(Request $request): ZeroQuery
    {
        return Task::zeroQuery()->visibleTo($request->user())->has('comments', '=', 1)->get();
    }

    public function uncovered(Request $request): ZeroQuery
    {
        return Task::zeroQuery()->visibleTo($request->user())->orWhereRelation('list.tasks', 'done', false)->get();
    }
}
