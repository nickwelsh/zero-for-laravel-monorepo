<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use PHPUnit\Framework\Assert;
use ZeroForLaravel\Attributes\ZeroScope;
use ZeroForLaravel\Attributes\ZeroScopedBy;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Scopes\ZeroGlobalScope;
use ZeroForLaravel\Tests\Fixtures\OwnedScope;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

#[ZeroScopedBy([OwnedScope::class])]
class DefinedTask extends Model
{
    use HasZeroQueries;

    protected $table = 'tasks';

    protected $fillable = ['user_id', 'title'];

    protected $hidden = ['secret'];

    /** @return BelongsTo<ScopedList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ScopedList::class, 'list_id');
    }
}

class SharedListScope implements ZeroGlobalScope
{
    public function apply(ZeroQuery $query, Model $model): void
    {
        $query->where(fn (ZeroQuery $query): ZeroQuery => $query->where('user_id', ZeroContext::USER_ID)->orWhere('name', 'public'));
    }
}

#[ZeroScopedBy([SharedListScope::class])]
class ScopedList extends Model
{
    use HasZeroQueries;

    public $timestamps = false;

    protected $table = 'lists';
}

class ScopeRelationTask extends Model
{
    use HasZeroQueries;

    protected $table = 'tasks';

    /** @return BelongsTo<ScopedList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ScopedList::class, 'list_id');
    }

    protected static function booted(): void
    {
        static::addZeroGlobalScope('shared-list', function (ZeroQuery $query): void {
            $query->whereHas('list', fn (ZeroQuery $list): ZeroQuery => $list->where('name', 'shared'));
        });
    }
}

class LegacyDefinitionTask extends Model
{
    public static int $calls = 0;

    protected $table = 'tasks';

    public static function zero(): never
    {
        self::$calls++;
        throw new LogicException('Legacy definitions must never execute.');
    }
}

class InvalidLocalScopeTask extends DefinedTask
{
    #[ZeroScope]
    public function invalid(ZeroQuery $query): void
    {
        $query->where('title', 'private');
    }
}

test('legacy zero definitions are rejected before execution', function (): void {
    LegacyDefinitionTask::$calls = 0;
    expect(fn (): ModelRegistry => new ModelRegistry([LegacyDefinitionTask::class]))
        ->toThrow(LogicException::class, 'legacy zero()/ZeroDefinition is unsupported');
    expect(LegacyDefinitionTask::$calls)->toBe(0);
});

test('scopes own visibility and creation defaults without hiding fillable columns', function (): void {
    $registry = new ModelRegistry([DefinedTask::class, ScopedList::class]);
    $metadata = $registry->get('DefinedTask');
    expect($metadata->writable)->toBe(['user_id', 'title']);
    $query = new ZeroQuery($metadata, $registry);
    $owner = $query->pendingAttributes()['user_id'];
    Assert::assertInstanceOf(ContextReference::class, $owner);
    expect($owner->field)->toBe('user_id');
    expect($metadata->toArray())->not->toHaveKeys(['authorization', 'serverFill']);

    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect(data_get($ast, 'where.right.value'))->toBe('alice');
});

test('global scopes are statically generated and resolved afresh on nested rows per request', function (): void {
    $registry = new ModelRegistry([DefinedTask::class, ScopedList::class]);
    $source = (new TypeScriptGenerator)->generate($registry);
    expect($source)->not->toContain('publicReads', 'serverScopes');

    $query = (new ZeroQuery($registry->get('DefinedTask'), $registry))->with('list');
    $compiler = new QueryCompiler($registry);
    foreach (['alice', 'bob'] as $user) {
        $ast = $compiler->compileDeclaration($query, new ResolvedContext(['user_id' => $user], $user));
        expect(data_get($ast, 'where.right.value'))->toBe($user);
        expect(data_get($ast, 'related.0.subquery.where.type'))->toBe('or');
        expect(data_get($ast, 'related.0.subquery.where.conditions.0.right.value'))->toBe($user);
        expect(data_get($ast, 'related.0.subquery.where.conditions.1.right.value'))->toBe('public');
    }
});

test('scope relations keep target authorization without adding result relationships', function (): void {
    $registry = new ModelRegistry([ScopeRelationTask::class, ScopedList::class]);
    $compiler = new QueryCompiler($registry);
    $ast = $compiler->compileDeclaration(new ZeroQuery($registry->get('ScopeRelationTask'), $registry), new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect(data_get($ast, 'where.op'))->toBe('EXISTS');
    expect(data_get($ast, 'where.related.subquery.where.conditions.0.type'))->toBe('or');
    expect(data_get($ast, 'where.related.subquery.where.conditions.0.conditions.0.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.related.subquery.where.conditions.1.right.value'))->toBe('shared');
    expect($ast)->not->toHaveKey('related');
});

test('invalid local scope contracts cannot silently grant access', function (): void {
    $registry = new ModelRegistry([InvalidLocalScopeTask::class]);
    app()->instance(ModelRegistry::class, $registry);
    InvalidLocalScopeTask::zeroQuery()->__call('invalid', []);
})->throws(LogicException::class, 'nonpublic');

test('missing scope context is denied on included models', function (): void {
    $registry = new ModelRegistry([ScopeRelationTask::class, ScopedList::class]);
    (new QueryCompiler($registry))->compileDeclaration(new ZeroQuery($registry->get('ScopeRelationTask'), $registry), new ResolvedContext([]));
})->throws(AuthorizationException::class);

test('declaration existence predicates retain global scopes on their targets', function (): void {
    $registry = new ModelRegistry([DefinedTask::class, ScopedList::class]);
    $query = (new ZeroQuery($registry->get('DefinedTask'), $registry))->whereHas('list', fn (ZeroQuery $list): ZeroQuery => $list->where('id', 'list-a'));
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect(data_get($ast, 'where.conditions.0.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.conditions.1.related.subquery.where.conditions.0.type'))->toBe('or');
    expect(data_get($ast, 'where.conditions.1.related.subquery.where.conditions.0.conditions.0.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.conditions.1.related.subquery.where.conditions.1.right.value'))->toBe('list-a');
});
