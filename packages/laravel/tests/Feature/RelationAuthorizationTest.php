<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use PhpParser\ParserFactory;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

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
