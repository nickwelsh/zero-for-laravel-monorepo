<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

uses(TestCase::class);

test('a generated report describes display fields separately from related filter paths', function (): void {
    /** @var TestCase $this */
    $models = new ModelRegistry($this->models());
    app()->instance(ModelRegistry::class, $models);
    config(['zero.route_prefix' => 'zero']);
    $definition = (new \ZeroForLaravel\Generation\TableCompiler($models))->compile('report', \ZeroForLaravel\Tests\Fixtures\TaskReportTable::class);
    $capabilities = $definition->capabilities();
    expect($capabilities['version'])->toBe(3)
        ->and($capabilities['displayColumns'])->toBe([['field' => 'title', 'label' => 'Task title'], ['field' => 'tags', 'label' => 'Assigned tags']])
        ->and(array_keys((array) $capabilities['relations']))->toBe(['tags', 'list'])
        ->and($capabilities['sorts'])->toBe([]);
});

test('generates native schema and plain row contracts', function (): void {
    /** @var TestCase $this */
    $output = (new TypeScriptGenerator)->generate(new ModelRegistry($this->models()));
    $this->assertStringContainsString('enableLegacyQueries: false', $output);
    $this->assertStringContainsString('enableLegacyMutators: false', $output);
    $this->assertStringContainsString('export type TaskRow = ModelRow', $output);
    $this->assertStringNotContainsString('secret', $output);
    $this->assertSame($output, (new TypeScriptGenerator)->generate(new ModelRegistry(array_reverse($this->models()))));
});

test('only destructures relationship helpers used by each model', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $task = $registry->get('Task');
    $registry->relation($task, 'list');
    $registry->relation($registry->get('TaskList'), 'tasks');

    $output = (new TypeScriptGenerator)->generate($registry);

    $this->assertMatchesRegularExpression('/relationships\(table\d+, \(\{one\}\)/', $output);
    $this->assertMatchesRegularExpression('/relationships\(table\d+, \(\{many\}\)/', $output);
    $this->assertStringNotContainsString('({one, many})', $output);
});
