<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;
use ZeroForLaravel\Definition\ZeroDefinition;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

class SharedTask extends Model
{
    protected $table = 'tasks';

    protected $hidden = ['secret'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorizeRelation('access');
    }

    /** @return BelongsTo<Tag, $this> */
    public function access(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'list_id');
    }
}

test('relation authorization requires a related row visible to the trusted user', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry([SharedTask::class, Tag::class]);
    $ast = (new QueryCompiler($registry))->compileDeclaration(new Query($registry->get('SharedTask'), $registry), new ZeroContext(['user_id' => 'alice']));
    $this->assertSame('correlatedSubquery', data_get($ast, 'where.type'));
    $this->assertSame('EXISTS', data_get($ast, 'where.op'));
    $this->assertSame('tags', data_get($ast, 'where.related.subquery.table'));
    $this->assertSame('alice', data_get($ast, 'where.related.subquery.where.right.value'));
    $this->assertSame(['list_id'], data_get($ast, 'where.related.correlation.parentField'));
    $this->assertSame(['access'], $registry->get('SharedTask')->toArray()['authorizationRelations']);
});

test('relation authorization fails closed when trusted context is missing', function (): void {
    $registry = new ModelRegistry([SharedTask::class, Tag::class]);
    (new QueryCompiler($registry))->compileDeclaration(new Query($registry->get('SharedTask'), $registry), new ZeroContext([]));
})->throws(AuthorizationException::class);

class JunctionAuthorizedTask extends Model
{
    protected $table = 'tasks';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorizeRelation('access');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function access(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag', 'task_id', 'tag_id');
    }
}

test('mandatory junction authorization checks target visibility without result bounds', function (): void {
    $registry = new ModelRegistry([JunctionAuthorizedTask::class, Tag::class]);
    $ast = (new QueryCompiler($registry))->compileDeclaration(new Query($registry->get('JunctionAuthorizedTask'), $registry), new ZeroContext(['user_id' => 'alice']));
    expect(data_get($ast, 'where.related.subquery.table'))->toBe('task_tag');
    expect(data_get($ast, 'where.related.subquery.where.op'))->toBe('EXISTS');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery.table'))->toBe('tags');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery.where.right.value'))->toBe('alice');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery'))->not->toHaveKey('limit');
    expect(data_get($ast, 'where.related.subquery.where.related.subquery'))->not->toHaveKey('orderBy');
});

class CyclicTask extends Model
{
    protected $table = 'tasks';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorizeRelation('parent');
    }

    /** @return BelongsTo<CyclicTask, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'list_id');
    }
}

test('cyclic authorization relations fail during generation', function (): void {
    new ModelRegistry([CyclicTask::class]);
})->throws(LogicException::class, 'Cyclic authorization');
