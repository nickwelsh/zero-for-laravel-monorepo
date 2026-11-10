<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use ZeroForLaravel\Generation\ControllerDiscovery;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\NavigationGenerator;
use ZeroForLaravel\Generation\RequestContract;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\ChoiceQueryRequest;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    request()->setUserResolver(fn () => $alice);
});

test('navigation generation preserves real route identities and only resolves known input codecs', function (): void {
    /** @var TestCase $this */
    app()->register(SurveyorServiceProvider::class);
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));

    $route = Route::get('/tasks/{label}', [QueryController::class, 'index'])->name('tasks.index');
    Route::get('/alternate/{label?}', [QueryController::class, 'index'])->defaults('label', 'default');
    Route::get('/constrained/{label}', [QueryController::class, 'index'])->where('label', '[a-z]+');
    Route::get('/search', [QueryController::class, 'search']);
    Route::get('/dated', [QueryController::class, 'dated']);
    Route::get('/choices', [QueryController::class, 'choices']);
    Route::get('/tasks/create', fn (): string => 'Not a live page');
    $definitions = array_values(array_column(resolve(ControllerDiscovery::class)->queries(), 'query'));
    $source = (new NavigationGenerator)->typescript($definitions);
    $routes = json_decode(substr($source, strlen('export const navigation = '), -strlen(" as const;\n")), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($routes)) {
        throw new LogicException('Expected generated navigation routes.');
    }

    $byPattern = array_column($routes, null, 'pattern');
    $index = $byPattern['^/tasks/(?<label>[^/]+)$'];
    expect(data_get($index, 'component'))->toBe('tasks/index')
        ->and(data_get($index, 'bindings.tasks.parameters'))->toBe(['title' => ['route' => 'label', 'type' => 'string']])
        ->and(data_get($index, 'route'))->toBe(\ZeroForLaravel\Http\ControllerRouteDispatcher::identity($route))
        ->and(data_get($byPattern['^/alternate(?:/(?<label>[^/]+))?$'], 'defaults'))->toBe(['label' => 'default'])
        ->and(data_get($byPattern['^/constrained/(?<label>.*)$'], 'bindings.tasks.parameters'))->toBeNull()
        ->and(data_get($byPattern['^/search$'], 'bindings.tasks.parameters'))->toBe(['input' => ['query' => ['title']]])
        ->and(data_get($byPattern['^/dated$'], 'bindings.tasks.parameters'))->toBeNull()
        ->and(data_get($byPattern['^/choices$'], 'bindings.tasks.parameters'))->toBeNull()
        ->and(data_get($byPattern['^/choices$'], 'bindings.tasks.options'))->toBe([
            'direction' => ['asc', 'desc'], 'priority' => [2, 7], 'status' => ['active', 'completed'],
        ])
        ->and(data_get($byPattern['^/tasks/create$'], 'component'))->toBeNull();
});

test('finite FormRequest choices generate literal unions and runtime options without requiring optional fields', function (): void {
    app()->register(SurveyorServiceProvider::class);
    // QueryDirection is declared alongside this fixture controller.
    class_exists(QueryController::class);
    $contract = RequestContract::value(ChoiceQueryRequest::class);
    expect($contract->fields['direction']->choices())->toBe(['asc', 'desc'])
        ->and($contract->fields['priority']->choices())->toBe([2, 7])
        ->and($contract->fields['status']->choices())->toBe(['active', 'completed'])
        ->and($contract->fields['direction']->typescript())->toBe('"asc" | "desc" | null')
        ->and($contract->fields['direction']->optional)->toBeTrue();
    $schema = (new ZodSchemaDriver)->object(['input' => 'request:'.ChoiceQueryRequest::class]);
    expect($schema)->toContain('z.literal(2)', 'z.literal(7)', 'z.literal("active")', '.nullable().optional()');
});

test('registered controller queries preserve input branches without executing the HTTP action', function (): void {
    /** @var TestCase $this */
    app()->register(SurveyorServiceProvider::class);
    app()->instance(ModelRegistry::class, new ModelRegistry($this->models()));
    Route::get('/tasks/{title}', [QueryController::class, 'index']);
    Route::get('/alternate/{title}', [QueryController::class, 'index']);
    $definitions = resolve(ControllerDiscovery::class)->queries();
    expect($definitions)->toHaveCount(1);
    $entry = array_values($definitions)[0];
    expect($entry['routes'])->toHaveCount(2);
    $definition = $entry['query'];
    expect($definition->component)->toBe('tasks/index');
    expect($definition->prop)->toBe('tasks');
    expect($definition->parameters)->toBe(['title' => 'string']);

    $factory = eval('return '.$definition->php().';');
    if (! is_callable($factory)) {
        throw new LogicException('Expected generated factory.');
    }

    foreach (['', '0', 'actual'] as $title) {
        $query = $factory(['title' => $title]);
        if (! $query instanceof ZeroQuery) {
            throw new LogicException('Expected query declaration.');
        }

        $ast = (clone $query)->withoutGlobalScopes()->ast();
        expect(array_key_exists('where', $ast))->toBe($title !== '');
        if ($title !== '') {
            expect(data_get($ast, 'where.right.value'))->toBe($title);
        }
    }

    expect($definition->typescript())->toContain('zql["tasks"]', 'args["title"] !== ""');
    $path = tempnam(sys_get_temp_dir(), 'zero-controller-');
    if ($path === false) {
        throw new LogicException('Cannot create generated registration fixture.');
    }

    try {
        file_put_contents($path, (new ControllerQueryGenerator(new ZodSchemaDriver))->php([$definition]));
        $registrations = require $path;
        if (! is_array($registrations) || ! ($registrations[$definition->name()] ?? null) instanceof RegisteredQuery) {
            throw new LogicException('Expected generated registration.');
        }

        $registered = $registrations[$definition->name()];
        $compiler = new QueryCompiler(resolve(ModelRegistry::class));
        $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
        $ast = $registered->compile(['title' => '0'], $context, $compiler);
        expect(json_encode(data_get($ast, 'where.conditions.0'), JSON_THROW_ON_ERROR))->toContain('permission_index', 'user:alice');
        expect(data_get($ast, 'where.conditions.2.right.value'))->toBe('0');
        foreach ([[], ['title' => null], ['title' => false], ['title' => '0', 'user_id' => 'other']] as $invalid) {
            expect(fn (): array => $registered->compile($invalid, $context, $compiler))->toThrow(ValidationException::class);
        }
    } finally {
        File::delete($path);
    }

    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'unsafe'))
        ->toThrow(LogicException::class, 'Statement or guard cannot be represented');
    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'unsafeSort'))
        ->toThrow(LogicException::class, 'Order direction requires asc/desc');
    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'unsafeNullable'))
        ->toThrow(LogicException::class, 'Concatenation requires strings without implicit coercion');
});
