<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ZeroForLaravel\Attributes\ZeroExclude;
use ZeroForLaravel\Attributes\ZeroPublic;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\Mutation;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

#[ZeroPublic]
class ExternalTask extends Model
{
    protected $table = 'tasks';

    protected $fillable = ['user_id', 'title'];

    protected $hidden = ['secret'];

    /** @return BelongsTo<UnprotectedTask, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(UnprotectedTask::class, 'list_id');
    }
}

test('external publication and mutation metadata need no model definition and relationships are collected on demand', function (): void {
    $registry = new ModelRegistry([ExternalTask::class]);
    $task = $registry->get('ExternalTask');
    expect((new ZeroQuery($task, $registry))->pendingAttributes())->toBe([]);
    expect($task->writable)->toBe(['user_id', 'title']);
    expect($task->relations)->toBe([]);
    expect(fn (): ZeroQuery => (new ZeroQuery($task, $registry))->with('delete'))->toThrow(LogicException::class, 'relationship');
    expect(fn (): ZeroQuery => (new ZeroQuery($task, $registry))->with('parent'))->toThrow(LogicException::class, 'Unknown Zero model');
    expect($task->columns)->not->toHaveKey('secret');
});

test('model identity must match the database primary key', function (): void {
    new ModelRegistry([WrongKeyTask::class]);
})->throws(LogicException::class, 'primary key');

test('date columns follow zeros numeric wire representation', function (): void {
    /** @var TestCase $this */
    Schema::create('calendars', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->date('day');
    });
    $column = (new ModelRegistry([Calendar::class]))->get('Calendar')->columns['day'];
    $this->assertSame('number', $column['type']);
    $this->assertTrue($column['date']);
});

test('resolves one column plan and separate public mutation inputs', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $task = $registry->get('Task');
    $this->assertArrayNotHasKey('secret', $task->columns);
    $this->assertSame('uuid', $task->idGenerator);
    $this->assertTrue($task->columns['completed_at']['date']);
    $this->assertSame('boolean', $task->columns['done']['type']);
    $this->assertSame(false, $task->columns['done']['default']);
    $this->assertSame(['user_id', 'list_id', 'title', 'done', 'completed_at'], $task->writable);
    $defaults = (new ZeroQuery($task, $registry))->pendingAttributes();
    $this->assertInstanceOf(ContextReference::class, $defaults['user_id']);
    $this->assertSame('user_id', $defaults['user_id']->field);
    $this->assertSame([], $task->relations);
    $relation = $registry->relation($task, 'list');
    $this->assertSame('TaskList', $relation['model']);
    $this->assertSame('one', $relation['cardinality']);
});

test('reads fail closed without a visibility declaration on the model itself', function (): void {
    foreach ([UnprotectedTask::class, InheritedPublicTask::class] as $class) {
        expect(fn (): ModelRegistry => new ModelRegistry([$class]))->toThrow(LogicException::class, '#[ZeroPublic]');
    }
});

test('hidden relation keys are not silently republished', function (): void {
    $registry = new ModelRegistry([HiddenRelationTask::class, TaskList::class, Tag::class, Comment::class]);
    $registry->relation($registry->get('HiddenRelationTask'), 'list');
})->throws(LogicException::class, 'list_id');

test('exclusions are additive to eloquent hidden columns', function (): void {
    /** @var TestCase $this */
    $task = (new ModelRegistry([ExcludedTask::class]))->get('ExcludedTask');
    $this->assertArrayNotHasKey('secret', $task->columns);
    $this->assertArrayNotHasKey('completed_at', $task->columns);
});

#[ZeroPublic]
class Calendar extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'immutable_date'];
    }
}

#[ZeroPublic]
class WrongKeyTask extends Task
{
    protected $primaryKey = 'title';
}

class UnprotectedTask extends Model
{
    protected $table = 'tasks';
}

class InheritedPublicTask extends ExternalTask {}

#[ZeroPublic]
class HiddenRelationTask extends Task
{
    protected $hidden = ['secret', 'list_id'];
}

#[ZeroExclude(['completed_at'])]
#[ZeroPublic]
class ExcludedTask extends Model
{
    protected $table = 'tasks';

    protected $hidden = ['secret'];
}

#[ZeroPublic]
class InvalidRelationTask extends Task
{
    public function invalidRelation(): string
    {
        return 'invalid';
    }
}

#[ZeroPublic]
class InvalidDefinitionTask extends Model
{
    protected $table = 'tasks';

    public static function zero(): string
    {
        return 'invalid';
    }
}

test('legacy model definitions are rejected regardless of return type', function (): void {
    new ModelRegistry([InvalidDefinitionTask::class]);
})->throws(LogicException::class, 'legacy zero()/ZeroDefinition is unsupported');

