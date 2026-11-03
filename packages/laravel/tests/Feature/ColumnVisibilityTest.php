<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use ZeroForLaravel\Attributes\ZeroColumns;
use ZeroForLaravel\Attributes\ZeroExclude;
use ZeroForLaravel\Attributes\ZeroIncludeColumns;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\Fixtures\VisibleTask;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config(['zero.public_models' => [VisibleTask::class, HiddenVisibleTask::class, ExcludedVisibleTask::class,
        ExplicitVisibleTask::class, IncludedVisibleTask::class, IncludedExcludedVisibleTask::class,
        BoundedVisibleTask::class, VirtualVisibleTask::class, EmptyVisibleTask::class,
        MissingVisibleKey::class, MissingVisibleTimestamp::class, MissingVisibleDeletedAt::class,
        MissingVisibleFill::class, MissingVisibleRelationKey::class],
        'zero.mutation_context.'.MissingVisibleFill::class => ['user_id' => ContextField::UserId]]);
});

test('visible columns restrict metadata, writable inputs and the generated client schema', function (): void {
    $registry = new ModelRegistry([VisibleTask::class]);
    $model = $registry->get('VisibleTask');

    expect(array_keys($model->columns))->toBe(['id', 'title']);
    expect($model->writable)->toBe(['title']);

    $output = (new TypeScriptGenerator)->generate($registry);
    expect($output)->toContain('title');
    expect($output)->not->toContain('secret');
});

test('column visibility controls have explicit precedence', function (Model $model, array $columns): void {
    $registry = new ModelRegistry([$model::class]);
    expect(array_keys(array_values($registry->all())[0]->columns))->toBe($columns);
})->with([
    'hidden excludes visible columns' => [fn (): Model => new HiddenVisibleTask, ['id']],
    'ZeroExclude excludes visible columns' => [fn (): Model => new ExcludedVisibleTask, ['id']],
    'ZeroColumns replaces the default visible allowlist' => [fn (): Model => new ExplicitVisibleTask, ['id', 'secret']],
    'ZeroIncludeColumns opts columns outside visible back in' => [fn (): Model => new IncludedVisibleTask, ['id', 'title', 'secret']],
    'ZeroIncludeColumns overrides hidden and ZeroExclude' => [fn (): Model => new IncludedExcludedVisibleTask, ['id', 'title', 'secret']],
    'ZeroColumns remains an upper bound on explicit includes' => [fn (): Model => new BoundedVisibleTask, ['id']],
    'visible may also name accessors and relations' => [fn (): Model => new VirtualVisibleTask, ['id', 'title']],
]);

test('an empty visible list preserves default column discovery', function (): void {
    $model = (new ModelRegistry([EmptyVisibleTask::class]))->get('EmptyVisibleTask');
    expect(array_keys($model->columns))->toContain('id', 'title', 'secret');
});

test('required columns omitted from visible fail closed', function (Model $model, string $column): void {
    expect(fn (): ModelRegistry => new ModelRegistry([$model::class]))->toThrow(LogicException::class, "required column {$column}");
})->with([
    'primary key' => [fn (): Model => new MissingVisibleKey, 'id'],
    'timestamps' => [fn (): Model => new MissingVisibleTimestamp, 'created_at'],
    'soft delete timestamp' => [fn (): Model => new MissingVisibleDeletedAt, 'deleted_at'],
    'server fill' => [fn (): Model => new MissingVisibleFill, 'user_id'],
]);

test('required relationship keys are checked when the relationship is collected', function (): void {
    $registry = new ModelRegistry([MissingVisibleRelationKey::class]);
    $registry->relation($registry->get('MissingVisibleRelationKey'), 'list');
})->throws(LogicException::class, 'required column list_id');

test('private ownership facts stay out of browser columns while policy visibility still uses them', function (): void {
    \ZeroForLaravel\Tests\Fixtures\OwnerPermissions::configure([ReadOnlyOwnerTask::class]);
    $registry = new ModelRegistry([ReadOnlyOwnerTask::class]);
    $model = $registry->get('ReadOnlyOwnerTask');
    expect($model->columns)->not->toHaveKeys(['user_id', 'secret']);
    $source = (new TypeScriptGenerator)->generate($registry);
    expect($source)->not->toContain('user_id', 'owner_id', 'permission_index');
    $actor = (new \Illuminate\Foundation\Auth\User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new \ZeroForLaravel\Query\Query($model, $registry))->visibleTo($actor);
    $ast = (new \ZeroForLaravel\Query\QueryCompiler($registry))->compileDeclaration($query, new \ZeroForLaravel\ZeroContext(['user_id' => 'alice']));
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('a_owner_id', 'permission_index');
});

class ReadOnlyOwnerTask extends \ZeroForLaravel\Tests\Fixtures\Task
{
    protected $hidden = ['secret', 'user_id'];
}

class HiddenVisibleTask extends VisibleTask
{
    protected $hidden = ['title'];
}

#[ZeroExclude(['title'])]
class ExcludedVisibleTask extends VisibleTask {}

#[ZeroColumns(['id', 'secret'])]
class ExplicitVisibleTask extends VisibleTask {}

#[ZeroIncludeColumns(['secret'])]
class IncludedVisibleTask extends VisibleTask {}

#[ZeroExclude(['title', 'secret'])]
#[ZeroIncludeColumns(['title', 'secret'])]
class IncludedExcludedVisibleTask extends VisibleTask
{
    protected $hidden = ['title', 'secret'];
}

#[ZeroColumns(['id'])]
#[ZeroIncludeColumns(['title', 'secret'])]
class BoundedVisibleTask extends VisibleTask {}

class VirtualVisibleTask extends VisibleTask
{
    protected $visible = ['id', 'title', 'display_title', 'list'];
}

class EmptyVisibleTask extends VisibleTask
{
    protected $visible = [];
}

class MissingVisibleKey extends VisibleTask
{
    protected $visible = ['title'];
}

class MissingVisibleTimestamp extends VisibleTask
{
    public $timestamps = true;
}

class MissingVisibleDeletedAt extends VisibleTask
{
    use SoftDeletes;
}

class MissingVisibleFill extends VisibleTask {}

class MissingVisibleRelationKey extends VisibleTask
{
    /** @return BelongsTo<MissingVisibleRelationKey, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(self::class, 'list_id');
    }
}
