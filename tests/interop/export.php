<?php

declare(strict_types=1);

require __DIR__.'/../../packages/laravel/vendor/autoload.php';

use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Tests\OwnerTestCase as TestCase;
use ZeroForLaravel\ZeroContext;

$fixture = new class('bootFixture') extends TestCase
{
    public function bootFixture(): ModelRegistry
    {
        $this->setUp();
        $alice = (new Illuminate\Foundation\Auth\User)->setKeyType('string')->forceFill(['id' => 'alice']);
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
function publicAst(Query $query): array
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
        'sorted' => ['typescript' => $sorted->typescript(), 'schema' => $schemas->object($sorted->parameters)],
        'typescript' => $definition->typescript(), 'schema' => $schemas->object($definition->parameters), 'cases' => $cases,
        'filtered' => ['typescript' => $filtered->typescript(), 'schema' => $schemas->object($filtered->parameters), 'cases' => $filteredCases],
        'relationships' => ['typescript' => $relationships->typescript(), 'ast' => publicAst($relationshipQuery), 'format' => $relationshipQuery->format()],
        'window' => ['typescript' => $window->typescript(), 'ast' => publicAst($windowQuery)],
    ], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? null) === 'declarations') {
    $query = new Query($registry->get('Task'), $registry);
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
} elseif (($argv[1] ?? null) === 'authorized') {
    $alice = request()->user();
    $query = (new Query($registry->get('Task'), $registry))->visibleTo($alice);
    $queries = [
        'root' => $query,
        'related' => $query->related('list', fn ($q) => $q->visibleTo($alice))->related('comments', fn ($q) => $q->visibleTo($alice)->limit(3)),
        'junction' => $query->whereExists('tags', fn ($q) => $q->visibleTo($alice)->where('id', 'owned')),
        'groups' => $query->where(fn ($eb) => $eb->or(
            $eb->exists('tags', fn ($q) => $q->visibleTo($alice)->where('name', 'Reading')),
            $eb->exists('tags', fn ($q) => $q->visibleTo($alice)->where('name', 'Other')),
        )),
    ];
    $compiler = new QueryCompiler($registry);
    $context = new ZeroContext(['user_id' => 'alice']);
    $asts = array_map(fn ($q) => $compiler->compileDeclaration($q, $context), $queries);
    $asts['scope'] = $compiler->compileDeclaration($query->where(fn ($op) => $op->or($op->cmp('done', true), $op->cmp('title', 'shared'))), $context);
    echo json_encode($asts, JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown controller interop fixture mode.');
}
