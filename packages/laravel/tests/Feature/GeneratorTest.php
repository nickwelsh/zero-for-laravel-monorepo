<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

test('generates native schema and plain row contracts without the old model API', function (): void {
    /** @var TestCase $this */
    $output = (new TypeScriptGenerator)->generate(new ModelRegistry($this->models()));
    $this->assertStringContainsString('enableLegacyQueries: false', $output);
    $this->assertStringContainsString('enableLegacyMutators: false', $output);
    $this->assertStringContainsString('export type TaskRow = ModelRow', $output);
    $this->assertStringNotContainsString('createZeroForLaravel', $output);
    $this->assertStringNotContainsString('secret', $output);
    $this->assertSame($output, (new TypeScriptGenerator)->generate(new ModelRegistry(array_reverse($this->models()))));
});

test('only destructures relationship helpers used by each model', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $task = $registry->get('Task');
    $task->relations = ['list' => $task->relations['list']];
    $task->definition->includes = ['list'];

    $output = (new TypeScriptGenerator)->generate($registry);

    $this->assertMatchesRegularExpression('/relationships\(table\d+, \(\{one\}\)/', $output);
    $this->assertMatchesRegularExpression('/relationships\(table\d+, \(\{many\}\)/', $output);
    $this->assertStringNotContainsString('({one, many})', $output);
});
