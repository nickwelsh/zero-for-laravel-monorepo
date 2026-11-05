<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Closure;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use LogicException;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\TableCompiler;
use ZeroForLaravel\Generation\TableGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Http\TableMetadataController;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tables\Metadata;
use ZeroForLaravel\Tables\RegisteredTableQuery;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableContext;
use ZeroForLaravel\Tables\TableRegistry;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\Fixtures\TasksTable;
use ZeroForLaravel\Tests\OwnerPostgresTestCase;
use ZeroForLaravel\ZeroServiceProvider;

class TableTransportTestCase extends OwnerPostgresTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zero.tables', ['tasks' => TasksTable::class]);
        $app['config']->set('zero.middleware', [TableScopeMiddleware::class]);
    }
}

final class TableScopeMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($request->header('X-Table-Scope') === 'allowed', 403);

        return $next($request);
    }
}

uses(TableTransportTestCase::class);

final class UnsafeTable
{
    public function definition(TableContext $context): Table
    {
        return Table::for(Task::class)->visibleTo($context->user())->where('title', '=', file_get_contents('/never-execute-this'));
    }
}

beforeEach(function (): void {
    app()->instance(ModelRegistry::class, new ModelRegistry([Task::class, TaskList::class, Tag::class, Comment::class]));
    config(['zero.tables.tasks' => TasksTable::class, 'zero.route_prefix' => 'zero', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Route::post('/table-metadata/{table}', TableMetadataController::class);
    for ($i = 1; $i <= 53; $i++) {
        $id = sprintf('00000000-0000-4000-8000-%012d', $i);
        if ($i <= 51) {
            DB::table('lists')->insert(['id' => $id, 'user_id' => $i === 51 ? 'bob' : 'alice', 'name' => 'List '.$i]);
        }

        DB::table('tasks')->insert(['id' => $id, 'user_id' => 'alice', 'title' => sprintf('Task %03d', $i), 'done' => $i === 53, 'list_id' => $i <= 51 ? $id : null]);
    }

    DB::table('tasks')->insert(['id' => '00000000-0000-4000-8000-000000000099', 'user_id' => 'bob', 'title' => 'Private task', 'done' => false]);
});

test('table directories batch authorized snapshots and scope opaque pagination cursors', function (): void {
    $definition = resolve(TableRegistry::class)->all()[0];
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $body = ['version' => 1, 'contract' => $definition->contract, 'filter' => null, 'groupBy' => ['done', 'list'], 'directories' => [
        ['path' => [], 'after' => null],
        ['path' => [['field' => 'done', 'value' => false]], 'after' => null],
        ['path' => [['field' => 'done', 'value' => true]], 'after' => null],
    ]];
    $response = (new Metadata)->read($definition, $context, $alice, $body);
    expect($response['totalCount'])->toBe(53);
    expect(data_get($response, 'directories.0.groups.*.count'))->toBe([52, 1]);
    expect(data_get($response, 'directories.1.groups'))->toHaveCount(50);
    expect(data_get($response, 'directories.2.groups.0'))->toMatchArray(['value' => null, 'count' => 1, 'label' => 'Unassigned']);

    $cursor = data_get($response, 'directories.1.nextCursor');
    expect($cursor)->toBeString();
    $body['directories'] = [['path' => [['field' => 'done', 'value' => false]], 'after' => $cursor]];
    $next = (new Metadata)->read($definition, $context, $alice, $body);
    expect(data_get($next, 'directories.0.groups.*.label'))->toBe(['Unavailable', 'Unassigned']);
    expect(data_get($next, 'directories.0.nextCursor'))->toBeNull();
    expect(data_get($next, 'directories.0.groups.*.count'))->toBe([1, 1]);
    $body['filter'] = ['kind' => 'condition', 'field' => 'title', 'operator' => 'contains', 'value' => 'Task'];
    expect(fn (): array => (new Metadata)->read($definition, $context, $alice, $body))->toThrow(ValidationException::class);
    $body['filter'] = null;
    $bob = (new User)->setKeyType('string')->forceFill(['id' => 'bob']);
    expect(fn (): array => (new Metadata)->read($definition, new ResolvedContext(['user_id' => 'bob'], 'bob'), $bob, $body))->toThrow(ValidationException::class);
});

test('table native registrations and metadata reject stale, malformed and anonymous requests', function (): void {
    /** @var OwnerPostgresTestCase $this */
    $definition = resolve(TableRegistry::class)->all()[0];
    $body = ['version' => 1, 'contract' => $definition->contract, 'filter' => null, 'groupBy' => ['done'], 'directories' => [['path' => [], 'after' => null]]];
    $this->postJson('/table-metadata/tasks', $body)->assertUnauthorized();
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $this->actingAs($alice);
    request()->setUserResolver(fn () => $alice);
    $this->postJson('/table-metadata/tasks', $body)->assertOk()->assertJsonPath('totalCount', 53)->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson('/table-metadata/missing', $body)->assertNotFound();
    $this->postJson('/table-metadata/tasks', [...$body, 'contract' => 'outdated'])->assertStatus(409);
    $this->postJson('/table-metadata/tasks', [...$body, 'groupBy' => ['done', 'done']])->assertUnprocessable();
    request()->setUserResolver(fn () => $alice);
    $registered = new RegisteredTableQuery('tasks', $definition->contract);
    $ast = $registered->invoke(['filter' => null, 'sort' => [], 'groupBy' => [], 'path' => [], '__window' => 3], new ResolvedContext(['user_id' => 'alice'], 'alice'), new QueryCompiler(resolve(ModelRegistry::class)));
    expect($ast['limit'])->toBe(3);
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('user:alice', 'permission_index');
    expect(fn (): array => $registered->invoke(['__window' => 0], new ResolvedContext(['user_id' => 'alice'], 'alice'), new QueryCompiler(resolve(ModelRegistry::class))))->toThrow(ValidationException::class);

    $identity = ['filter' => null, 'sort' => [], 'groupBy' => [], 'path' => [], '__window' => 1, '__row' => ['00000000-0000-4000-8000-000000000001'], '__refresh' => '00000000-0000-4000-8000-000000000123'];
    $ast = $registered->invoke($identity, new ResolvedContext(['user_id' => 'alice'], 'alice'), new QueryCompiler(resolve(ModelRegistry::class)));
    expect($ast['limit'])->toBe(1);
    expect(json_encode($ast, JSON_THROW_ON_ERROR))->toContain('00000000-0000-4000-8000-000000000001', 'user:alice', 'permission_index');
    foreach ([['__row' => []], ['__row' => null], ['__row' => [false]], ['__row' => ['one', 'two']], ['__window' => 2], ['__refresh' => 'invalid'], ['filter' => ['kind' => 'condition', 'field' => 'title', 'operator' => 'contains', 'value' => 'Task']]] as $invalid) {
        expect(fn (): array => $registered->invoke([...$identity, ...$invalid], new ResolvedContext(['user_id' => 'alice'], 'alice'), new QueryCompiler(resolve(ModelRegistry::class))))->toThrow(ValidationException::class);
    }
});

test('metadata runs configured scope middleware as well as browser authentication', function (): void {
    /** @var TableTransportTestCase $this */
    $definition = resolve(TableRegistry::class)->all()[0];
    $body = ['version' => 1, 'contract' => $definition->contract, 'filter' => null, 'groupBy' => [], 'directories' => []];
    $this->postJson('/zero/tables/tasks/metadata', $body)->assertUnauthorized();
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $this->actingAs($alice);
    $this->postJson('/zero/tables/tasks/metadata', $body)->assertForbidden();
    $this->postJson('/zero/tables/tasks/metadata', $body, ['X-Table-Scope' => 'allowed'])
        ->assertOk()->assertJsonPath('totalCount', 53);
});

test('table generation is symbolic and emits paired descriptors without changing the no-table output', function (): void {
    $compiler = resolve(TableCompiler::class);
    expect(fn () => $compiler->compile('unsafe', UnsafeTable::class))->toThrow(LogicException::class, 'Unsupported table expression');
    $definition = $compiler->compile('tasks', TasksTable::class);
    $models = resolve(ModelRegistry::class);
    $models->relation($models->get('Task'), 'tags');

    expect($compiler->compile('tasks', TasksTable::class)->contract)->toBe($definition->contract);
    $typescript = (new TableGenerator)->typescript([$definition], '../generated');
    expect($typescript['tables/tasks.ts'])->toContain('@nickwelsh/zero-table', 'applyTableQuery', $definition->contract);
    expect($typescript['tables/tasks.ts'])->not->toContain('user:alice', 'permission_index', 'Private task', 'secret');

    $generator = new ControllerQueryGenerator(new ZodSchemaDriver);
    expect($generator->php([]))->toBe($generator->php([], []));
    $path = tempnam(sys_get_temp_dir(), 'zero-tables-');
    if ($path === false) {
        throw new LogicException('Cannot create table registration fixture.');
    }

    try {
        file_put_contents($path, $generator->php([], [$definition]));
        expect(RegisteredQueries::load($path)->all()[$definition->queryName()])->toBeInstanceOf(RegisteredTableQuery::class);
    } finally {
        File::delete($path);
    }
});
