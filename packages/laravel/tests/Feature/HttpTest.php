<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Composer\Autoload\ClassLoader;
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
use ZeroForLaravel\Generation\EffectSchemaDriver;
use ZeroForLaravel\Generation\FrontendDriver;
use ZeroForLaravel\Generation\Mutations\MutationSchemaDriver;
use ZeroForLaravel\Generation\Mutations\ValueContract;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Support\ApplicationClasses;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\Module\OutsideTask;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TasksTable;
use ZeroForLaravel\Tests\HttpTestCase;
use ZeroForLaravel\ZeroServiceProvider;

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

test('discovery ignores classes which are not participating models', function (): void {
    /** @var HttpTestCase $this */
    $this->discover([InvalidContextResolver::class, Task::class]);
    app()->forgetInstance(ModelRegistry::class);
    expect(array_keys(resolve(ModelRegistry::class)->all()))->toBe(['Task']);
});

test('Effect generation is opt-in and preserves PHP contracts while detecting validator drift', function (): void {
    $directory = sys_get_temp_dir().'/zero-effect-'.uniqid();
    config(['zero.generation.queries' => $directory.'/queries.php', 'zero.generation.mutations' => $directory.'/mutations.php']);
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

        config(['zero.generation.schema' => EffectSchemaDriver::class]);
        app()->forgetInstance(\ZeroForLaravel\Generation\SchemaDriver::class);
        app()->forgetInstance(MutationSchemaDriver::class);
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
    config(['zero.generation.schema' => InvalidContextResolver::class]);
    Artisan::call('zero:generate');
})->throws(LogicException::class, 'zero.generation.schema must implement');

