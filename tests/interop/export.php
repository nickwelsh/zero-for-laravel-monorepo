<?php

declare(strict_types=1);

require __DIR__.'/../../packages/laravel/vendor/autoload.php';

use Illuminate\Foundation\Auth\User;
use PhpParser\ParserFactory;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\MissingValue;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;

$fixture = new class('bootFixture') extends TestCase
{
    public function bootFixture(): ModelRegistry
    {
        $this->setUp();
        $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
        request()->setUserResolver(fn () => $alice);
        $registry = new ModelRegistry($this->models());
        app()->instance(ModelRegistry::class, $registry);
        // This fixture exports every relationship used by the cross-language cases.
        // Production query analysis collects just the paths an application uses.
        foreach (['list', 'tags', 'comments'] as $name) {
            $registry->relation($registry->get('Task'), $name);
        }

        $registry->relation($registry->get('TaskList'), 'tasks');

        return $registry;
    }
};
$registry = $fixture->bootFixture();

/** Public construction AST, excluding trusted PHP-only actor markers. */
function publicAst(ZeroQuery $query): array
{
    $strip = function (array $value) use (&$strip): array {
        unset($value['__visibility'], $value['__ability']);
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $strip($child);
            }
        }

        return $value;
    };

    return $strip($query->ast());
}
if (($argv[1] ?? null) === 'generate') {
    echo (new TypeScriptGenerator)->generate($registry);
} elseif (in_array($argv[1] ?? null, ['controller', 'controller-module'], true)) {
    $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), $registry);
    $definition = $compiler->compile(QueryController::class, 'index')[0];
    $filtered = $compiler->compile(QueryController::class, 'filtered')[0];
    $relationships = $compiler->compile(QueryController::class, 'relationships')[0];
    $window = $compiler->compile(QueryController::class, 'window')[0];
    $pages = $compiler->compile(QueryController::class, 'pages')[0];
    $sorted = $compiler->compile(QueryController::class, 'sorted')[0];
    $schemas = new ZodSchemaDriver;
    if ($argv[1] === 'controller-module') {
        echo (new ControllerQueryGenerator($schemas))->typescript([$definition, $filtered, $relationships, $window, $pages, $sorted], './generated');
        exit;
    }
    $factory = eval('return '.$definition->php().';');
    $cases = [];
    foreach (['', '0', 'actual'] as $title) {
        $query = $factory(['title' => $title]);
        $cases[] = ['args' => ['title' => $title], 'ast' => publicAst($query), 'format' => $query->format()];
    }
    $filteredFactory = eval('return '.$filtered->php().';');
    $filteredCases = [];
    foreach ([['title' => '', 'showDone' => false, 'window' => 0], ['title' => '0', 'showDone' => true, 'window' => 2], ['title' => 'actual', 'showDone' => false, 'window' => 3]] as $args) {
        $query = $filteredFactory($args);
        $filteredCases[] = ['args' => $args, 'ast' => publicAst($query), 'format' => $query->format()];
    }
    $relationshipFactory = eval('return '.$relationships->php().';');
    $relationshipQuery = $relationshipFactory(['title' => '0']);
    $windowFactory = eval('return '.$window->php().';');
    $windowQuery = $windowFactory([]);
    echo json_encode([
        'name' => $definition->name(),
        'context' => ['user_id' => 'alice'],
        'sorted' => ['typescript' => $sorted->typescript(), 'schema' => $schemas->object($sorted->parameters)],
        'typescript' => $definition->typescript(), 'schema' => $schemas->object($definition->parameters), 'cases' => $cases,
        'filtered' => ['typescript' => $filtered->typescript(), 'schema' => $schemas->object($filtered->parameters), 'cases' => $filteredCases],
        'relationships' => ['typescript' => $relationships->typescript(), 'ast' => publicAst($relationshipQuery), 'format' => $relationshipQuery->format()],
        'window' => ['typescript' => $window->typescript(), 'ast' => publicAst($windowQuery)],
    ], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? null) === 'declarations') {
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
    $eb = $query->expressionBuilder();
    foreach ([
        'literals' => $eb->or($eb->cmpLit('0', '=', '0'), $eb->and($eb->cmpLit(0, '!=', 1), $eb->cmp('list_id', 'IS', null))),
        'emptyGroups' => $eb->and($eb->or(), $eb->cmp('done', true)),
        'missing' => $eb->or(MissingValue::Missing, $eb->cmp('id', MissingValue::Missing)),
    ] as $name => $condition) {
        $output[$name] = ['ast' => ['table' => 'tasks', 'where' => $condition->toArray()], 'format' => $query->format()];
    }
    echo json_encode($output, JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? null) === 'authorized') {
    $alice = request()->user();
    $query = (new ZeroQuery($registry->get('Task'), $registry))->visibleTo($alice);
    $queries = [
        'root' => clone $query,
        'related' => (clone $query)->with('list', fn ($q) => $q->visibleTo($alice))->with('comments', fn ($q) => $q->visibleTo($alice)->limit(3)),
        'junction' => (clone $query)->whereExists('tags', fn ($q) => $q->visibleTo($alice)->where('id', 'owned')),
        'groups' => (clone $query)->where(fn ($q) => $q
            ->whereHas('tags', fn ($q) => $q->visibleTo($alice)->where('name', 'Reading'))
            ->orWhere(fn ($q) => $q->whereHas('tags', fn ($q) => $q->visibleTo($alice)->where('name', 'Other')))),
    ];
    $compiler = new QueryCompiler($registry);
    $context = new ResolvedContext(['user_id' => 'alice'], 'alice');
    $asts = array_map(fn ($q) => $compiler->compileDeclaration($q, $context), $queries);
    $asts['scope'] = $compiler->compileDeclaration((clone $query)->where(fn ($q) => $q->where('done', true)->orWhere('title', 'shared')), $context);
    echo json_encode($asts, JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown controller interop fixture mode.');
}
