<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

class SharedTask extends Model
{
    use HasZeroQueries;

    protected $table = 'tasks';

    protected $hidden = ['secret'];

    /** @return BelongsTo<Tag, $this> */
    public function access(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'list_id');
    }

    protected static function booted(): void
    {
        static::addZeroGlobalScope('access', function (ZeroQuery $query): void {
            $query->whereHas('access');
        });
    }
}

test('relation authorization requires a related row visible to the trusted user', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry([SharedTask::class, Tag::class]);
    $ast = (new QueryCompiler($registry))->compileDeclaration(new ZeroQuery($registry->get('SharedTask'), $registry), new ResolvedContext(['user_id' => 'alice'], 'alice'));
    $this->assertSame('correlatedSubquery', data_get($ast, 'where.type'));
    $this->assertSame('EXISTS', data_get($ast, 'where.op'));
    $this->assertSame('tags', data_get($ast, 'where.related.subquery.table'));
    $this->assertSame('alice', data_get($ast, 'where.related.subquery.where.right.value'));
    $this->assertSame(['list_id'], data_get($ast, 'where.related.correlation.parentField'));
    $this->assertArrayNotHasKey('related', $ast);
    $this->assertArrayHasKey('access', $registry->get('SharedTask')->relations);
});

test('relation authorization fails closed when trusted context is missing', function (): void {
    $registry = new ModelRegistry([SharedTask::class, Tag::class]);
    (new QueryCompiler($registry))->compileDeclaration(new ZeroQuery($registry->get('SharedTask'), $registry), new ResolvedContext([]));
})->throws(AuthorizationException::class);

class JunctionAuthorizedTask extends Model
{
    use HasZeroQueries;

    protected $table = 'tasks';

    /** @return BelongsToMany<Tag, $this> */
    public function access(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag', 'task_id', 'tag_id');
    }

    protected static function booted(): void
    {
        static::addZeroGlobalScope('access', function (ZeroQuery $query): void {
            $query->whereHas('access');
        });
    }
}

test('mandatory junction authorization checks target visibility without result bounds', function (): void {
    $registry = new ModelRegistry([JunctionAuthorizedTask::class, Tag::class]);
    $ast = (new QueryCompiler($registry))->compileDeclaration(new ZeroQuery($registry->get('JunctionAuthorizedTask'), $registry), new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect(data_get($ast, 'where.related.subquery.table'))->toBe('task_tag');
    expect(data_get($ast, 'where.related.subquery.where.op'))->toBe('EXISTS');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery.table'))->toBe('tags');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery.where.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery'))->not->toHaveKey('limit');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery'))->not->toHaveKey('orderBy');
});

class CyclicTask extends Model
{
    use HasZeroQueries;

    protected $table = 'tasks';

    /** @return BelongsTo<CyclicTask, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'list_id');
    }

    protected static function booted(): void
    {
        static::addZeroGlobalScope('parent', function (ZeroQuery $query): void {
            $query->whereHas('parent');
        });
    }
}

test('cyclic authorization relations fail during generation', function (): void {
    (new TypeScriptGenerator)->generate(new ModelRegistry([CyclicTask::class]));
})->throws(LogicException::class, 'Recursive Zero scope');

test('cyclic authorization relations fail during runtime expansion', function (): void {
    $registry = new ModelRegistry([CyclicTask::class]);
    (new QueryCompiler($registry))->compileDeclaration(new ZeroQuery($registry->get('CyclicTask'), $registry), new ResolvedContext(['user_id' => 'alice'], 'alice'));
})->throws(LogicException::class, 'Recursive Zero scope');
