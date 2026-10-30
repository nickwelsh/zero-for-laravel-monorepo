<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Closure;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\HttpTestCase;

uses(HttpTestCase::class);

final class QueryAccessMiddleware
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route('title') !== 'allowed' || $request->user()?->getAuthIdentifier() !== 'alice') {
            return new Response('', 403);
        }

        return $next($request);
    }
}

test('controller transforms replay bindings and middleware and cannot substitute authorized route arguments', function (): void {
    /** @var HttpTestCase $this */
    $route = Route::get('/protected/{title}', [QueryController::class, 'index'])
        ->middleware([SubstituteBindings::class, QueryAccessMiddleware::class]);
    Route::bind('title', fn (string $value): string => $value === 'alias' ? 'allowed' : $value);
    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        'q_test' => new RegisteredQuery(QueryController::class.'@index', 'tasks/index', 'tasks', ['title' => 'string'],
            static fn (array $args): \ZeroForLaravel\Query\Query => Task::zeroQuery()->where('title', $args['title'])),
    ]));
    $alice = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $this->actingAs($alice)->withHeader('X-Api-Key', 'private-test-secret');
    $binding = ['__route' => ControllerRouteDispatcher::identity($route), '__routeArgs' => ['title' => 'alias']];
    $requests = [
        ['title' => 'allowed', ...$binding],
        ['title' => 'private', ...$binding],
        ['title' => 'blocked'],
    ];
    $batch = array_map(fn (array $args, int $index): array => ['id' => (string) $index, 'name' => 'q_test', 'args' => [$args]], $requests, array_keys($requests));
    $this->postJson('/zero/query', ['transform', $batch])->assertOk()
        ->assertJsonPath('queries.0.ast.where.conditions.2.right.value', 'allowed')
        ->assertJsonPath('queries.1.error', 'parse')
        ->assertJsonPath('queries.2.error', 'app');
    expect(Route::currentRouteName())->toBe('zero.query');
    $this->postJson('/zero/query', ['transform', [['id' => 'next', 'name' => 'q_test', 'args' => [$requests[0]]]]])
        ->assertOk()->assertJsonMissingPath('queries.0.error');
});

test('FormRequest queries preserve missing null and zero filters and reauthorize every transform', function (): void {
    /** @var HttpTestCase $this */
    Route::get('/search', [QueryController::class, 'search']);
    $definition = resolve(\ZeroForLaravel\Generation\ControllerQueryCompiler::class)->compile(QueryController::class, 'search')[0];
    $factory = eval('return '.$definition->php().';');
    if (! $factory instanceof Closure) {
        throw new LogicException('Expected generated query factory.');
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        $definition->name() => new RegisteredQuery($definition->action, $definition->component, $definition->prop, $definition->parameters, $factory),
    ]));
    $this->actingAs((new User)->setKeyType('string')->forceFill(['id' => 'alice']))->withHeader('X-Api-Key', 'private-test-secret');
    $batch = [];
    foreach ([(object) [], ['title' => null], ['title' => '0'], ['title' => false], ['title' => str_repeat('x', 256)], ['title' => '0', 'private' => true]] as $index => $input) {
        $batch[] = ['id' => (string) $index, 'name' => $definition->name(), 'args' => [['input' => $input]]];
    }

    $this->postJson('/zero/query', ['transform', $batch])->assertOk()
        ->assertJsonPath('queries.0.ast.limit', 3)->assertJsonPath('queries.1.ast.limit', 3)
        ->assertJsonPath('queries.2.ast.where.conditions.2.right.value', '%0%')
        ->assertJsonPath('queries.3.error', 'parse')->assertJsonPath('queries.4.error', 'parse')
        ->assertJsonPath('queries.5.error', 'parse');
    $this->actingAs((new User)->setKeyType('string')->forceFill(['id' => 'bob']))
        ->postJson('/zero/query', ['transform', [$batch[2]]])->assertOk()->assertJsonPath('queries.0.error', 'app');
});

test('date query arguments use canonical JSON dates and preserve comparison semantics on direct transforms', function (): void {
    Route::get('/dated', [QueryController::class, 'dated']);
    $definition = resolve(\ZeroForLaravel\Generation\ControllerQueryCompiler::class)->compile(QueryController::class, 'dated')[0];
    $factory = eval('return '.$definition->php().';');
    if (! $factory instanceof Closure) {
        throw new LogicException('Expected generated date query factory.');
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        $definition->name() => new RegisteredQuery($definition->action, $definition->component, $definition->prop, $definition->parameters, $factory,
            ['input' => \ZeroForLaravel\Generation\RequestContract::value(\ZeroForLaravel\Tests\Fixtures\DateQueryRequest::class)]),
    ]));
    \Pest\Laravel\actingAs((new User)->setKeyType('string')->forceFill(['id' => 'alice']))->withHeader('X-Api-Key', 'private-test-secret');
    $batch = [];
    foreach (['2023-11-14T22:13:20.123Z', 'invalid', '2023-11-14T22:13:20Z'] as $index => $after) {
        $batch[] = ['id' => (string) $index, 'name' => $definition->name(), 'args' => [['input' => ['after' => $after]]]];
    }

    \Pest\Laravel\postJson('/zero/query', ['transform', $batch])->assertOk()
        ->assertJsonPath('queries.0.ast.where.conditions.2.right.value', 1700000000123)
        ->assertJsonPath('queries.1.error', 'parse')->assertJsonPath('queries.2.error', 'parse');
});

test('cursor pages preserve raw ordering values and reject incomplete or foreign cursors', function (): void {
    /** @var HttpTestCase $this */
    Route::get('/pages', [QueryController::class, 'pages']);
    $definition = resolve(\ZeroForLaravel\Generation\ControllerQueryCompiler::class)->compile(QueryController::class, 'pages')[0];
    $factory = eval('return '.$definition->php().';');
    if (! $factory instanceof Closure) {
        throw new LogicException('Expected generated query factory.');
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        $definition->name() => new RegisteredQuery($definition->action, $definition->component, $definition->prop, $definition->parameters, $factory),
    ]));
    $this->actingAs((new User)->setKeyType('string')->forceFill(['id' => 'alice']))->withHeader('X-Api-Key', 'private-test-secret');
    $batch = [];
    foreach ([(object) [], ['__cursor' => ['created_at' => 2000.25, 'id' => 'b']], ['__cursor' => ['id' => 'b']], ['__cursor' => ['created_at' => 2000.25, 'id' => 'b', 'user_id' => 'bob']], ['__window' => 100]] as $index => $args) {
        $batch[] = ['id' => (string) $index, 'name' => $definition->name(), 'args' => [$args]];
    }

    $this->postJson('/zero/query', ['transform', $batch])->assertOk()
        ->assertJsonPath('queries.0.ast.limit', 3)
        ->assertJsonPath('queries.0.ast.orderBy', [['created_at', 'desc'], ['id', 'asc']])
        ->assertJsonPath('queries.1.ast.start.row.created_at', 2000.25)
        ->assertJsonPath('queries.1.ast.start.exclusive', true)
        ->assertJsonPath('queries.2.error', 'parse')->assertJsonPath('queries.3.error', 'parse')
        ->assertJsonPath('queries.4.error', 'parse');
});
