<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use PhpParser\ParserFactory;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Attributes\ZeroScope;
use ZeroForLaravel\Attributes\ZeroScopedBy;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRuntime;
use ZeroForLaravel\Mutation\Mutation;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Scopes\ZeroGlobalScope;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

test('local scopes mutate aliases, group boolean branches and keep nested defaults off the root', function (): void {
    $registry = new ModelRegistry([ScopedTodo::class]);
    app()->instance(ModelRegistry::class, $registry);
    $query = ScopedTodo::completed();
    $alias = $query;
    $branch = clone $query;
    $alias->__call('named', ['local'])->orWhere->__call('named', ['other']);
    $branch->where('title', 'clone');
    expect($alias)->toBe($query);
    expect($query->pendingAttributes())->toMatchArray(['title' => 'local', 'done' => true]);
    expect($branch->pendingAttributes())->toMatchArray(['title' => 'global', 'done' => true]);

    $compiler = new QueryCompiler($registry);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $ast = $compiler->compileDeclaration($query, $context);
    expect($compiler->compileDeclaration($query, $context))->toEqual($ast);
    expect($compiler->compileDeclaration($branch, $context))->not->toEqual($ast);
    // Global owner remains an AND outside the root OR group.
    expect(data_get($ast, 'where.type'))->toBe('and');
    expect(data_get($ast, 'where.conditions.0.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.conditions.2.type'))->toBe('or');

    $unscoped = ScopedTodo::zeroQuery()->withoutGlobalScopes()->where('title', 'first')->orWhere('title', 'second')->where('done', true)->ast();
    expect(data_get($unscoped, 'where.type'))->toBe('or');
    expect(data_get($unscoped, 'where.conditions.1.type'))->toBe('and');
});

test('creation defaults use global local explicit precedence and removal without changing updates or Eloquent', function (): void {
    $registry = new ModelRegistry([ScopedTodo::class]);
    app()->instance(ModelRegistry::class, $registry);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $query = ScopedTodo::completed()->__call('named', ['local']);
    expect($query->withoutGlobalScope('named')->pendingAttributes())->toMatchArray(['title' => 'local', 'done' => true]);
    expect(ScopedTodo::zeroQuery()->withoutGlobalScope(OwnershipScope::class)->pendingAttributes())->toBe(['title' => 'global']);
    $registry->connection()->transaction(function () use ($registry, $context, $query): void {
        $mutation = new Mutation($registry, ['ids' => ['create' => '12345678-1234-4567-8123-123456789012'], 'timestamp' => 1700000000000], $context);
        $created = $mutation->create(ScopedTodo::class, ['title' => 'explicit'], 'create', $query->pendingAttributes());
        expect($created->fresh()?->only('user_id', 'title', 'done'))->toBe(['user_id' => 'alice', 'title' => 'explicit', 'done' => true]);
        $mutation->update($created, ['user_id' => 'bob']);
        expect($created->fresh()?->getAttribute('user_id'))->toBe('bob');
    });
    $ordinary = ScopedTodo::create(['user_id' => 'bob', 'title' => 'ordinary']);
    expect($ordinary->getAttribute('user_id'))->toBe('bob')->and($ordinary->fresh()?->getAttribute('done'))->toBeFalse();
    expect(ContextReference::resolve(ContextReference::normalize(['nested' => [ZeroContext::USER_ID], 'literal' => ZeroContext::USER_ID->value]), $context))
        ->toBe(['nested' => ['alice'], 'literal' => 'user_id']);
});

test('controller lowering preserves mutation aliases cloned branches and scope-aware creation', function (): void {
    $registry = new ModelRegistry([ScopedTodo::class]);
    app()->instance(ModelRegistry::class, $registry);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $compiler = new ControllerQueryCompiler($parser, $registry);
    $queries = $compiler->compile(ScopedController::class, 'index');
    foreach ([false, true] as $branch) {
        $actual = [];
        foreach ($queries as $query) {
            $factory = eval('return '.$query->php().';');
            if (! is_callable($factory)) {
                throw new LogicException('Expected a generated query factory.');
            }

            $result = $factory(['branch' => $branch]);
            if (! $result instanceof ZeroQuery) {
                throw new LogicException('Expected a generated ZeroQuery.');
            }

            $actual[$query->prop] = $result->ast();
            expect($query->typescript())->toContain('ctx["user_id"]', 'zql["tasks"]');
        }

        $root = ScopedTodo::completed();
        if ($branch) {
            $root->withAttributes(['title' => 'yes']);
        } else {
            $root->withAttributes(['title' => 'no']);
        }

        $clone = clone $root;
        $clone->where('title', 'clone');
        $root->where('done', true);
        $grouped = (clone $root)->where(fn (ZeroQuery $query): ZeroQuery => $query->where('title', $branch ? 'group-a' : 'group-b'));
        expect($actual)->toEqual(['root' => $root->ast(), 'alias' => $root->ast(), 'clone' => $clone->ast(), 'grouped' => $grouped->ast()]);
    }

    $program = (new ControllerMutationCompiler($parser, $registry))->compile(ScopedController::class, 'store');
    $registry->connection()->transaction(function () use ($program, $registry): void {
        $mutation = new Mutation($registry, ['ids' => ['s0' => '12345678-1234-4567-8123-123456789013'], 'timestamp' => 1700000000000], new ResolvedContext(['user_id' => 'alice'], 'alice'));
        (new ControllerMutationRuntime($mutation))->run($program->operations, []);
        expect(ScopedTodo::findOrFail('12345678-1234-4567-8123-123456789013')->only('title', 'done', 'user_id'))->toBe(['title' => 'explicit', 'done' => true, 'user_id' => 'alice']);
    });
});

