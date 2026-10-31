<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use ZeroForLaravel\Generation\ControllerDiscovery;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

test('finite FormRequest choices generate literal unions and runtime options without requiring optional fields', function (): void {
    app()->register(SurveyorServiceProvider::class);
    // QueryDirection is declared alongside this fixture controller.
    class_exists(QueryController::class);
    $contract = \ZeroForLaravel\Generation\RequestContract::value(\ZeroForLaravel\Tests\Fixtures\ChoiceQueryRequest::class);
    expect($contract->fields['direction']->choices())->toBe(['asc', 'desc'])
        ->and($contract->fields['priority']->choices())->toBe([2, 7])
        ->and($contract->fields['status']->choices())->toBe(['active', 'completed'])
        ->and($contract->fields['direction']->typescript())->toBe('"asc" | "desc" | null')
        ->and($contract->fields['direction']->optional)->toBeTrue();
    $schema = (new ZodSchemaDriver)->object(['input' => 'request:'.\ZeroForLaravel\Tests\Fixtures\ChoiceQueryRequest::class]);
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
        if (! $query instanceof Query) {
            throw new LogicException('Expected query declaration.');
        }

        expect(array_key_exists('where', $query->ast()))->toBe($title !== '');
        if ($title !== '') {
            expect(data_get($query->ast(), 'where.right.value'))->toBe($title);
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
        $context = new ZeroContext(['user_id' => 'alice']);
        $ast = $registered->compile(['title' => '0'], $context, $compiler);
        expect(data_get($ast, 'where.conditions.0.right.value'))->toBe('alice');
        expect(data_get($ast, 'where.conditions.2.right.value'))->toBe('0');
        foreach ([[], ['title' => null], ['title' => false], ['title' => '0', 'user_id' => 'other']] as $invalid) {
            expect(fn (): array => $registered->compile($invalid, $context, $compiler))->toThrow(ValidationException::class);
        }
    } finally {
        \Illuminate\Support\Facades\File::delete($path);
    }

    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'unsafe'))
        ->toThrow(LogicException::class, 'Statement or guard cannot be represented');
    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'unsafeSort'))
        ->toThrow(LogicException::class, 'Order direction requires asc/desc');
    expect(fn () => resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'unsafeNullable'))
        ->toThrow(LogicException::class, 'Concatenation requires strings without implicit coercion');
});
