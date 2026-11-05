<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use LogicException;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\Module\OutsideTask;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TasksTable;
use ZeroForLaravel\Tests\HttpTestCase;

uses(HttpTestCase::class);

test('annotated mutations cannot bypass native preparation through ordinary HTTP', function (): void {
    Route::post('/controller-write', [ControllerMutationController::class, 'store']);
    $request = Request::create('/controller-write', 'POST', ['title' => 'Bypass', 'zero.controller.dispatch' => true]);
    $response = resolve(Kernel::class)->handle($request);
    expect($response->getStatusCode())->toBe(405);
    expect(Task::count())->toBe(0);
});

test('context follows its declared type and protocol user id is a string', function (): void {
    /** @var HttpTestCase $this */
    $user = (new User)->forceFill(['id' => 42]);
    $request = Request::create('/zero/query', 'POST');
    $request->setUserResolver(fn () => $user);

    $context = ContextDefinition::configured()->resolve($request);
    $this->assertSame('42', $context->get('user_id'));
    $this->assertSame('42', $context->userId());
});

test('cache requests are authenticated separately from end users', function (): void {
    /** @var HttpTestCase $this */
    $this->postJson('/zero/query', ['transform', []])->assertForbidden();
});

test('bad json returns protocol error without leaking exceptions', function (): void {
    /** @var HttpTestCase $this */
    $this->withHeader('X-Api-Key', 'private-test-secret')->postJson('/zero/query', ['other', []])
        ->assertOk()->assertJsonPath('kind', 'TransformFailed')->assertJsonPath('reason', 'parse');
});

test('the cache request size limit still bounds transform work', function (): void {
    /** @var HttpTestCase $this */
    $this->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', [[
            'id' => 'large', 'name' => 'q_http', 'args' => [['title' => str_repeat('x', 1048576)]],
        ]]])->assertStatus(413);
});

