<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Surveyor\SurveyorServiceProvider;
use PhpParser\ParserFactory;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\EffectSchemaDriver;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Generation\Mutations\ValueContract;
use ZeroForLaravel\Generation\Mutations\ZodMutationSchemaDriver;
use ZeroForLaravel\Generation\OptionQueryCompiler;
use ZeroForLaravel\Generation\PublicApiGenerator;
use ZeroForLaravel\Generation\TableCompiler;
use ZeroForLaravel\Generation\TableGenerator;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\Fixtures\ChoiceQueryRequest;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\DateQueryRequest;
use ZeroForLaravel\Tests\Fixtures\MutationPreference;
use ZeroForLaravel\Tests\Fixtures\PreferenceMutationController;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\TasksTable;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

require __DIR__.'/../../vendor/autoload.php';

// Reproducible cross-language fixture, backed by the same Testbench models as
// authoritative mutation tests. No controller is executed during generation.
$fixture = new class('export') extends TestCase
{
    public function export(string $mode, string $driver): string
    {
        $this->setUp();
        app()->register(SurveyorServiceProvider::class);
        $schemas = match ($driver) {
            'zod' => new ZodMutationSchemaDriver,
            'effect' => new EffectSchemaDriver,
            default => throw new LogicException('Unsupported fixture schema driver.'),
        };
        if ($mode === 'schema-parity') {
            // Small real generator outputs: runtime parity and inferred types share these fixtures.
            class_exists(QueryController::class);
            $draft = new ValueContract('object', fields: [
                'title' => new ValueContract('string'),
                'at' => new ValueContract('date', nullable: true),
                'day' => new ValueContract('dateOnly'),
                'priority' => new ValueContract('int', optional: true, rules: ['choices:[2,7]']),
                'reminder' => new ValueContract('date', optional: true, nullable: true),
                'items' => new ValueContract('list', members: [new ValueContract('object', fields: ['at' => new ValueContract('date')])]),
            ]);
            $values = [
                'integer' => new ValueContract('int'),
                'number' => new ValueContract('number'),
                'boolean' => new ValueContract('bool'),
                'nullValue' => new ValueContract('null'),
                'voidValue' => new ValueContract('void'),
                'empty' => new ValueContract('object'),
                'fields' => new ValueContract('object', fields: [
                    'required' => new ValueContract('string'),
                    'nullable' => new ValueContract('string', nullable: true),
                    'optional' => new ValueContract('string', optional: true),
                    'both' => new ValueContract('string', optional: true, nullable: true),
                ]),
                'text' => new ValueContract('string', rules: ['min:2', 'max:3']),
                'boundedNumber' => new ValueContract('number', rules: ['min:2', 'max:7']),
                'uuid' => new ValueContract('string', rules: ['uuid']),
                'ulid' => new ValueContract('string', rules: ['ulid']),
                'date' => new ValueContract('date'),
                'dateOnly' => new ValueContract('dateOnly'),
                'dateWire' => new ValueContract('dateWire'),
                'dateOnlyWire' => new ValueContract('dateOnlyWire'),
                'tuple' => new ValueContract('tuple', members: [new ValueContract('string'), new ValueContract('int')]),
                'list' => new ValueContract('list', members: [new ValueContract('string')], rules: ['min:1', 'max:2']),
                'union' => new ValueContract('union', members: [new ValueContract('string'), new ValueContract('int')]),
                'choices' => new ValueContract('int', rules: ['choices:[2,7]', 'min:3']),
                'draft' => $draft,
                'wireDraft' => $draft->wire(),
            ];
            $fields = [];
            foreach ($values as $name => $contract) {
                $fields[] = $name.': '.$schemas->value($contract);
            }
            $parameters = ['direction' => 'enum:ZeroForLaravel\\Tests\\Fixtures\\QueryDirection', 'priority' => 'enum:'.SchemaParityPriority::class];
            $fields[] = 'routed: '.$schemas->object($parameters, ['title' => 'nullable:string', 'id' => 'string']);
            $fields[] = 'publicQuery: '.$schemas->object($parameters, public: true);
            $fields[] = 'scalars: '.$schemas->object(['text' => 'string', 'active' => 'bool', 'score' => 'float', 'limit' => 'int'], routed: false);
            $fields[] = 'internalOption: '.$schemas->object([], routed: false);
            $fields[] = 'publicOption: '.$schemas->object([], public: true, routed: false);
            $fields[] = 'windowOption: '.$schemas->object([], routed: false, window: true);
            $request = ['input' => 'request:'.DateQueryRequest::class];
            $fields[] = 'requestPublic: '.$schemas->object($request, public: true);
            $fields[] = 'requestWire: '.$schemas->object($request);
            $fields[] = 'contractQuery: '.$schemas->object(['input' => 'string'], public: true, contracts: ['input' => ZeroForLaravel\Generation\RequestContract::value(ChoiceQueryRequest::class)], routed: false);

            return '// Generated by export-controller-mutations.php schema-parity '.$driver.".\n"
                .$schemas->imports()."\nexport const schemas = {\n".implode(",\n", $fields)."\n};\n"
                .'export type DraftInput = '.$schemas->inputType('schemas.draft').";\n"
                .'export type QueryInput = '.$schemas->inputType('schemas.requestPublic').";\n"
                .'export const encodeDraft = (input: DraftInput) => { const parsed = '.$schemas->parse('schemas.draft', 'input').'; return '.$draft->encode('parsed')."; };\n"
                .'export const encodeQuery = (input: QueryInput) => { const parsed = '.$schemas->parse('schemas.requestPublic', 'input').'; return '.$schemas->encode($request, 'parsed')."; };\n";
        }

        if ($mode === 'table') {
            config(['zero.route_prefix' => 'zero']);
            $models = new ModelRegistry($this->models());
            app()->instance(ModelRegistry::class, $models);
            $table = (new TableCompiler($models))->compile('tasks', TasksTable::class);

            return (new TableGenerator)->typescript([$table], './generated')['tables/tasks.ts'];
        }

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

            return (new TypeScriptGenerator)->generate($models)."\n".(new ControllerMutationGenerator($schemas))->typescript([$program]);
        }

        if ($mode === 'queries') {
            $compiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), new ModelRegistry($this->models()));
            $query = $compiler->compile(QueryController::class, 'dated')[0];

            return (new ControllerQueryGenerator($schemas))->typescript([$query], './generated');
        }

        $compiler = new ControllerMutationCompiler((new ParserFactory)->createForNewestSupportedVersion(), new ModelRegistry($this->models()));
        if ($mode === 'options') {
            $models = new ModelRegistry($this->models());
            app()->instance(ModelRegistry::class, $models);
            $queries = (new OptionQueryCompiler(new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), $models), $models))->compile($compiler->compile(ControllerMutationController::class, 'options'));
            $metadata = [];
            foreach ($queries as $query) {
                $metadata[$query->options()['operation']] = ['name' => $query->name(), 'inputs' => $query->options()['inputs']];
            }

            return (new ControllerQueryGenerator($schemas))->typescript($queries, './generated')."\nexport const optionQueries = ".json_encode($metadata, JSON_THROW_ON_ERROR)." as const;\n";
        }
        $programs = array_map(fn (string $method): MutationProgram => $compiler->compile(ControllerMutationController::class, $method), ['store', 'complete', 'authoritative', 'noResult', 'explicit', 'structured', 'projected', 'bulk']);

        if ($mode === 'public') {
            $queryCompiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), new ModelRegistry($this->models()));
            $queries = [...$queryCompiler->compile(QueryController::class, 'index'), ...$queryCompiler->compile(QueryController::class, 'show'), ...$queryCompiler->compile(QueryController::class, 'dated'), ...$queryCompiler->compile(QueryController::class, 'choices')];
            $files = (new PublicApiGenerator)->generate($programs, $queries, 'generated');
            $files['generated.mutations.ts'] = $driver === 'zod' ? "export * from '../../../packages/client/src/controller-mutation.fixture';\n" : (new ControllerMutationGenerator($schemas))->typescript($programs);
            $files['generated.queries.ts'] = (new ControllerQueryGenerator($schemas))->typescript($queries, '../generated');

            return json_encode($files, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }

        // Standalone process owns this in-memory DB, not a PHPUnit runner lifecycle.
        return (new ControllerMutationGenerator($schemas))->typescript($programs);
    }
};

enum SchemaParityPriority: int
{
    case Normal = 2;
    case High = 7;
}

echo $fixture->export($argv[1] ?? 'mutations', $argv[2] ?? 'zod');