test('child attribute defaults override inherited registrations and removal restores the parent default', function (): void {
    ParentDefaultTodo::addZeroGlobalScope('parent-title', function (ZeroQuery $query): void {
        $query->withAttributes(['title' => 'parent registration'], asConditions: false);
    });
    $registry = new ModelRegistry([InheritedDefaultTodo::class]);
    app()->instance(ModelRegistry::class, $registry);
    $registry->connection()->transaction(function () use ($registry): void {
        $mutation = new Mutation($registry, ['ids' => [
            'child' => '12345678-1234-4567-8123-123456789014',
            'parent' => '12345678-1234-4567-8123-123456789015',
        ], 'timestamp' => 1700000000000], new ResolvedContext(['user_id' => 'alice'], 'alice'));
        $child = $mutation->create(InheritedDefaultTodo::class, ['user_id' => 'alice'], 'child');
        $parent = $mutation->create(InheritedDefaultTodo::class, ['user_id' => 'alice'], 'parent', InheritedDefaultTodo::zeroQuery()->withoutGlobalScope(ChildDefaultScope::class)->pendingAttributes());
        expect($child->fresh()?->getAttribute('title'))->toBe('child attribute');
        expect($parent->fresh()?->getAttribute('title'))->toBe('parent registration');
    });
});

class OwnershipScope implements ZeroGlobalScope
{
    public function apply(ZeroQuery $query, Model $model): void
    {
        $query->withAttributes(['user_id' => ZeroContext::USER_ID, 'done' => false]);
    }
}

/** @method static ZeroQuery completed()
 * @method static ZeroQuery named(string $name)
 */
#[ZeroScopedBy([OwnershipScope::class])]
class ScopedTodo extends Model
{
    use HasUuids, HasZeroQueries;

    protected $table = 'tasks';

    protected $fillable = ['user_id', 'title', 'done'];

    protected $hidden = ['secret'];

    protected static function booted(): void
    {
        static::addZeroGlobalScope('named', function (ZeroQuery $query): void {
            $query->withAttributes(['title' => 'global'], asConditions: false);
        });
    }

    protected function casts(): array
    {
        return ['done' => 'boolean'];
    }

    #[ZeroScope]
    protected function completed(ZeroQuery $query): void
    {
        $query->withAttributes(['done' => true]);
    }

    #[ZeroScope]
    protected function named(ZeroQuery $query, string $name): ZeroQuery
    {
        return $query->withAttributes(['title' => $name]);
    }
}

class ScopedController
{
    public function index(bool $branch): Response
    {
        $root = ScopedTodo::completed();
        $alias = $root;
        if ($branch) {
            $alias->withAttributes(['title' => 'yes']);
        } else {
            $root->withAttributes(['title' => 'no']);
        }

        $clone = clone $alias;
        $clone->where('title', 'clone');

        $alias->where('done', true);

        $grouped = (clone $root)->where(function (ZeroQuery $query) use ($branch): void {
            if ($branch) {
                $query->where('title', 'group-a');
            } else {
                $query->where('title', 'group-b');
            }
        });

        return Inertia::render('scopes', ['root' => $root, 'alias' => $alias, 'clone' => $clone, 'grouped' => $grouped]);
    }

    #[ZeroMutation]
    public function store(): void
    {
        ScopedTodo::named('local')->withAttributes(['done' => true])->withoutGlobalScope('named')->create(['title' => 'explicit']);
    }
}

class ParentDefaultTodo extends Model
{
    use HasUuids, HasZeroQueries;

    protected $table = 'tasks';

    protected $fillable = ['title', 'user_id'];

    protected $hidden = ['secret'];
}

#[ZeroScopedBy([ChildDefaultScope::class])]
class InheritedDefaultTodo extends ParentDefaultTodo {}

class ChildDefaultScope implements ZeroGlobalScope
{
    public function apply(ZeroQuery $query, Model $model): void
    {
        $query->withAttributes(['title' => 'child attribute'], asConditions: false);
    }
}