#[ZeroPublic]
class ParentDependentTask extends Task
{
    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id')->where('user_id', $this->user_id);
    }
}

#[ZeroPublic]
class ParentAccessorTask extends Task
{
    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id')->where('user_id', $this->getAttribute('user_id'));
    }
}

test('collected relationships reject parent values instead of freezing a blank instance', function (): void {
    foreach ([ParentDependentTask::class, ParentAccessorTask::class] as $class) {
        $registry = new ModelRegistry([$class, Comment::class]);
        $parent = $registry->get(class_basename($class));
        expect(fn (): array => $registry->relation($parent, 'comments'))
            ->toThrow(LogicException::class, 'nonportable parent-instance relationship logic');
    }
});

test('relationships are validated lazily and require a typed public Eloquent method', function (): void {
    $registry = new ModelRegistry([InvalidRelationTask::class]);
    expect($registry->get('InvalidRelationTask')->relations)->toBe([]);
    $registry->relation($registry->get('InvalidRelationTask'), 'invalidRelation');
})->throws(LogicException::class, 'relationship');

enum CastStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

#[ZeroPublic]
class CastTask extends Task
{
    protected $fillable = ['user_id', 'title', 'options', 'status', 'completed_at', 'scheduled_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['done' => 'boolean', 'completed_at' => 'immutable_datetime', 'options' => 'array', 'status' => CastStatus::class, 'scheduled_at' => 'immutable_datetime'];
    }
}

test('exports native cast metadata including implicit timestamps and backed enum values', function (): void {
    Schema::table('tasks', function (Blueprint $table): void {
        $table->text('options')->nullable();
        $table->string('status')->default('draft');
        $table->string('scheduled_at')->nullable();
    });
    $model = (new ModelRegistry([CastTask::class]))->get('CastTask');
    expect($model->columns['created_at']['date'])->toBeTrue();
    expect($model->columns['updated_at']['date'])->toBeTrue();
    expect($model->columns['deleted_at']['date'])->toBeTrue();
    expect($model->columns['options']['cast'] ?? null)->toBe('array');
    expect($model->columns['status']['enum'])->toBe(['draft', 'published']);
    expect($model->columns['done']['cast'] ?? null)->toBe('boolean');
    expect($model->columns['scheduled_at']['dateFormat'] ?? null)->toBe('Y-m-d H:i:s');

    $query = new ZeroQuery($model, new ModelRegistry([CastTask::class]));
    expect(data_get($query->withoutGlobalScopes()->where('scheduled_at', '2026-10-30T20:31:45+02:00')->ast(), 'where.right.value'))->toBe('2026-10-30 18:31:45');
});

test('controller mutation primitives preserve JSON enum date casts and patch semantics through Eloquent', function (): void {
    Schema::table('tasks', function (Blueprint $table): void {
        $table->text('options')->nullable();
        $table->string('status')->default('draft');
        $table->string('scheduled_at')->nullable();
    });
    $registry = new ModelRegistry([CastTask::class]);
    $id = '019a0000-0000-7000-8000-000000000001';
    $mutation = new Mutation($registry, ['timestamp' => 1700000000123, 'ids' => ['key:cast' => $id]], new ResolvedContext(['user_id' => 'alice'], 'alice'));
    $registry->connection()->transaction(function () use ($mutation, $id): void {
        $task = $mutation->create(CastTask::class, [
            'title' => 'Casts', 'options' => ['theme' => 'dark'], 'status' => 'published',
            'completed_at' => '2026-10-01T12:30:00.000Z', 'scheduled_at' => '2026-10-01T12:30:00.000Z',
        ], 'cast');
        $mutation->update($task, ['title' => 'Patched']);
        $task->refresh();
        expect($task->id)->toBe($id);
        expect($task->title)->toBe('Patched');
        expect($task->user_id)->toBe('alice');
        expect($task->done)->toBeFalse();
        expect($task->getAttribute('options'))->toBe(['theme' => 'dark']);
        expect($task->getRawOriginal('options'))->toBe('{"theme":"dark"}');
        expect($task->getAttribute('status'))->toBe(CastStatus::Published);
        expect($task->completed_at?->toISOString())->toBe('2026-10-01T12:30:00.000000Z');
        expect($task->getRawOriginal('scheduled_at'))->toBe('2026-10-01 12:30:00');
        expect($task->created_at->getTimestamp())->toBe(1700000000);
        expect(fn (): bool => $mutation->delete($task))->toThrow(LogicException::class, 'Portable delete does not support soft deletes.');
        expect(CastTask::withTrashed()->findOrFail($id)->trashed())->toBeFalse();
    });
});
