<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use PhpParser\ParserFactory;
use ZeroForLaravel\Attributes\ZeroColumns;
use ZeroForLaravel\Attributes\ZeroExcludeColumns;
use ZeroForLaravel\Attributes\ZeroIncludeColumns;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
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
    'ZeroExcludeColumns excludes visible columns' => [fn (): Model => new ExcludedVisibleTask, ['id']],
    'ZeroColumns replaces the default visible allowlist' => [fn (): Model => new ExplicitVisibleTask, ['id', 'secret']],
    'ZeroIncludeColumns opts columns outside visible back in' => [fn (): Model => new IncludedVisibleTask, ['id', 'title', 'secret']],
    'ZeroIncludeColumns overrides hidden and ZeroExcludeColumns' => [fn (): Model => new IncludedExcludedVisibleTask, ['id', 'title', 'secret']],
    'ZeroColumns remains an upper bound on explicit includes' => [fn (): Model => new BoundedVisibleTask, ['id']],
    'visible may also name accessors and relations' => [fn (): Model => new VirtualVisibleTask, ['id', 'title']],
]);

test('an empty visible list preserves default column discovery', function (): void {
    $model = (new ModelRegistry([EmptyVisibleTask::class]))->get('EmptyVisibleTask');
    expect(array_keys($model->columns))->toContain('id', 'title', 'secret');
});

test('guarded lists control writable business columns without exposing hidden or lifecycle fields', function (): void {
    $open = (new ModelRegistry([UnguardedTask::class]))->get('UnguardedTask');
    $guarded = (new ModelRegistry([GuardedTask::class]))->get('GuardedTask');
    expect($open->writable)->toBe(['user_id', 'list_id', 'title', 'done', 'completed_at']);
    expect($guarded->writable)->toBe(['list_id', 'title', 'done', 'completed_at']);
    expect($guarded->columns)->toHaveKey('user_id');
    expect($guarded->columns)->not->toHaveKey('secret');
});

test('live reads merge default eager loads with refinements and omit hidden relationships', function (): void {
    OwnerPermissions::configure([]);
    $registry = new ModelRegistry([EagerList::class, Task::class, Tag::class]);
    app()->instance(ModelRegistry::class, $registry);
    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    $read = (new EagerListReads)->open();
    $ast = $read->ast();
    expect(array_keys((array) $read->format()['relationships']))->toBe(['tasks']);
    expect($ast['related'])->toHaveCount(1);
    expect(data_get($ast, 'related.0.subquery.related.0.subquery.alias'))->toBe('tags');
    expect(json_encode(data_get($ast, 'related.0.subquery.where'), JSON_THROW_ON_ERROR))->toContain('done');

    $native = $compiler->compile(EagerListReads::class, 'open')[0]->typescript();
    expect(substr_count($native, '.related("tasks"'))->toBe(1);
    expect($native)->toContain('.related("tags"', '"done"');
    expect($native)->not->toContain('privateTasks');

    OwnerPermissions::configure([Task::class]);
    $registry = new ModelRegistry([EagerList::class, Task::class, Tag::class]);
    app()->instance(ModelRegistry::class, $registry);
    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    expect(fn (): array => $compiler->compile(EagerListReads::class, 'all'))->toThrow(LogicException::class, 'require visibleTo');
    expect(fn (): array => (new QueryCompiler($registry))->compileDeclaration(EagerList::zeroQuery()->get(), new ResolvedContext([], 'alice')))->toThrow(LogicException::class, 'require visibleTo');
    expect($compiler->compile(EagerListReads::class, 'authorized')[0]->typescript())->toContain('.related("tasks"', '.related("tags"');
});

test('a global eager load replaces the explicit filter and retains nested model defaults', function (): void {
    OwnerPermissions::configure([]);
    $registry = new ModelRegistry([EagerList::class, Task::class, Tag::class]);
    app()->instance(ModelRegistry::class, $registry);
    EagerList::addGlobalScope('onlyCompleted', function (Builder $query): void {
        $query->with('tasks', fn ($tasks) => $tasks->where('title', 'finished'));
    });
    $ast = (new EagerListReads)->open()->ast();
    expect($ast['related'])->toHaveCount(1);
    expect(data_get($ast, 'related.0.subquery.related.0.subquery.alias'))->toBe('tags');

    $filter = json_encode(data_get($ast, 'related.0.subquery.where'), JSON_THROW_ON_ERROR);
    expect($filter)->toContain('finished');
    expect($filter)->not->toContain('done');

    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    $native = $compiler->compile(EagerListReads::class, 'open')[0]->typescript();
    expect(substr_count($native, '.related("tasks"'))->toBe(1);
    expect($native)->toContain('finished', '.related("tags"');
    expect($native)->not->toContain('"done"');
});

