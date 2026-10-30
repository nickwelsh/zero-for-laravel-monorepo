<?php

declare(strict_types=1);

require __DIR__.'/../../packages/laravel/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use ZeroForLaravel\Definition\AllowedFilter;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\ZeroContext;

$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$capsule->schema()->create('tasks', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('user_id');
    $table->uuid('list_id')->nullable();
    $table->string('title');
    $table->boolean('done')->default(false);
    $table->timestamp('completed_at')->nullable();
    $table->string('secret')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
$capsule->schema()->create('lists', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('user_id');
    $table->string('name');
});
$capsule->schema()->create('tags', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('user_id');
    $table->string('name');
});
$capsule->schema()->create('task_tag', function (Blueprint $table) {
    $table->uuid('task_id');
    $table->uuid('tag_id');
    $table->primary(['task_id', 'tag_id']);
});
$capsule->schema()->create('comments', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('task_id');
    $table->string('user_id');
    $table->string('body');
});
Container::setInstance($capsule->getContainer());
$registry = new ModelRegistry([Task::class, TaskList::class, Tag::class, Comment::class]);
if (($argv[3] ?? null) === 'scope') {
    $registry->get('Task')->definition->enforceServerScope(fn (ZeroForLaravel\Query\ZQLBuilder $query) => $query->where(fn (ZeroForLaravel\Query\ZeroOperator $op) => $op->or($op->cmp('done', true), $op->cmp('title', 'shared'))));
}
if (in_array($argv[3] ?? null, ['junction', 'junction-scope'], true)) {
    $registry->get('Task')->definition->allowedFilters([
        AllowedFilter::related('tag', 'tags', 'id'),
        AllowedFilter::conditions('rules', [AllowedFilter::related('tag', 'tags', 'name')]),
    ]);
    $registry->get('Tag')->definition->defaultSort('name');
    if ($argv[3] === 'junction-scope') {
        $registry->get('Task')->definition->enforceServerScope(fn (ZeroForLaravel\Query\ZQLBuilder $query) => $query->whereExists('tags'), relations: ['tags']);
    }
}
if (($argv[1] ?? null) === 'generate') {
    echo (new TypeScriptGenerator)->generate($registry);
    // Exercise the retained internal engines without publishing their former model API.
    echo "import {createZeroForLaravel} from '../../packages/client/src/runtime';\n";
    echo "import type {ModelInstance} from '../../packages/client/src/record';\n";
    echo "export const {models, queries, mutators} = createZeroForLaravel(schema, definitions);\n";
    foreach ($registry->all() as $model) {
        echo 'export const '.$model->name.' = models.'.$model->name.";\n";
        echo 'export type '.$model->name.' = ModelInstance<typeof schema, typeof definitions, '.json_encode($model->name, JSON_THROW_ON_ERROR).">;\n";
    }
} elseif (in_array($argv[1] ?? null, ['controller', 'controller-module'], true)) {
    Container::getInstance()->instance(ModelRegistry::class, $registry);
    $compiler = new ZeroForLaravel\Generation\ControllerQueryCompiler((new PhpParser\ParserFactory)->createForHostVersion(), $registry);
    $definition = $compiler->compile(ZeroForLaravel\Tests\Fixtures\QueryController::class, 'index')[0];
    $filtered = $compiler->compile(ZeroForLaravel\Tests\Fixtures\QueryController::class, 'filtered')[0];
    $relationships = $compiler->compile(ZeroForLaravel\Tests\Fixtures\QueryController::class, 'relationships')[0];
    $window = $compiler->compile(ZeroForLaravel\Tests\Fixtures\QueryController::class, 'window')[0];
    $pages = $compiler->compile(ZeroForLaravel\Tests\Fixtures\QueryController::class, 'pages')[0];
    $sorted = $compiler->compile(ZeroForLaravel\Tests\Fixtures\QueryController::class, 'sorted')[0];
    $schemas = new ZeroForLaravel\Generation\ZodSchemaDriver;
    if ($argv[1] === 'controller-module') {
        echo (new ZeroForLaravel\Generation\ControllerQueryGenerator($schemas))->typescript([$definition, $filtered, $relationships, $window, $pages, $sorted], './generated');
        exit;
    }
    $factory = eval('return '.$definition->php().';');
    $cases = [];
    foreach (['', '0', 'actual'] as $title) {
        $query = $factory(['title' => $title]);
        $cases[] = ['args' => ['title' => $title], 'ast' => $query->ast(), 'format' => $query->format()];
    }
    $filteredFactory = eval('return '.$filtered->php().';');
    $filteredCases = [];
    foreach ([['title' => '', 'showDone' => false, 'window' => 0], ['title' => '0', 'showDone' => true, 'window' => 2], ['title' => 'actual', 'showDone' => false, 'window' => 3]] as $args) {
        $query = $filteredFactory($args);
        $filteredCases[] = ['args' => $args, 'ast' => $query->ast(), 'format' => $query->format()];
    }
    $relationshipFactory = eval('return '.$relationships->php().';');
    $relationshipQuery = $relationshipFactory(['title' => '0']);
    $windowFactory = eval('return '.$window->php().';');
    $windowQuery = $windowFactory([]);
    echo json_encode([
        'name' => $definition->name(),
        'sorted' => ['typescript' => $sorted->typescript(), 'schema' => $schemas->object($sorted->parameters)],
        'typescript' => $definition->typescript(), 'schema' => $schemas->object($definition->parameters), 'cases' => $cases,
        'filtered' => ['typescript' => $filtered->typescript(), 'schema' => $schemas->object($filtered->parameters), 'cases' => $filteredCases],
        'relationships' => ['typescript' => $relationships->typescript(), 'ast' => $relationshipQuery->ast(), 'format' => $relationshipQuery->format()],
        'window' => ['typescript' => $window->typescript(), 'ast' => $windowQuery->ast()],
    ], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? null) === 'declarations') {
    $query = new ZeroForLaravel\Query\Query($registry->get('Task'), $registry);
    $queries = [
        'unbounded' => $query->all(),
        'zeroLimit' => $query->limit(0),
        'one' => $query->where('id', 'task-a')->one(),
        'cursor' => $query->orderBy('title', 'desc')->orderBy('id', 'asc')->start(['title' => '0', 'id' => 'task-a'], ['inclusive' => true])->limit(7),
        'exclusive' => $query->orderBy('id', 'asc')->start(['id' => 'task-a']),
        'relationships' => $query->related('comments', fn ($q) => $q->where('body', 'NOT ILIKE', '%private%')->orderBy('id', 'desc')->limit(3))->related('list'),
        'junction' => $query->related('tags', fn ($q) => $q->where('name', 'IN', ['0', 'work'])),
        'exists' => $query->whereExists('comments', fn ($q) => $q->where('body', 'NOT LIKE', 'secret%'), ['flip' => false]),
        'scalar' => $query->whereExists('list', fn ($q) => $q->where('id', 'list-a'), ['scalar' => true]),
        'junctionExists' => $query->whereExists('tags', fn ($q) => $q->where('name', 'work'), ['flip' => true]),
        'negation' => $query->where(fn ($eb) => $eb->not($eb->or($eb->cmp('done', false), $eb->exists('comments', fn ($q) => $q->where('body', 'hidden'))))),
        'literals' => $query->where(fn ($eb) => $eb->or($eb->cmpLit('0', '=', '0'), $eb->and($eb->cmpLit(0, '!=', 1), $eb->cmp('list_id', 'IS', null)))),
        'emptyGroups' => $query->where(fn ($eb) => $eb->and($eb->or(), $eb->cmp('done', true))),
        'missing' => $query->where(fn ($eb) => $eb->or(ZeroForLaravel\Query\MissingValue::Missing, $eb->cmp('id', ZeroForLaravel\Query\MissingValue::Missing))),
    ];
    foreach (['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'] as $operator) {
        $queries[$operator] = $query->where('title', $operator, '0');
    }
    foreach (['IN', 'NOT IN'] as $operator) {
        $queries[$operator] = $query->where('title', $operator, ['0', 'two']);
    }
    foreach (['IS', 'IS NOT'] as $operator) {
        $queries[$operator] = $query->where('list_id', $operator, null);
    }
    echo json_encode(array_map(fn ($q) => ['ast' => $q->ast(), 'format' => $q->format()], $queries), JSON_THROW_ON_ERROR);
} else {
    $args = json_decode($argv[2] ?? '{}', flags: JSON_THROW_ON_ERROR);
    echo json_encode((new QueryCompiler($registry))->compile($argv[1] ?? 'Task.all', $args, new ZeroContext(['user_id' => 'alice'])), JSON_THROW_ON_ERROR);
}
