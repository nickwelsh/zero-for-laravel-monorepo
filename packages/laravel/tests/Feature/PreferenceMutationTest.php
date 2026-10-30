<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Assert;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRuntime;
use ZeroForLaravel\Mutation\Mutation;
use ZeroForLaravel\Tests\Fixtures\MutationPreference;
use ZeroForLaravel\Tests\Fixtures\PreferenceMutationController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

beforeEach(function (): void {
    if ($database = getenv('PREFERENCE_TEST_DATABASE')) {
        config(['database.default' => 'preferences', 'database.connections.preferences' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55432, 'database' => $database, 'username' => 'postgres', 'password' => '', 'prefix' => '']]);
    }

    Schema::create('mutation_preferences', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('recipient');
        $table->string('issue');
        $table->boolean('subscribed');
        $table->string('note')->default('keep');
        $table->timestamps();
        $table->unique(['recipient', 'issue']);
    });
});

afterEach(function (): void {
    MutationPreference::flushEventListeners();
    Schema::dropIfExists('mutation_preferences');
});

test('compiled preference writes preserve opt outs and read preceding writes', function (): void {
    $models = new ModelRegistry([MutationPreference::class]);
    $compiler = new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), $models);
    $subscribe = $compiler->compile(PreferenceMutationController::class, 'subscribe');
    $unsubscribe = $compiler->compile(PreferenceMutationController::class, 'unsubscribe');
    $id = (string) Str::uuid();
    $mutation = new Mutation($models, ['timestamp' => 1700000000123, 'ids' => ['key:preference' => $id, 'key:unsubscribe' => (string) Str::uuid()]], new ZeroContext([]));
    DB::transaction(function () use ($subscribe, $unsubscribe, $mutation, $id): void {
        $runtime = new ControllerMutationRuntime($mutation);
        expect($runtime->run($subscribe->operations, ['mutation' => $mutation]))->toBeTrue();
        expect(MutationPreference::firstOrFail()->id)->toBe($id);
        expect($mutation->findUnique(MutationPreference::class, ['id' => $id])?->subscribed)->toBeTrue();
        expect($runtime->run($unsubscribe->operations, ['mutation' => $mutation]))->toBeFalse();
        expect($runtime->run($subscribe->operations, ['mutation' => $mutation]))->toBeFalse();
        expect(MutationPreference::count())->toBe(1);
        expect(MutationPreference::firstOrFail()->note)->toBe('keep');
        expect(fn (): \Illuminate\Database\Eloquent\Model => $mutation->insertUnlessExists(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue'], ['id' => 'bad'], 'preference'))->toThrow(ValidationException::class);
        expect(fn (): ?\Illuminate\Database\Eloquent\Model => $mutation->findUnique(MutationPreference::class, ['recipient' => 'alice']))->toThrow(LogicException::class);
    });
});

