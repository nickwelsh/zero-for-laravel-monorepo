<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Surveyor\SurveyorServiceProvider;
use PhpParser\ParserFactory;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\MutationPreference;
use ZeroForLaravel\Tests\Fixtures\PreferenceMutationController;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\TestCase;

require __DIR__.'/../../vendor/autoload.php';

// Reproducible cross-language fixture, backed by the same Testbench models as
// authoritative mutation tests. No controller is executed during generation.
$fixture = new class('export') extends TestCase
{
    public function export(string $mode): string
    {
        $this->setUp();
        app()->register(SurveyorServiceProvider::class);
        if ($mode === 'react') {
            return (new TypeScriptGenerator)->generateReact('./generated', './assignment-options.generated', '../../packages/client/src/controller-mutation.fixture', true);
        }

        if ($mode === 'assignment') {
            Schema::create('mutation_preferences', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('recipient');
                $table->string('issue');
                $table->boolean('subscribed');
                $table->string('note')->default('keep');
                $table->timestamps();
                $table->unique(['recipient', 'issue']);
            });
            $models = new ModelRegistry([...$this->models(), MutationPreference::class]);
            $compiler = new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), $models);
            $program = $compiler->compile(PreferenceMutationController::class, 'assign');

            return (new TypeScriptGenerator)->generate($models)."\n".(new ControllerMutationGenerator)->typescript([$program]);
        }

        if ($mode === 'queries') {
            $compiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), new ModelRegistry($this->models()));
            $query = $compiler->compile(QueryController::class, 'dated')[0];

            return (new ControllerQueryGenerator(new ZodSchemaDriver))->typescript([$query], './generated');
        }

        $compiler = new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), new ModelRegistry($this->models()));
        $programs = array_map(fn (string $method): ZeroForLaravel\Generation\Mutations\MutationProgram => $compiler->compile(ControllerMutationController::class, $method), ['store', 'complete', 'authoritative', 'noResult', 'explicit', 'structured', 'projected', 'bulk']);

        // Standalone process owns this in-memory DB, not a PHPUnit runner lifecycle.
        return (new ControllerMutationGenerator)->typescript($programs);
    }
};

echo $fixture->export($argv[1] ?? 'mutations');
