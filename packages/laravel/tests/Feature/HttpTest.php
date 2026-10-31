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
use ZeroForLaravel\Http\ContextResolver;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\HttpTestCase;

uses(HttpTestCase::class);

test('annotated mutations cannot bypass native preparation through ordinary HTTP', function (): void {
    Route::post('/controller-write', [ControllerMutationController::class, 'store']);
    $request = Request::create('/controller-write', 'POST', ['title' => 'Bypass', 'zero.controller.dispatch' => true]);
    $response = resolve(Kernel::class)->handle($request);
    expect($response->getStatusCode())->toBe(405);
    expect(Task::count())->toBe(0);
});

test('context preserves numeric identifiers while protocol user id is a string', function (): void {
    /** @var HttpTestCase $this */
    $user = (new User)->forceFill(['id' => 42]);
    $request = Request::create('/zero/query', 'POST');
    $request->setUserResolver(fn () => $user);

    $context = (new ContextResolver)($request);
    $this->assertSame(42, $context->get('user_id'));
    $this->assertSame('42', $context->userId());
});

test('cache requests are authenticated separately from end users', function (): void {
    /** @var HttpTestCase $this */
    $this->postJson('/zero/query', ['transform', []])->assertForbidden();
});

test('query endpoint uses authenticated identity instead of body context', function (): void {
    /** @var HttpTestCase $this */
    $user = (new User)->setKeyType('string');
    $user->forceFill(['id' => 'alice']);
    $this->actingAs($user)->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', [['id' => 'q', 'name' => 'Task.all', 'args' => [(object) []]]]])
        ->assertOk()->assertJsonPath('userID', 'alice')->assertJsonPath('queries.0.ast.where.conditions.0.right.value', 'alice');
});

test('bad json returns protocol error without leaking exceptions', function (): void {
    /** @var HttpTestCase $this */
    $this->withHeader('X-Api-Key', 'private-test-secret')->postJson('/zero/query', ['other', []])
        ->assertOk()->assertJsonPath('kind', 'TransformFailed')->assertJsonPath('reason', 'parse');
});

test('query transforms accept large revalidation batches while authorizing every query', function (): void {
    /** @var HttpTestCase $this */
    $user = (new User)->setKeyType('string');
    $user->forceFill(['id' => 'alice']);

    $queries = [];
    for ($index = 0; $index < 257; $index++) {
        $queries[] = ['id' => 'q'.$index, 'name' => 'Task.all', 'args' => [(object) []]];
    }

    $queries[] = ['id' => 'invalid', 'name' => 'Task.all', 'args' => [['filters' => ['secret' => 'forged']]]];
    $response = $this->actingAs($user)->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', $queries])
        ->assertOk()->assertJsonPath('kind', 'QueryResponse')->assertJsonCount(258, 'queries')
        ->assertJsonPath('queries.257.error', 'parse');
    for ($index = 0; $index < 257; $index++) {
        $response->assertJsonPath('queries.'.$index.'.ast.where.conditions.0.right.value', 'alice');
    }
});

test('the cache request size limit still bounds transform work', function (): void {
    /** @var HttpTestCase $this */
    $this->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', [[
            'id' => 'large', 'name' => 'Task.all', 'args' => [['filters' => ['title' => str_repeat('x', 1048576)]]],
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

test('generation owns named controller modules and removes only stale generated files', function (): void {
    $directory = sys_get_temp_dir().'/zero-public-'.uniqid();
    config(['zero.controller_output' => $directory.'/queries.php', 'zero.mutation_output' => $directory.'/mutations.php']);
    Route::post('/write', [ControllerMutationController::class, 'store']);
    Route::get('/tasks/{title}', [QueryController::class, 'index']);
    $options = ['--output' => $directory.'/generated.ts'];
    try {
        expect(Artisan::call('zero:generate', $options))->toBe(0);
        $controller = $directory.'/ZeroForLaravel/Tests/Fixtures/ControllerMutationController.ts';
        expect(File::get($controller))->toContain(' as store}', ' as StoreInput', '../../../generated.mutations');
        expect(File::get($directory.'/ZeroForLaravel/Tests/Fixtures/QueryController.ts'))->toContain(' as index}', '.request()', 'live: liveQueries');
        expect(File::exists($directory.'/pages.d.ts'))->toBeFalse();
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(0);
        File::put($directory.'/handwritten.ts', 'export const keep = true;');
        Route::setRoutes(new RouteCollection);
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(1);
        expect(File::exists($controller))->toBeTrue();
        expect(Artisan::call('zero:generate', $options))->toBe(0);
        expect(File::exists($controller))->toBeFalse();
        expect(File::get($directory.'/handwritten.ts'))->toBe('export const keep = true;');
        expect(Artisan::call('zero:generate', [...$options, '--check' => true]))->toBe(0);
    } finally {
        File::deleteDirectory($directory);
    }
});

class InvalidContextResolver
{
    public function __invoke(Request $request): string
    {
        return 'invalid';
    }
}

test('configured context resolvers must return trusted context', function (): void {
    /** @var HttpTestCase $this */
    config()->set('zero.context_resolver', InvalidContextResolver::class);
    \Pest\Laravel\withoutExceptionHandling();
    $this->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', []]);
})->throws(LogicException::class, 'must return ZeroContext');

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
        expect(file_get_contents($directory.'/generated.react.ts'))->toContain('createZeroProvider(schema, mutators, options, true, {actions, preparation})', '"./generated"');
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

test('configured discovery roots find opted-in modular models without publishing unrelated classes', function (): void {
    config()->set('zero.models', []);
    config()->set('zero.discovery', [['path' => __DIR__.'/../Fixtures/Module', 'namespace' => 'ZeroForLaravel\\Tests\\Fixtures\\Module']]);

    app()->forgetInstance(ModelRegistry::class);
    $registry = resolve(ModelRegistry::class);
    expect(array_keys($registry->all()))->toBe(['OutsideTask']);
});

test('exposure reports reflect the same private column and authorization metadata', function (): void {
    /** @var HttpTestCase $this */
    $command = $this->artisan('zero:exposure');
    $this->assertInstanceOf(PendingCommand::class, $command);
    $command->expectsOutputToContain('Published:')->expectsOutputToContain('Owner rules:')->assertSuccessful()->run();
});

test('mutation results are not exposed through a second read endpoint', function (): void {
    /** @var HttpTestCase $this */
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $this->actingAs($alice)->postJson('/zero/results/completeTasks', ['ids' => []])->assertNotFound();
});
