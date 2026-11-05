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
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\OptionQueryCompiler;
use ZeroForLaravel\Generation\RequestContract;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Tests\Fixtures\ControllerMutationController;
use ZeroForLaravel\Tests\Fixtures\DateQueryRequest;
use ZeroForLaravel\Tests\Fixtures\OwnerUser;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\ProtectedHttpTestCase as HttpTestCase;

uses(HttpTestCase::class);

test('option reads validate their own arguments and reauthorize context without executing mutation hooks', function (): void {
    /** @var HttpTestCase $this */
    Route::post('/option-write/{task}', [ControllerMutationController::class, 'options']);
    $mutation = resolve(ControllerMutationCompiler::class)->compile(ControllerMutationController::class, 'options');
    $definitions = resolve(OptionQueryCompiler::class)->compile($mutation);
    $registrations = eval(substr(resolve(ControllerQueryGenerator::class)->php($definitions), 5));
    if (! is_array($registrations)) {
        throw new LogicException('Expected generated registrations.');
    }

    foreach ($registrations as $name => $registration) {
        if (! is_string($name) || ! $registration instanceof \ZeroForLaravel\Query\RegisteredOptionQuery) {
            throw new LogicException('Expected an option registration.');
        }
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries($registrations));
    // Loading another page's relationships must not stale a generated option registration.
    resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'relationships');
    $parent = Task::create(['title' => 'Parent', 'user_id' => 'alice']);
    $foreign = Task::create(['title' => 'Private', 'user_id' => 'bob']);
    $this->actingAs(OwnerUser::findOrFail('alice'))->withHeader('X-Api-Key', 'private-test-secret');
    $args = ['i0' => $parent->id, 'i1' => false, 'i2' => '50%_\\'];
    $variants = [$args, [...$args, 'i0' => $foreign->id], [...$args, 'i1' => null], [...$args, 'i2' => str_repeat('x', 257)], [...$args, '__window' => 0], [...$args, 'i2' => ''], [...$args, '__window' => 41], [...$args, '__window' => 2147483648], [...$args, '__route' => 'forged']];
    $batch = array_map(fn (array $input, int $index): array => ['id' => (string) $index, 'name' => $definitions[0]->name(), 'args' => [$input]], $variants, array_keys($variants));
    $response = $this->postJson('/zero/query', ['transform', $batch])->assertOk()
        ->assertJsonMissingPath('queries.0.error')->assertJsonPath('queries.0.ast.limit', 21)
        ->assertJsonPath('queries.0.ast.orderBy', [['title', 'asc'], ['id', 'asc']])
        ->assertJsonPath('queries.1.details.status', 403)->assertJsonPath('queries.2.error', 'parse')
        ->assertJsonPath('queries.3.error', 'parse')->assertJsonPath('queries.4.error', 'parse')
        ->assertJsonMissingPath('queries.5.error')->assertJsonPath('queries.6.ast.limit', 41)
        ->assertJsonPath('queries.7.error', 'parse')->assertJsonPath('queries.8.error', 'parse');
    $ast = json_encode($response->json('queries.0.ast'), JSON_THROW_ON_ERROR);
    expect($ast)->toContain(json_encode('%50\\%\\_\\\\%', JSON_THROW_ON_ERROR), 'permission_index', 'user:alice');
    $resolve = ['id' => 'selected', 'name' => $definitions[1]->name(), 'args' => [['i0' => $parent->id, 'i1' => false, 'i2' => $foreign->id]]];
    $resolved = $this->postJson('/zero/query', ['transform', [$resolve]])->assertOk()
        ->assertJsonMissingPath('queries.0.error')->assertJsonPath('queries.0.ast.limit', 1);
    expect(json_encode($resolved->json('queries.0.ast'), JSON_THROW_ON_ERROR))->not->toContain('ILIKE');
    $resolve['args'][0]['__window'] = 41;
    $this->postJson('/zero/query', ['transform', [$resolve]])->assertOk()->assertJsonPath('queries.0.error', 'parse');
    $registered = $registrations[$definitions[0]->name()];
    $stale = new \ZeroForLaravel\Query\RegisteredOptionQuery($registered->action, $registered->parameters,
        static fn (): ZeroQuery => Task::zeroQuery(), $registered->source, 'obsolete');
    expect(fn () => $stale->assertCurrent())->toThrow(LogicException::class, 'Stale Zero option registration');
    expect(Task::count())->toBe(2);
    $this->actingAs(OwnerUser::findOrFail('bob'))
        ->postJson('/zero/query', ['transform', [$batch[0]]])->assertOk()->assertJsonPath('queries.0.details.status', 403);
    expect(resolve(RegisteredQueries::class)->forAction($mutation->action))->toBe([]);
});

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
            static fn (array $args): ZeroQuery => Task::zeroQuery()->visibleTo(request()->user())->where('title', $args['title'])),
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
    $definition = resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'search')[0];
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
    $definition = resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'dated')[0];
    $factory = eval('return '.$definition->php().';');
    if (! $factory instanceof Closure) {
        throw new LogicException('Expected generated date query factory.');
    }

    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        $definition->name() => new RegisteredQuery($definition->action, $definition->component, $definition->prop, $definition->parameters, $factory,
            ['input' => RequestContract::value(DateQueryRequest::class)]),
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
    $definition = resolve(ControllerQueryCompiler::class)->compile(QueryController::class, 'pages')[0];
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

function registerHttpQuery(): void
{
    Route::get('/http-query', [QueryController::class, 'window']);
    app()->instance(RegisteredQueries::class, new RegisteredQueries([
        'q_http' => new RegisteredQuery(QueryController::class.'@window', 'tasks/window', 'tasks', [],
            static fn (): ZeroQuery => Task::zeroQuery()->visibleTo(request()->user())),
    ]));
}

test('query endpoint uses authenticated identity instead of body context', function (): void {
    /** @var HttpTestCase $this */
    registerHttpQuery();
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $response = $this->actingAs($user)->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', [['id' => 'q', 'name' => 'q_http', 'args' => [(object) []]]]])
        ->assertOk()->assertJsonPath('userID', 'alice')->assertJsonMissingPath('queries.0.error');
    expect(json_encode($response->json('queries.0.ast'), JSON_THROW_ON_ERROR))->toContain('user:alice', 'permission_index');
});

test('query transforms accept large revalidation batches while authorizing every query', function (): void {
    /** @var HttpTestCase $this */
    registerHttpQuery();
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $queries = [];
    for ($index = 0; $index < 257; $index++) {
        $queries[] = ['id' => 'q'.$index, 'name' => 'q_http', 'args' => [(object) []]];
    }

    $queries[] = ['id' => 'invalid', 'name' => 'q_http', 'args' => [['ast' => ['table' => 'users']]]];
    $response = $this->actingAs($user)->withHeader('X-Api-Key', 'private-test-secret')
        ->postJson('/zero/query', ['transform', $queries])
        ->assertOk()->assertJsonPath('kind', 'QueryResponse')->assertJsonCount(258, 'queries')
        ->assertJsonPath('queries.257.error', 'parse');
    for ($index = 0; $index < 257; $index++) {
        $response->assertJsonMissingPath('queries.'.$index.'.error');
        expect(json_encode($response->json('queries.'.$index.'.ast'), JSON_THROW_ON_ERROR))->toContain('user:alice', 'permission_index');
    }
});
