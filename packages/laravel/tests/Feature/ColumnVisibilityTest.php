<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use LogicException;
use ZeroForLaravel\Attributes\ZeroColumns;
use ZeroForLaravel\Attributes\ZeroExclude;
use ZeroForLaravel\Attributes\ZeroIncludeColumns;
use ZeroForLaravel\Attributes\ZeroPublic;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;
use ZeroForLaravel\Tests\Fixtures\VisibleTask;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

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
]);

test('creation defaults cannot expose omitted visible columns', function (): void {
    $registry = new ModelRegistry([MissingVisibleFill::class]);
    $model = $registry->get('MissingVisibleFill');
    expect($model->columns)->not->toHaveKey('user_id');
    expect(fn (): array => (new ZeroQuery($model, $registry))->ast())->toThrow(ValidationException::class);
    expect(fn (): string => (new TypeScriptGenerator)->generate($registry))
        ->toThrow(LogicException::class, 'Creation defaults require an exposed, fillable, non-metadata column.');
});

test('required relationship keys are checked when the relationship is collected', function (): void {
    $registry = new ModelRegistry([MissingVisibleRelationKey::class]);
    $registry->relation($registry->get('MissingVisibleRelationKey'), 'list');
})->throws(LogicException::class, 'required column list_id');

test('private ownership facts stay out of browser columns while policy visibility still uses them', function (): void {
    OwnerPermissions::configure([ReadOnlyOwnerTask::class]);
    $registry = new ModelRegistry([ReadOnlyOwnerTask::class]);
    $model = $registry->get('ReadOnlyOwnerTask');
    expect($model->columns)->not->toHaveKeys(['user_id', 'secret']);
    $source = (new TypeScriptGenerator)->generate($registry);
    expect($source)->not->toContain('owner_id', 'permission_index');
    $actor = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $query = (new ZeroQuery($model, $registry))->visibleTo($actor);
    $ast = (new QueryCompiler($registry))->compileDeclaration($query, new ResolvedContext(['user_id' => 'alice'], 'alice'));
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('a_owner_id', 'permission_index');
});

class ReadOnlyOwnerTask extends Model
{
    use HasPolicyVisibility, HasZeroQueries, SoftDeletes;

    protected $table = 'tasks';

    protected $hidden = ['secret', 'user_id'];
}

#[ZeroPublic]
class HiddenVisibleTask extends VisibleTask
{
    protected $hidden = ['title'];
}

#[ZeroExclude(['title'])]
#[ZeroPublic]
class ExcludedVisibleTask extends VisibleTask {}

#[ZeroColumns(['id', 'secret'])]
#[ZeroPublic]
class ExplicitVisibleTask extends VisibleTask {}

#[ZeroIncludeColumns(['secret'])]
#[ZeroPublic]
class IncludedVisibleTask extends VisibleTask {}

#[ZeroExclude(['title', 'secret'])]
#[ZeroIncludeColumns(['title', 'secret'])]
#[ZeroPublic]
class IncludedExcludedVisibleTask extends VisibleTask
{
    protected $hidden = ['title', 'secret'];
}

#[ZeroColumns(['id'])]
#[ZeroIncludeColumns(['title', 'secret'])]
#[ZeroPublic]
class BoundedVisibleTask extends VisibleTask {}

#[ZeroPublic]
class VirtualVisibleTask extends VisibleTask
{
    protected $visible = ['id', 'title', 'display_title', 'list'];
}

#[ZeroPublic]
class EmptyVisibleTask extends VisibleTask
{
    protected $visible = [];
}

#[ZeroPublic]
class MissingVisibleKey extends VisibleTask
{
    protected $visible = ['title'];
}

#[ZeroPublic]
class MissingVisibleTimestamp extends VisibleTask
{
    public $timestamps = true;
}

#[ZeroPublic]
class MissingVisibleDeletedAt extends VisibleTask
{
    use SoftDeletes;
}

#[ZeroPublic]
class MissingVisibleFill extends VisibleTask
{
    use HasZeroQueries;

    protected static function booted(): void
    {
        static::addZeroGlobalScope('creator', function (ZeroQuery $query): void {
            $query->withAttributes(['user_id' => ZeroContext::USER_ID], asConditions: false);
        });
    }
}

#[ZeroPublic]
class MissingVisibleRelationKey extends VisibleTask
{
    /** @return BelongsTo<MissingVisibleRelationKey, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(self::class, 'list_id');
    }
}
