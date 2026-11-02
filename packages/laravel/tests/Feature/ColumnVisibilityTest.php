<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Assert;
use ZeroForLaravel\Attributes\ZeroColumns;
use ZeroForLaravel\Attributes\ZeroExclude;
use ZeroForLaravel\Attributes\ZeroIncludeColumns;
use ZeroForLaravel\Attributes\ZeroScopedBy;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Tests\Fixtures\OwnedScope;
use ZeroForLaravel\Tests\Fixtures\OwnerReadScope;
use ZeroForLaravel\Tests\Fixtures\VisibleTask;
use ZeroForLaravel\Tests\TestCase;

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
    'relationship key' => [fn (): Model => new MissingVisibleRelationKey, 'list_id'],
]);

test('scope predicates cannot expose omitted visible columns', function (): void {
    $registry = new ModelRegistry([MissingVisibleAuthorization::class]);
    $model = $registry->get('MissingVisibleAuthorization');
    expect($model->columns)->not->toHaveKey('user_id');
    $query = new ZeroQuery($model, $registry);
    expect(fn (): array => $query->ast())->toThrow(InvalidArgumentException::class, 'MissingVisibleAuthorization.user_id');
    expect(fn (): string => (new TypeScriptGenerator)->generate($registry))
        ->toThrow(LogicException::class, 'Query fields and relationships must name a registered column or relationship.');
});

test('creation defaults cannot expose omitted visible columns', function (): void {
    $registry = new ModelRegistry([MissingVisibleFill::class]);
    $model = $registry->get('MissingVisibleFill');
    expect($model->columns)->not->toHaveKey('user_id');
    $query = new ZeroQuery($model, $registry);
    try {
        $query->ast();
        Assert::fail('Expected creation defaults to reject an omitted column.');
    } catch (ValidationException $validationException) {
        expect(array_keys($validationException->errors()))->toContain('attributes.user_id');
    }

    expect(fn (): string => (new TypeScriptGenerator)->generate($registry))
        ->toThrow(LogicException::class, 'Creation defaults require an exposed, fillable, non-metadata column.');
});

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

#[ZeroScopedBy([OwnerReadScope::class])]
class MissingVisibleAuthorization extends VisibleTask {}

#[ZeroScopedBy([OwnedScope::class])]
class MissingVisibleFill extends VisibleTask {}

class MissingVisibleRelationKey extends VisibleTask
{
    /** @return BelongsTo<MissingVisibleRelationKey, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(self::class, 'list_id');
    }
}