test('generation command writes verified contracts and can detect drift', function (): void {
    /** @var HttpTestCase $this */
    $path = sys_get_temp_dir().'/zero-for-laravel-'.uniqid().'.ts';
    try {
        $command = $this->artisan('zero:generate', ['--output' => $path]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringContainsString('export type TaskRow =', $source);
        $command = $this->artisan('zero:generate', ['--output' => $path, '--check' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        file_put_contents($path, '// out of date');
        $command = $this->artisan('zero:generate', ['--output' => $path, '--check' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertFailed()->run();
    } finally {
        File::delete($path);
    }
});

test('configured models must be Eloquent model classes', function (): void {
    config()->set('zero.models', ['invalid']);
    app()->forgetInstance(ModelRegistry::class);
    app()->make(ModelRegistry::class);
})->throws(LogicException::class, 'Eloquent model classes');

test('Effect generation is opt-in and preserves PHP contracts while detecting validator drift', function (): void {
    $directory = sys_get_temp_dir().'/zero-effect-'.uniqid();
    config(['zero.controller_output' => $directory.'/queries.php', 'zero.mutation_output' => $directory.'/mutations.php']);
    Route::post('/write', [ControllerMutationController::class, 'store']);
    Route::get('/tasks/{title}', [QueryController::class, 'index']);
    Route::get('/sorted/{direction}', [QueryController::class, 'sorted']);
    $options = ['--output' => $directory.'/generated.ts'];
    try {
        expect(Artisan::call('zero:generate', $options))->toBe(0);
        foreach (['queries', 'mutations'] as $kind) {
            expect(File::get($directory.'/generated.'.$kind.'.ts'))->toContain("from 'zod'");
            expect(File::get($directory.'/generated.'.$kind.'.ts'))->not->toContain('effect/');
        }

        $queries = File::get($directory.'/queries.php');
        $mutations = File::get($directory.'/mutations.php');
        $models = File::get($directory.'/generated.ts');
        $controller = File::get($directory.'/ZeroForLaravel/Tests/Fixtures/ControllerMutationController.ts');

        config(['zero.schema_driver' => 'effect']);
        app()->forgetInstance(\ZeroForLaravel\Generation\SchemaDriver::class);
        app()->forgetInstance(\ZeroForLaravel\Generation\Mutations\MutationSchemaDriver::class);
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(1);
        expect(Artisan::call('zero:generate', $options))->toBe(0);
        foreach (['queries', 'mutations'] as $kind) {
            expect(File::get($directory.'/generated.'.$kind.'.ts'))->toContain("from 'effect/Schema'", 'toStandardSchemaV1', 'parse(input: unknown)');
            expect(File::get($directory.'/generated.'.$kind.'.ts'))->not->toContain("from 'zod'");
        }

        expect(File::get($directory.'/queries.php'))->toBe($queries);
        expect(File::get($directory.'/mutations.php'))->toBe($mutations);
        expect(File::get($directory.'/generated.ts'))->toBe($models);
        expect(File::get($directory.'/ZeroForLaravel/Tests/Fixtures/ControllerMutationController.ts'))->toBe($controller);
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(0, Artisan::output());
    } finally {
        File::deleteDirectory($directory);
    }
});

test('generation rejects unknown schema drivers', function (): void {
    config(['zero.schema_driver' => 'efffect']);
    Artisan::call('zero:generate');
})->throws(LogicException::class, 'zero.schema_driver must be zod or effect.');

test('generation owns named controller modules and removes only stale generated files', function (): void {
    $directory = sys_get_temp_dir().'/zero-public-'.uniqid();
    config(['zero.controller_output' => $directory.'/queries.php', 'zero.mutation_output' => $directory.'/mutations.php', 'zero.tables.tasks' => TasksTable::class]);
    Route::post('/write', [ControllerMutationController::class, 'store']);
    Route::get('/tasks/{title}', [QueryController::class, 'index']);
    $options = ['--output' => $directory.'/generated.ts'];
    try {
        expect(Artisan::call('zero:generate', $options))->toBe(0);
        $controller = $directory.'/ZeroForLaravel/Tests/Fixtures/ControllerMutationController.ts';
        expect(File::get($controller))->toContain(' as store}', ' as StoreInput', '../../../generated.mutations');
        expect(File::get($directory.'/ZeroForLaravel/Tests/Fixtures/QueryController.ts'))->toContain(' as index}', '.request()', 'live: liveQueries');
        expect(File::get($directory.'/tables/tasks.ts'))->toContain('applyTableQuery', '../generated');
        expect(File::exists($directory.'/pages.d.ts'))->toBeFalse();
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(0, Artisan::output());
        File::put($directory.'/handwritten.ts', 'export const keep = true;');
        Route::setRoutes(new RouteCollection);
        config(['zero.tables' => []]);
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(1);
        expect(File::exists($controller))->toBeTrue();
        expect(Artisan::call('zero:generate', $options))->toBe(0);
        expect(File::exists($controller))->toBeFalse();
        expect(File::exists($directory.'/tables/tasks.ts'))->toBeFalse();
        expect(File::get($directory.'/handwritten.ts'))->toBe('export const keep = true;');
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(0);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('Formbraid generation publishes direct descriptors and bound factories without overwriting edits', function (): void {
    $id = uniqid();
    $directory = 'resources/js/zero-forms-'.$id;
    $root = base_path($directory);
    $enum = 'app/Formbraid/Fields'.$id.'.php';
    File::ensureDirectoryExists($root);
    File::put($root.'/metadata.json', json_encode([
        'version' => 1, 'inputs' => ['must-not-load-browser-code.tsx'],
        'fields' => ['Input' => ['props' => [
            'label' => ['required' => true, 'type' => ['kind' => 'string']],
            'constraints' => ['required' => true, 'type' => ['kind' => 'unknown']],
        ]]],
        'components' => (object) [], 'groups' => (object) [],
    ], JSON_THROW_ON_ERROR));
    config([
        'zero.output' => $root.'/native/generated.ts',
        'zero.controller_output' => $root.'/queries.php',
        'zero.mutation_output' => $root.'/mutations.php',
        'zero.formbraid_output' => $directory.'/forms',
        'formbraid.metadata' => $directory.'/metadata.json',
        'formbraid.component_enum' => $enum,
        'formbraid.fields.types' => ['string' => 'Input', 'boolean' => 'Input'],
        'formbraid.fields.fallbackType' => 'string',
    ]);
    Route::post('/write', [ControllerMutationController::class, 'store']);
    Route::patch('/complete/{task}', [ControllerMutationController::class, 'complete']);
    Route::post('/options/{task}', [ControllerMutationController::class, 'options']);
    Route::get('/query/{title}', [QueryController::class, 'index']);
    try {
        expect(Artisan::call('zero:formbraid'))->toBe(0, Artisan::output());
        $path = $root.'/forms/ZeroForLaravel/Tests/Fixtures/ControllerMutationController.ts';
        $source = File::get($path);
        expect($source)->toContain('const _form_store = createControllerForm(', 'parameters: {"task": string | number | boolean}', 'as store}', 'as complete}');
        expect($source)->not->toContain('useControllerForm', 'fetch(');
        expect($source)->toContain('createZeroOptionSource', 'withOptionSources(', '"task": parameters["task"]', 'resolve:', '"kind":"value"');
        preg_match_all('/_optionBindings\["(q_[a-f0-9]+)"\]/', $source, $bindings);
        foreach ($bindings[1] as $binding) {
            expect(File::get($root.'/native/generated.queries.ts'))->toContain('"'.$binding.'"');
        }

        expect(File::get($root.'/forms/requests/ZeroForLaravel/Tests/Fixtures/ControllerMutationRequest.ts'))->toContain('defineForm<ControllerMutationRequestValues>()', '"maxLength": 100');
        expect(File::exists($root.'/forms/ZeroForLaravel/Tests/Fixtures/QueryController.ts'))->toBeFalse();
        expect(Task::query()->count())->toBe(0);
        expect(Artisan::call('zero:formbraid'))->toBe(0, Artisan::output());
        expect(File::get($path))->toBe($source);
        Route::post('/custom', [ControllerMutationController::class, 'projected']);
        expect(Artisan::call('zero:formbraid'))->toBe(1);
        expect(Artisan::output())->toContain('Set zero.formbraid_runtime');
        expect(File::get($path))->toBe($source);
        config(['zero.formbraid_runtime' => $directory.'/custom.ts']);
        expect(Artisan::call('zero:formbraid'))->toBe(0, Artisan::output());
        expect(File::get($path))->toContain('from "../../../../custom"', 'as projected}');
        File::put($path, '// Application edit');
        expect(Artisan::call('zero:formbraid'))->toBe(1);
        expect(Artisan::output())->toContain('Refusing to overwrite');
        expect(File::get($path))->toBe('// Application edit');
    } finally {
        File::deleteDirectory($root);
        File::delete(base_path($enum));
    }
});

class InvalidContextResolver
{
    public function __invoke(Request $request): string
    {
        return 'invalid';
    }
}

test('configured context resolvers must map every declared field', function (): void {
    config()->set('zero.context_resolver', InvalidContextResolver::class);
    ContextDefinition::configured();
})->throws(LogicException::class, 'Missing #[ResolvesContext]');

test('automatic publication synchronization skips SQLite migrations', function (): void {
    /** @var HttpTestCase $this */
    config()->set('zero.sync_publication_on_migrate', true);
    Event::dispatch(new MigrationsEnded('up'));
    $this->assertFalse(app()->resolved(ModelRegistry::class));
});

test('react generation binds the contracts and preserves editable connection configuration', function (): void {
    /** @var HttpTestCase $this */
    $directory = sys_get_temp_dir().'/zero-for-laravel-react-'.uniqid();
    $path = $directory.'/generated.ts';
    try {
        $command = $this->artisan('zero:generate', ['--output' => $path, '--react' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        expect(file_get_contents($directory.'/generated.react.ts'))->toContain('createZeroProvider<Context>(schema, mutators, options, true, {actions, preparation}, contextContract)', '"./generated"');
        file_put_contents($directory.'/zero.config.ts', '// Application-owned options');
        $command = $this->artisan('zero:generate', ['--output' => $path, '--react' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        expect(file_get_contents($directory.'/zero.config.ts'))->toBe('// Application-owned options');
        $command = $this->artisan('zero:generate', ['--output' => $path, '--react' => true, '--check' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        file_put_contents($directory.'/generated.react.ts', '// drift');
        $command = $this->artisan('zero:generate', ['--output' => $path, '--react' => true, '--check' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertFailed()->run();
    } finally {
        foreach (glob($directory.'/*') ?: [] as $file) {
            File::delete($file);
        }

        rmdir($directory);
    }
});

test('trait-based modular discovery still requires explicit read visibility and respects the model allowlist', function (): void {
    config()->set('zero.models', []);
    config()->set('zero.discovery', [['path' => __DIR__.'/../Fixtures/Module', 'namespace' => 'ZeroForLaravel\\Tests\\Fixtures\\Module']]);

    app()->forgetInstance(ModelRegistry::class);
    expect(fn (): ModelRegistry => resolve(ModelRegistry::class))->toThrow(LogicException::class, 'explicit zero.public_models');
    config(['zero.public_models' => [OutsideTask::class]]);
    app()->forgetInstance(ModelRegistry::class);
    expect(array_keys(resolve(ModelRegistry::class)->all()))->toBe(['OutsideTask']);
    config(['zero.models' => [OutsideTask::class],
        'zero.public_models' => [OutsideTask::class]]);
    app()->forgetInstance(ModelRegistry::class);
    $registry = resolve(ModelRegistry::class);
    expect(array_keys($registry->all()))->toBe(['OutsideTask']);
});

test('exposure reports reflect the same private column and authorization metadata', function (): void {
    /** @var HttpTestCase $this */
    $command = $this->artisan('zero:exposure');
    $this->assertInstanceOf(PendingCommand::class, $command);
    $command->expectsOutputToContain('Published:')->expectsOutputToContain('Laravel policy via visibleTo / captured Gate')->assertSuccessful()->run();
});

test('mutation results are not exposed through a second read endpoint', function (): void {
    /** @var HttpTestCase $this */
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $this->actingAs($alice)->postJson('/zero/results/completeTasks', ['ids' => []])->assertNotFound();
});