test('preference primitives enforce transaction boundaries and model event cancellation', function (): void {
    $models = new ModelRegistry([MutationPreference::class]);
    $mutation = new Mutation($models, ['timestamp' => 1700000000123, 'ids' => ['key:preference' => (string) Str::uuid()]], new ZeroContext([]));
    expect(fn (): ?\Illuminate\Database\Eloquent\Model => $mutation->findUnique(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue']))->toThrow(LogicException::class);
    MutationPreference::creating(fn (): false => false);
    expect(fn () => DB::transaction(fn (): \Illuminate\Database\Eloquent\Model => $mutation->insertUnlessExists(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue'], ['subscribed' => true], 'preference')))->toThrow(ValidationException::class, 'cancelled');
    expect(MutationPreference::count())->toBe(0);
});

test('assignment distinguishes omitted null same and changed recipients and rolls back late visibility rejection', function (): void {
    if (! $this instanceof TestCase) {
        throw new LogicException('Expected booted test case.');
    }

    if (! Schema::hasTable('tasks')) {
        $this->createTables();
    }

    app()->register(SurveyorServiceProvider::class);
    Route::post('/assign/{task}', [PreferenceMutationController::class, 'assign'])->middleware(SubstituteBindings::class);
    $models = new ModelRegistry([Task::class, \ZeroForLaravel\Tests\Fixtures\TaskList::class, \ZeroForLaravel\Tests\Fixtures\Tag::class, \ZeroForLaravel\Tests\Fixtures\Comment::class, MutationPreference::class]);
    $program = (new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), $models))->compile(PreferenceMutationController::class, 'assign');
    $file = tempnam(sys_get_temp_dir(), 'assignment-');
    try {
        file_put_contents($file, (new ControllerMutationGenerator)->php([$program]));
        $factory = require $file;
        if (! $factory instanceof Closure) {
            throw new LogicException('Expected generated registry factory.');
        }

        $registry = $factory($models, new ControllerRouteDispatcher);
        if (! $registry instanceof ControllerMutationRegistry) {
            throw new LogicException('Expected generated registry.');
        }
    } finally {
        \Illuminate\Support\Facades\File::delete($file);
    }

    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $task = Task::create(['title' => 'Assigned', 'user_id' => 'actor', 'list_id' => $a]);
    $seen = [];
    $allow = function ($user = null, ?Task $task = null, ?string $recipient = null) use (&$seen): bool {
        $seen[] = [$recipient, $task?->list_id];

        return true;
    };
    Gate::define('recipient-visibility', $allow);
    $execute = function (array $input) use ($models, $program, $registry, $task): void {
        $wire = ['version' => $program->fingerprint, 'input' => $input, 'route' => ['task' => $task->id],
            'prepared' => ['timestamp' => 1700000000123, 'ids' => array_combine(array_keys($program->sites), array_map(fn (): string => (string) Str::uuid(), $program->sites))]];
        expect($models->connection()->transaction(fn (): array => $registry->execute($program->name(), $wire, new ZeroContext(['user_id' => 'actor']))))->toBe(['data' => ['id' => $task->id]]);
    };
    $execute([]);
    expect($task->fresh()?->list_id)->toBe($a);
    expect($seen)->toBe([]);
    expect(MutationPreference::count())->toBe(0);
    $execute(['list_id' => $a]);
    expect($seen)->toBe([[$a, $a]]);
    expect(MutationPreference::firstOrFail()->subscribed)->toBeTrue();
    MutationPreference::query()->update(['subscribed' => false]);
    $execute(['list_id' => $a]);
    expect(MutationPreference::firstOrFail()->subscribed)->toBeFalse();
    $seen = [];
    Gate::define('recipient-visibility', function ($user = null, ?Task $task = null, ?string $recipient = null) use (&$seen, $a): bool {
        $seen[] = [$recipient, $task?->list_id];

        return $recipient !== $a;
    });
    expect(fn () => $execute(['list_id' => $b]))->toThrow(AuthorizationException::class);
    expect($seen)->toBe([[$b, $b], [$a, $b]]);
    expect($task->fresh()?->list_id)->toBe($a);
    expect(MutationPreference::count())->toBe(1);
    Gate::define('recipient-visibility', $allow);
    $execute(['list_id' => $b]);
    expect($task->fresh()?->list_id)->toBe($b);
    expect(MutationPreference::where('recipient', $b)->firstOrFail()->subscribed)->toBeTrue();
    expect(MutationPreference::where('recipient', $a)->firstOrFail()->subscribed)->toBeFalse();
    $execute(['list_id' => null]);
    expect($task->fresh()?->list_id)->toBeNull();
    expect(MutationPreference::where('recipient', $b)->firstOrFail()->subscribed)->toBeFalse();
});

test('PostgreSQL committed competing insert preserves conditional intent and savepoint usability', function (bool $upsert): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        Assert::markTestSkipped('Set PREFERENCE_TEST_DATABASE to a disposable PostgreSQL database.');
    }

    $models = new ModelRegistry([MutationPreference::class]);
    $mutation = new Mutation($models, ['timestamp' => 1700000000123, 'ids' => ['key:preference' => (string) Str::uuid()]], new ZeroContext([]));
    config(['database.connections.competitor' => config('database.connections.preferences')]);
    $winner = (string) Str::uuid();
    MutationPreference::creating(function () use ($winner, $upsert): void {
        // Separate connection commits after our absence read, before our insert.
        DB::connection('competitor')->table('mutation_preferences')->insert(['id' => $winner, 'recipient' => 'alice', 'issue' => 'issue', 'subscribed' => $upsert, 'note' => 'winner']);
    });
    $created = 0;
    MutationPreference::created(function () use (&$created): void {
        $created++;
    });
    DB::transaction(function () use ($mutation, $winner, $upsert): void {
        $row = $upsert
            ? $mutation->upsertUnique(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue'], ['subscribed' => false], 'preference')
            : $mutation->insertUnlessExists(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue'], ['subscribed' => true], 'preference');
        expect($row->id)->toBe($winner);
        expect($row->subscribed)->toBeFalse();
        expect($mutation->findUnique(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue'])?->note)->toBe('winner');
        expect(MutationPreference::count())->toBe(1);
    });
    expect($created)->toBe(0);
    DB::disconnect('competitor');
})->with([false, true]);