test('generation owns named controller modules and removes only stale generated files', function (): void {
    /** @var HttpTestCase $this */
    $directory = sys_get_temp_dir().'/zero-public-'.uniqid();
    config(['zero.generation.queries' => $directory.'/queries.php', 'zero.generation.mutations' => $directory.'/mutations.php']);
    $this->discover([...$this->models(), TasksTable::class]);
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
        $this->discover($this->models());
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
        'zero.generation.output' => $root.'/native/generated.ts',
        'zero.generation.queries' => $root.'/queries.php',
        'zero.generation.mutations' => $root.'/mutations.php',
        'zero.formbraid.output' => $directory.'/forms',
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
        $path = $root.'/forms/ZeroForLaravel/Tests/Fixtures/ControllerMutationController.ts';
        expect(Artisan::call('zero:generate'))->toBe(0, Artisan::output());
        expect(File::exists($path))->toBeFalse();
        config(['zero.formbraid.enabled' => true]);
        expect(Artisan::call('zero:generate', ['--check' => true]))->toBe(1);
        expect(File::exists($path))->toBeFalse();
        expect(File::exists(base_path($enum)))->toBeFalse();
        expect(Artisan::call('zero:generate'))->toBe(0, Artisan::output());
        expect(Artisan::call('zero:generate', ['--check' => true]))->toBe(0, Artisan::output());
        expect(Artisan::call('zero:formbraid', ['--check' => true]))->toBe(0, Artisan::output());
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
        config(['zero.formbraid.enabled' => false]);
        expect(Artisan::call('zero:formbraid'))->toBe(0, Artisan::output());
        expect(File::get($path))->toBe($source);
        config(['zero.formbraid.enabled' => true]);
        Route::post('/custom', [ControllerMutationController::class, 'projected']);
        expect(Artisan::call('zero:formbraid'))->toBe(1);
        expect(Artisan::output())->toContain('Set zero.formbraid.runtime');
        expect(File::get($path))->toBe($source);
        config(['zero.formbraid.runtime' => $directory.'/custom.ts']);
        expect(Artisan::call('zero:formbraid'))->toBe(0, Artisan::output());
        expect(File::get($path))->toContain('from "../../../../custom"', 'as projected}');
        File::put($path, '// Application edit');
        expect(Artisan::call('zero:generate', ['--check' => true]))->toBe(1);
        expect(File::get($path))->toBe('// Application edit');
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

test('react detection binds the contracts and preserves editable connection configuration', function (string $section): void {
    /** @var HttpTestCase $this */
    $directory = sys_get_temp_dir().'/zero-for-laravel-react-'.uniqid();
    $path = $directory.'/generated.ts';
    $original = base_path();
    File::ensureDirectoryExists($directory);
    File::put($directory.'/package.json', json_encode([$section => ['react' => '^19.0']], JSON_THROW_ON_ERROR));
    app()->setBasePath($directory);
    config(['zero.generation.queries' => $directory.'/queries.php', 'zero.generation.mutations' => $directory.'/mutations.php']);
    try {
        $command = $this->artisan('zero:generate', ['--output' => $path]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        expect(file_get_contents($directory.'/generated.react.ts'))->toContain('createZeroProvider<Context>(schema, mutators, options, true, {actions, preparation}, contextContract)', '"./generated"');
        file_put_contents($directory.'/zero.config.ts', '// Application-owned options');
        $command = $this->artisan('zero:generate', ['--output' => $path]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        expect(file_get_contents($directory.'/zero.config.ts'))->toBe('// Application-owned options');
        $command = $this->artisan('zero:generate', ['--output' => $path, '--check' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        file_put_contents($directory.'/generated.react.ts', '// drift');
        $command = $this->artisan('zero:generate', ['--output' => $path, '--check' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertFailed()->run();
    } finally {
        app()->setBasePath($original);
        File::deleteDirectory($directory);
    }
})->with(['dependencies', 'devDependencies']);

test('trait-based modular discovery reads visibility from the model', function (): void {
    /** @var HttpTestCase $this */
    $this->discover([OutsideTask::class]);
    app()->forgetInstance(ModelRegistry::class);
    $registry = resolve(ModelRegistry::class);
    expect(array_keys($registry->all()))->toBe(['OutsideTask']);
    expect($registry->get('OutsideTask')->policyVisibility)->toBeFalse();
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

test('Composer namespaces discover nested models and tables without configuration lists', function (): void {
    $directory = sys_get_temp_dir().'/zero-discovery-'.uniqid();
    $namespace = 'ZeroDiscovery'.bin2hex(random_bytes(4));
    $original = base_path();
    $loader = new ClassLoader;
    File::ensureDirectoryExists($directory.'/app/Domain/Deep');
    File::ensureDirectoryExists($directory.'/app/Other');
    File::ensureDirectoryExists($directory.'/dev');
    File::put($directory.'/composer.json', json_encode([
        'autoload' => ['psr-4' => [$namespace.'\\' => ['app', 'missing']]],
        'autoload-dev' => ['psr-4' => [$namespace.'\\Dev\\' => 'dev']],
    ], JSON_THROW_ON_ERROR));
    File::put($directory.'/app/Domain/NestedTask.php', '<?php namespace '.$namespace.'\\Domain; #[\\ZeroForLaravel\\Attributes\\ZeroPublic] class NestedTask extends \\ZeroForLaravel\\Tests\\Fixtures\\VisibleTask {}');
    File::put($directory.'/app/Domain/AbstractTask.php', '<?php namespace '.$namespace.'\\Domain; abstract class AbstractTask extends NestedTask {}');
    File::put($directory.'/app/Domain/Unpublished.php', '<?php namespace '.$namespace.'\\Domain; class Unpublished extends \\Illuminate\\Database\\Eloquent\\Model {}');
    File::put($directory.'/app/Domain/Deep/Utility.php', '<?php namespace '.$namespace.'\\Domain\\Deep; class Utility {}');
    File::put($directory.'/app/Other/OtherTask.php', '<?php namespace '.$namespace.'\\Other; #[\\ZeroForLaravel\\Attributes\\ZeroPublic] class OtherTask extends \\ZeroForLaravel\\Tests\\Fixtures\\VisibleTask { protected $table = "lists"; protected $visible = ["id", "name"]; protected $fillable = ["name"]; }');
    File::put($directory.'/dev/DevTask.php', '<?php namespace '.$namespace.'\\Dev; class DevTask extends \\ZeroForLaravel\\Tests\\Fixtures\\VisibleTask {}');
    File::put($directory.'/app/Domain/TasksTable.php', '<?php namespace '.$namespace.'\\Domain; use '.Table::class.'; class TasksTable { public function definition(): Table { return Table::for(NestedTask::class)->allowedSorts(["title"]); } }');
    $loader->addPsr4($namespace.'\\', $directory.'/app');
    $loader->addPsr4($namespace.'\\Dev\\', $directory.'/dev');
    $loader->register();

    app()->setBasePath($directory);
    app()->instance(ApplicationClasses::class, new ApplicationClasses);
    app()->forgetInstance(ModelRegistry::class);
    config(['zero.generation.output' => $directory.'/generated.ts',
        'zero.generation.queries' => $directory.'/queries.php', 'zero.generation.mutations' => $directory.'/mutations.php']);
    try {
        expect(Artisan::call('zero:generate'))->toBe(0, Artisan::output());
        expect(array_keys(resolve(ModelRegistry::class)->all()))->toBe(['NestedTask', 'OtherTask']);
        expect(File::get($directory.'/generated.ts'))->toContain('NestedTaskRow');
        expect(File::get($directory.'/generated.ts'))->not->toContain('AbstractTaskRow', 'UnpublishedRow', 'DevTaskRow');
        expect(File::get($directory.'/tables/tasks.ts'))->toContain('applyTableQuery');
        expect(File::exists($directory.'/generated.react.ts'))->toBeFalse();
        expect(Artisan::call('zero:generate', ['--check' => true]))->toBe(0, Artisan::output());

        foreach ([
            [$namespace.'\\Domain\\NestedTask', $directory.'/app/**/TasksTable.php'],
            [$namespace.'\\Domain\\*'],
            ['app/Domain/*.php'],
            ['app/**/NestedTask.php', 'app/**/TasksTable.php', $namespace.'\\Domain\\NestedTask'],
        ] as $selection) {
            config(['zero.discovery' => $selection]);
            app()->forgetInstance(ModelRegistry::class);
            expect(Artisan::call('zero:generate'))->toBe(0, Artisan::output());
            expect(array_keys(resolve(ModelRegistry::class)->all()))->toBe(['NestedTask']);
            expect(File::get($directory.'/generated.ts'))->not->toContain('OtherTaskRow');
            expect(File::get($directory.'/tables/tasks.ts'))->toContain('applyTableQuery');
        }

        config(['zero.discovery' => ['app/Domain/*.php']]);
        expect(resolve(ApplicationClasses::class)->all())->not->toContain($namespace.'\\Domain\\Deep\\Utility');
        config(['zero.discovery' => ['app/Domain/**/*.php']]);
        expect(resolve(ApplicationClasses::class)->all())->toContain($namespace.'\\Domain\\Deep\\Utility', $namespace.'\\Domain\\NestedTask');
        config(['zero.discovery' => [\ZeroForLaravel\Tests\Fixtures\VisibleTask::class]]);
        expect(resolve(ApplicationClasses::class)->all())->toBe([\ZeroForLaravel\Tests\Fixtures\VisibleTask::class]);
        config(['zero.discovery' => []]);
        expect(resolve(ApplicationClasses::class)->all())->toBe([]);
        config(['zero.discovery' => 'auto']);
        File::put($directory.'/app/Other/TasksTable.php', '<?php namespace '.$namespace.'\\Other; class TasksTable extends \\'.$namespace.'\\Domain\\TasksTable {}');
        expect(fn (): int => Artisan::call('zero:generate'))->toThrow(LogicException::class, 'Duplicate Zero table name: tasks');
    } finally {
        app()->setBasePath($original);
        $loader->unregister();
        File::deleteDirectory($directory);
    }
});

test('discovery rejects invalid selections rather than silently publishing another set', function (mixed $selection): void {
    config(['zero.discovery' => $selection]);
    (new ApplicationClasses)->all();
})->with(['invalid mode' => ['all'], 'invalid entry' => [[false]], 'unknown class' => [['App\\MissingZeroModel']]])
    ->throws(LogicException::class);

test('nested overrides keep defaults and replace middleware lists', function (): void {
    config(['zero' => ['generation' => ['output' => '/custom/generated.ts'], 'middleware' => []]]);
    (new ZeroServiceProvider(app()))->register();
    expect(config('zero.generation.output'))->toBe('/custom/generated.ts');
    expect(config('zero.generation.queries'))->toBe(base_path('bootstrap/zero-queries.php'));
    expect(config('zero.generation.schema'))->toBe(\ZeroForLaravel\Generation\Mutations\ZodMutationSchemaDriver::class);
    expect(config('zero.middleware'))->toBe([]);
});

test('custom schema and frontend classes generate contracts through the container', function (): void {
    $directory = sys_get_temp_dir().'/zero-custom-drivers-'.uniqid();
    config(['zero.generation.schema' => ApplicationSchemaDriver::class, 'zero.frontend.driver' => ApplicationFrontendDriver::class,
        'zero.generation.output' => $directory.'/generated.ts', 'zero.generation.queries' => $directory.'/queries.php',
        'zero.generation.mutations' => $directory.'/mutations.php']);
    Route::get('/tasks/{title}', [QueryController::class, 'index']);
    Route::post('/write', [ControllerMutationController::class, 'store']);
    try {
        expect(Artisan::call('zero:generate'))->toBe(0, Artisan::output());
        expect(File::get($directory.'/generated.queries.ts'))->toContain('// Application schema driver', "from 'effect/Schema'");
        expect(File::get($directory.'/generated.mutations.ts'))->toContain('// Application schema driver');
        expect(File::get($directory.'/generated.custom.ts'))->toBe('export {schema} from "./generated";');
        expect(File::exists($directory.'/zero.config.ts'))->toBeFalse();
        expect(File::exists($directory.'/generated.react.ts'))->toBeFalse();
        expect(Artisan::call('zero:generate', ['--check' => true]))->toBe(0, Artisan::output());
        File::put($directory.'/generated.custom.ts', '// stale');
        expect(Artisan::call('zero:generate', ['--check' => true]))->toBe(1);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('frontend detection rejects unsupported or ambiguous frameworks', function (array $dependencies): void {
    $directory = sys_get_temp_dir().'/zero-frontend-'.uniqid();
    $original = base_path();
    File::ensureDirectoryExists($directory);
    File::put($directory.'/package.json', json_encode(['dependencies' => $dependencies], JSON_THROW_ON_ERROR));
    app()->setBasePath($directory);
    try {
        expect(fn (): array => resolve(FrontendDriver::class)->files('generated', false))->toThrow(LogicException::class, 'zero.frontend.driver');
    } finally {
        app()->setBasePath($original);
        File::deleteDirectory($directory);
    }
})->with(['unsupported' => [['vue' => '^3']], 'ambiguous' => [['react' => '^19', 'svelte' => '^5']]]);

final readonly class ApplicationFrontendDriver implements FrontendDriver
{
    public function files(string $base, bool $custom): array
    {
        return [$base.'.custom.ts' => 'export {schema} from "./'.$base.'";'];
    }

    public function configuration(): ?string
    {
        return null;
    }
}

final readonly class ApplicationSchemaDriver implements MutationSchemaDriver
{
    public function __construct(private EffectSchemaDriver $driver) {}

    public function imports(): string
    {
        return "// Application schema driver\n".$this->driver->imports();
    }

    public function object(array $parameters, ?array $cursor = null, bool $public = false, array $contracts = [], bool $routed = true, bool $window = false): string
    {
        return $this->driver->object($parameters, $cursor, $public, $contracts, $routed, $window);
    }

    public function encode(array $parameters, string $input): string
    {
        return $this->driver->encode($parameters, $input);
    }

    public function inputType(string $schema): string
    {
        return $this->driver->inputType($schema);
    }

    public function parse(string $schema, string $input): string
    {
        return $this->driver->parse($schema, $input);
    }

    public function value(ValueContract $contract): string
    {
        return $this->driver->value($contract);
    }
}