test('sibling paths share the parent but keep their own filters', function (): void {
    OwnerPermissions::configure([]);
    $registry = new ModelRegistry([TaskList::class, Task::class, Tag::class, Comment::class]);
    app()->instance(ModelRegistry::class, $registry);
    $read = (new EagerListReads)->siblings();
    $ast = $read->ast();
    expect($ast['related'])->toHaveCount(1);
    expect(array_keys((array) data_get($read->format(), 'relationships.tasks.relationships')))->toBe(['tags', 'comments']);
    expect(json_encode(data_get($ast, 'related.0.subquery.related'), JSON_THROW_ON_ERROR))->toContain('Urgent', 'Approved');

    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    $native = $compiler->compile(EagerListReads::class, 'siblings')[0]->typescript();
    expect(substr_count($native, '.related("tasks"'))->toBe(1);
    expect($native)->toContain('.related("tags"', '.related("comments"', 'Urgent', 'Approved');
});

test('a visible allowlist limits returned relationships but not membership predicates', function (): void {
    OwnerPermissions::configure([]);
    $registry = new ModelRegistry([AllowlistedList::class, Task::class]);
    app()->instance(ModelRegistry::class, $registry);
    $read = (new EagerListReads)->matching();
    expect($read->ast())->not->toHaveKey('related');
    expect(data_get($read->ast(), 'where.op'))->toBe('EXISTS');

    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    $native = $compiler->compile(EagerListReads::class, 'matching')[0]->typescript();
    expect($native)->toContain('eb.exists("tasks"');
    expect($native)->not->toContain('.related(');
});

test('an instance guard rejects an unbound eager load during generation and names the path', function (): void {
    OwnerPermissions::configure([EagerList::class]);
    $registry = new ModelRegistry([EagerList::class, Task::class, Tag::class]);
    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    expect(fn (): array => $compiler->compile(EagerListReads::class, 'show'))->toThrow(LogicException::class, 'EagerList.tasks:');
});

class UnguardedTask extends Task
{
    protected $fillable = [];

    protected $guarded = [];
}

class GuardedTask extends UnguardedTask
{
    protected $guarded = ['user_id'];
}

class EagerList extends TaskList
{
    protected $with = ['tasks.tags', 'privateTasks'];

    protected $hidden = ['privateTasks', 'display_name'];

    /** @return HasMany<Task, $this> */
    public function privateTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'list_id');
    }
}

class EagerListReads
{
    public function siblings(): ZeroQuery
    {
        return TaskList::zeroQuery()->with(['tasks.tags' => fn ($tags) => $tags->where('name', 'Urgent')])
            ->with('tasks.comments', fn ($comments) => $comments->where('body', 'Approved'))->get();
    }

    public function matching(): ZeroQuery
    {
        return AllowlistedList::zeroQuery()->with('tasks')->whereHas('tasks')->get();
    }

    public function show(EagerList $list): Response
    {
        Gate::authorize('view', $list);

        return Inertia::render('lists/show', ['list' => $list]);
    }

    public function open(): ZeroQuery
    {
        return EagerList::zeroQuery()->with('privateTasks')->with('tasks', fn ($q) => $q->where('done', false))->get();
    }

    public function all(): ZeroQuery
    {
        return EagerList::zeroQuery()->get();
    }

    public function authorized(Request $request): ZeroQuery
    {
        return EagerList::zeroQuery()->with('tasks', fn ($q) => $q->visibleTo($request->user()))->get();
    }
}

class AllowlistedList extends TaskList
{
    protected $with = ['tasks'];

    protected $visible = ['id'];
}

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
    expect(fn (): array => (new ZeroQuery($model, $registry))->ast())->toThrow(LogicException::class, 'Creation defaults require an exposed, fillable, non-metadata column.');
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

class HiddenVisibleTask extends VisibleTask
{
    protected $hidden = ['title'];
}

#[ZeroExcludeColumns(['title'])]
class ExcludedVisibleTask extends VisibleTask {}

#[ZeroColumns(['id', 'secret'])]
class ExplicitVisibleTask extends VisibleTask {}

#[ZeroIncludeColumns(['secret'])]
class IncludedVisibleTask extends VisibleTask {}

#[ZeroExcludeColumns(['title', 'secret'])]
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

class MissingVisibleFill extends VisibleTask
{
    use HasZeroQueries;

    protected static function booted(): void
    {
        static::addGlobalScope('creator', function (Builder $query): void {
            $query->withAttributes(['user_id' => ContextReference::current(ZeroContext::USER_ID)], asConditions: false);
        });
    }
}

class MissingVisibleRelationKey extends VisibleTask
{
    /** @return BelongsTo<MissingVisibleRelationKey, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(self::class, 'list_id');
    }
}
