<?php

declare(strict_types=1);

require __DIR__.'/../../packages/laravel/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\Fixtures\ContextResolver;
use ZeroForLaravel\Tests\Fixtures\ZeroContext;

$container = new Container;
$container->instance('config', new Illuminate\Config\Repository([
    'zero' => ['context' => ZeroContext::class, 'context_resolver' => ContextResolver::class],
]));
$capsule = new Manager($container);
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
if (($argv[1] ?? null) === 'generate') {
    echo (new TypeScriptGenerator)->generate($registry);
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
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $queryCompiler = new QueryCompiler($registry);
    $factory = eval('return '.$definition->php().';');
    $cases = [];
    foreach (['', '0', 'actual'] as $title) {
        $query = $factory(['title' => $title]);
        $cases[] = ['args' => ['title' => $title], 'ast' => $queryCompiler->compileDeclaration($query, $context), 'format' => $query->format()];
    }
    $filteredFactory = eval('return '.$filtered->php().';');
    $filteredCases = [];
    foreach ([['title' => '', 'showDone' => false, 'window' => 0], ['title' => '0', 'showDone' => true, 'window' => 2], ['title' => 'actual', 'showDone' => false, 'window' => 3]] as $args) {
        $query = $filteredFactory($args);
        $filteredCases[] = ['args' => $args, 'ast' => $queryCompiler->compileDeclaration($query, $context), 'format' => $query->format()];
    }
    $relationshipFactory = eval('return '.$relationships->php().';');
    $relationshipQuery = $relationshipFactory(['title' => '0']);
    $windowFactory = eval('return '.$window->php().';');
    $windowQuery = $windowFactory([]);
    echo json_encode([
        'name' => $definition->name(),
        'context' => $context->values,
        'sorted' => ['typescript' => $sorted->typescript(), 'schema' => $schemas->object($sorted->parameters)],
        'typescript' => $definition->typescript(), 'schema' => $schemas->object($definition->parameters), 'cases' => $cases,
        'filtered' => ['typescript' => $filtered->typescript(), 'schema' => $schemas->object($filtered->parameters), 'cases' => $filteredCases],
        'relationships' => ['typescript' => $relationships->typescript(), 'ast' => $queryCompiler->compileDeclaration($relationshipQuery, $context), 'format' => $relationshipQuery->format()],
        'window' => ['typescript' => $window->typescript(), 'ast' => $queryCompiler->compileDeclaration($windowQuery, $context)],
    ], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? null) === 'declarations') {
    // Native construction parity intentionally excludes application authorization.
    // The authorized mode below separately verifies all root and target scopes.
    $query = (new ZeroQuery($registry->get('Task'), $registry))->withoutGlobalScopes();
    $queries = [
        'unbounded' => clone $query,
        'zeroLimit' => (clone $query)->limit(0),
        'one' => (clone $query)->where('id', 'task-a')->one(),
        'cursor' => (clone $query)->orderBy('title', 'desc')->orderBy('id', 'asc')->start(['title' => '0', 'id' => 'task-a'], ['inclusive' => true])->limit(7),
        'exclusive' => (clone $query)->orderBy('id', 'asc')->start(['id' => 'task-a']),
        'relationships' => (clone $query)->with('comments', fn ($q) => $q->withoutGlobalScopes()->where('body', 'NOT ILIKE', '%private%')->orderBy('id', 'desc')->limit(3))->with('list', fn ($q) => $q->withoutGlobalScopes()),
        'junction' => (clone $query)->with('tags', fn ($q) => $q->withoutGlobalScopes()->where('name', 'IN', ['0', 'work'])),
        'exists' => (clone $query)->whereExists('comments', fn ($q) => $q->withoutGlobalScopes()->where('body', 'NOT LIKE', 'secret%'), ['flip' => false]),
        'scalar' => (clone $query)->whereExists('list', fn ($q) => $q->withoutGlobalScopes()->where('id', 'list-a'), ['scalar' => true]),
        'junctionExists' => (clone $query)->whereExists('tags', fn ($q) => $q->withoutGlobalScopes()->where('name', 'work'), ['flip' => true]),
        'negation' => (clone $query)->where('done', '!=', false)->whereDoesntHave('comments', fn ($q) => $q->withoutGlobalScopes()->where('body', 'hidden')),
        'nullEquality' => (clone $query)->where('list_id', null),
        'nullInequality' => (clone $query)->where('list_id', '!=', null),
    ];
    foreach (['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'] as $operator) {
        $queries[$operator] = (clone $query)->where('title', $operator, '0');
    }
    foreach (['IN', 'NOT IN'] as $operator) {
        $queries[$operator] = (clone $query)->where('title', $operator, ['0', 'two']);
    }
    foreach (['IS', 'IS NOT'] as $operator) {
        $queries[$operator] = (clone $query)->where('list_id', $operator, null);
    }
    $output = array_map(fn ($q) => ['ast' => $q->ast(), 'format' => $q->format()], $queries);
    // Literal-only and empty expressions exercise the native internal expression
    // builder directly; grouped public callbacks now receive a mutable ZeroQuery.
    $eb = $query->expressionBuilder();
    $expressions = [
        'literals' => $eb->or($eb->cmpLit('0', '=', '0'), $eb->and($eb->cmpLit(0, '!=', 1), $eb->cmp('list_id', 'IS', null))),
        'emptyGroups' => $eb->and($eb->or(), $eb->cmp('done', true)),
        'missing' => $eb->or(ZeroForLaravel\Query\MissingValue::Missing, $eb->cmp('id', ZeroForLaravel\Query\MissingValue::Missing)),
    ];
    foreach ($expressions as $name => $condition) {
        $output[$name] = ['ast' => ['table' => 'tasks', 'where' => $condition->toArray()], 'format' => $query->format()];
    }
    echo json_encode($output, JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? null) === 'authorized') {
    $query = new ZeroQuery($registry->get('Task'), $registry);
    $queries = [
        'root' => clone $query,
        'related' => (clone $query)->with('list')->with('comments', fn ($q) => $q->limit(3)),
        'junction' => (clone $query)->whereHas('tags', fn ($q) => $q->where('id', 'owned')),
        'groups' => (clone $query)->where(fn (ZeroQuery $q) => $q
            ->whereHas('tags', fn ($q) => $q->where('name', 'Reading'))
            ->orWhere(fn (ZeroQuery $q) => $q->whereHas('tags', fn ($q) => $q->where('name', 'Other')))),
    ];
    $compiler = new QueryCompiler($registry);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $asts = array_map(fn ($q) => $compiler->compileDeclaration($q, $context), $queries);
    Task::addZeroGlobalScope('shared', function (ZeroQuery $query): void {
        $query->where(fn (ZeroQuery $q) => $q->where('done', true)->orWhere('title', 'shared'));
    });
    $asts['scope'] = $compiler->compileDeclaration($query, $context);
    echo json_encode($asts, JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown controller interop fixture mode.');
}
