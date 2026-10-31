<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ZeroForLaravel\Attributes\ZeroExclude;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\ZeroDefinition;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\Mutation;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

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
    $this->assertSame(['list_id', 'title', 'done', 'completed_at'], $task->writable);
    $this->assertSame(['user_id' => 'user_id'], $task->serverFill);
    $this->assertSame('TaskList', $task->relations['list']['model']);
    $this->assertSame('one', $task->relations['list']['cardinality']);
});

test('reads fail closed without an explicit visibility declaration', function (): void {
    new ModelRegistry([UnprotectedTask::class]);
})->throws(LogicException::class, 'visibility');

test('hidden relation keys are not silently republished', function (): void {
    new ModelRegistry([HiddenRelationTask::class, TaskList::class, Tag::class, Comment::class]);
})->throws(LogicException::class, 'list_id');

test('exclusions are additive to eloquent hidden columns', function (): void {
    /** @var TestCase $this */
    $task = (new ModelRegistry([ExcludedTask::class]))->get('ExcludedTask');
    $this->assertArrayNotHasKey('secret', $task->columns);
    $this->assertArrayNotHasKey('completed_at', $task->columns);
});

class Calendar extends Model
{
    public $timestamps = false;

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads();
    }

    protected function casts(): array
    {
        return ['day' => 'immutable_date'];
    }
}

class WrongKeyTask extends Task
{
    protected $primaryKey = 'title';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads();
    }
}

class UnprotectedTask extends Model
{
    protected $table = 'tasks';
}

class HiddenRelationTask extends Task
{
    protected $hidden = ['secret', 'list_id'];
}

#[ZeroExclude(['completed_at'])]
class ExcludedTask extends Model
{
    protected $table = 'tasks';

    protected $hidden = ['secret'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads();
    }
}

class InvalidDefinitionTask extends Model
{
    protected $table = 'tasks';

    public static function zero(): string
    {
        return 'invalid';
    }
}

class InvalidRelationTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads()->relations('invalidRelation');
    }

    public function invalidRelation(): string
    {
        return 'invalid';
    }
}

test('model definitions must return ZeroDefinition', function (): void {
    new ModelRegistry([InvalidDefinitionTask::class]);
})->throws(LogicException::class, 'zero() must return ZeroDefinition');

test('included methods must return an Eloquent relation', function (): void {
    new ModelRegistry([InvalidRelationTask::class]);
})->throws(LogicException::class, 'method must return an Eloquent relation');

enum CastStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

class CastTask extends Task
{
    protected $fillable = ['title', 'options', 'status', 'completed_at', 'scheduled_at'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads()->fillFromContext('user_id', ContextField::UserId);
    }

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

    $query = new Query($model, new ModelRegistry([CastTask::class]));
    expect(data_get($query->where('scheduled_at', '2026-10-30T20:31:45+02:00')->ast(), 'where.right.value'))->toBe('2026-10-30 18:31:45');
});

test('controller mutation primitives preserve JSON enum date casts and patch semantics through Eloquent', function (): void {
    Schema::table('tasks', function (Blueprint $table): void {
        $table->text('options')->nullable();
        $table->string('status')->default('draft');
        $table->string('scheduled_at')->nullable();
    });
    $registry = new ModelRegistry([CastTask::class]);
    $id = '019a0000-0000-7000-8000-000000000001';
    $mutation = new Mutation($registry, ['timestamp' => 1700000000123, 'ids' => ['key:cast' => $id]], new ZeroContext(['user_id' => 'alice']));
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
