<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\ProvidesInertiaProperties;
use Inertia\RenderContext;
use LogicException;
use PhpParser\ParserFactory;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Inertia\ZeroInertiaServiceProvider;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

test('Inertia omits live result props in HTML, visits, and partial responses', function (): void {
    /** @var TestCase $this */
    app()->register(ZeroInertiaServiceProvider::class);
    $registry = new ModelRegistry($this->models());
    app()->instance(ModelRegistry::class, $registry);
    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    $query = $compiler->compile(QueryController::class, 'index')[0];
    $bound = $compiler->compile(QueryController::class, 'show')[0];
    $directory = sys_get_temp_dir().'/zero-inertia-'.bin2hex(random_bytes(6));
    mkdir($directory);
    try {
        file_put_contents($directory.'/queries.php', (new ControllerQueryGenerator(new ZodSchemaDriver))->php([$query, $bound]));
        file_put_contents($directory.'/app.blade.php', '<div data-page="{{ json_encode($page) }}"></div>');
        app()->instance(RegisteredQueries::class, RegisteredQueries::load($directory.'/queries.php'));
        view()->addNamespace('zero-test', $directory);
        Inertia::setRootView('zero-test::app');
        Inertia::share('appName', 'Example');
        Route::get('/tasks/{title}', [QueryController::class, 'index']);
        Route::get('/alternate/{search}', [QueryController::class, 'index']);
        Route::get('/bound/{task:title}', [QueryController::class, 'show'])->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
        $task = Task::create(['user_id' => 'alice', 'title' => 'slug']);
        $this->get('/bound/slug', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonMissingPath('props.task')
            ->assertJsonPath('props.__zero.bindings.task.args.task', $task->id)
            ->assertJsonPath('props.__zero.bindings.task.args.__routeArgs.task', 'slug');
        $this->get('/bound/missing', ['X-Inertia' => 'true'])->assertNotFound();

        $html = $this->get('/tasks/0')->assertOk()->assertSee('data-page');
        $page = $html->viewData('page');
        expect(data_get($page, 'props.heading'))->toBe('Tasks');
        expect(data_get($page, 'props.appName'))->toBe('Example');
        expect(data_get($page, 'props.__zero.bindings.tasks.args.title'))->toBe('0');
        expect(data_get($page, 'props.tasks', 'absent'))->toBe('absent');

        $this->get('/alternate/actual', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonMissingPath('props.tasks')
            ->assertJsonPath('props.__zero.bindings.tasks.args.title', 'actual');
        $this->get('/tasks/0', [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'tasks/index', 'X-Inertia-Partial-Data' => 'heading',
        ])->assertOk()->assertJsonMissingPath('props.tasks')
            ->assertJsonPath('props.__zero.mode', 'patch')->assertJsonMissingPath('props.__zero.bindings.tasks')
            ->assertJsonPath('props.__zero.bindings.heading', null);
        $this->get('/tasks/0', [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'tasks/index', 'X-Inertia-Partial-Data' => 'tasks',
        ])->assertOk()->assertJsonMissingPath('props.tasks')
            ->assertJsonPath('props.__zero.bindings.tasks.query', $query->name());

        Inertia::share('tasks', ['private snapshot']);
        \Pest\Laravel\withoutExceptionHandling();
        expect(fn () => $this->get('/tasks/0'))->toThrow(LogicException::class, 'collides');
    } finally {
        \Illuminate\Support\Facades\File::delete($directory.'/queries.php');
        \Illuminate\Support\Facades\File::delete($directory.'/app.blade.php');
        rmdir($directory);
    }
});

test('property providers cannot bypass live prop collision checks', function (): void {
    /** @var TestCase $this */
    app()->register(ZeroInertiaServiceProvider::class);
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));

    $provider = new class implements ProvidesInertiaProperties
    {
        public function toInertiaProperties(RenderContext $context): iterable
        {
            return ['tasks' => Task::zeroQuery()];
        }
    };
    Inertia::share('tasks', ['snapshot']);
    Route::get('/provider', fn () => Inertia::render('tasks/index', $provider));
    \Pest\Laravel\withoutExceptionHandling();
    expect(fn () => $this->get('/provider', ['X-Inertia' => 'true']))->toThrow(LogicException::class, 'collides');
    Inertia::flushShared();
    Inertia::share('tasks', Task::zeroQuery());
    expect(fn () => Inertia::render('tasks/index'))->toThrow(LogicException::class, 'not shared props');
});
