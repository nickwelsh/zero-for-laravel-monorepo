<?php

declare(strict_types=1);

namespace ZeroForLaravel\Access;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Support\Input;

/**
 * Server-only registrations. The projection lock serializes Gates and registration
 * with managed grants and source triggers, not just with other watch requests.
 *
 * @phpstan-type State array{id: string, generation: string, revision: string, state: 'allowed'|'denied'|'unknown', status?: 403|404}
 * @phpstan-type Watch array{id: string, generation: string, query: string, args: array<string, mixed>}
 */
final readonly class AccessWatches
{
    public function __construct(private ProjectionManager $projections) {}

    public function owner(Request $request): string
    {
        $actor = $this->freshActor($request);

        return hash('sha256', $actor::class."\0".Binding::id($actor->getAuthIdentifier()));
    }

    /** @return State */
    public function store(Request $request): array
    {
        $input = Input::object($request->all(), 'access');
        Input::keys($input, ['query', 'args', 'generation'], 'access');
        $name = $input['query'] ?? null;
        $generation = $input['generation'] ?? null;
        if (! is_string($name) || $name === '' || strlen($name) > 255
            || ! is_string($generation) || $generation === '' || strlen($generation) > 128) {
            Input::fail('access', 'A registered query and bounded generation string are required.');
        }

        $args = Input::object($input['args'] ?? null, 'args');
        $json = json_encode($args, JSON_THROW_ON_ERROR);
        if (strlen($json) > 16384) {
            Input::fail('args', 'Access watch arguments exceed 16384 bytes.');
        }

        return $this->projections->connection()->transaction(function () use ($request, $name, $generation, $args, $json): array {
            $this->projections->lock();
            $this->projections->assertReady();

            $owner = $this->owner($request);
            $query = $this->registration($name);
            // Never save a denied, invalid or indeterminate initial registration.
            try {
                $this->invoke($request, $query, $args);
            } catch (AuthorizationException $authorizationException) {
                abort($this->deniedStatus($authorizationException));
            }

            $db = $this->projections->connection();
            $db->table('permission_access_watches')->where('expires_at', '<=', now())->delete();
            if ($db->table('permission_access_watches')->where('actor', $owner)->count() >= $this->limit()) {
                abort(429, 'Access watch quota exceeded.');
            }

            $id = (string) Str::uuid();
            $db->table('permission_access_watches')->insert([
                'id' => $id, 'actor' => $owner, 'query' => $name, 'args' => $json,
                'generation' => $generation,
                'expires_at' => now()->addSeconds($this->ttl()),
            ]);

            return ['id' => $id, 'generation' => $generation, 'revision' => $this->revision(), 'state' => 'allowed'];
        });
    }

    public function limit(): int
    {
        return max(1, min(32, config()->integer('permissions.access.max_watches', 32)));
    }

    /** @param list<string> $ids
     * @return list<Watch>
     */
    public function owned(string $owner, array $ids): array
    {
        $rows = $this->projections->connection()->table('permission_access_watches')
            ->where('actor', $owner)->whereIn('id', $ids)->where('expires_at', '>', now())->get();
        abort_unless($rows->count() === count($ids), 404);
        $watches = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass || ! is_string($row->id) || ! is_string($row->generation)
                || ! is_string($row->query) || ! is_string($row->args)) {
                throw new LogicException('Invalid persisted access watch.');
            }

            $watches[] = ['id' => $row->id, 'generation' => $row->generation, 'query' => $row->query,
                'args' => Input::object(json_decode($row->args, true, flags: JSON_THROW_ON_ERROR), 'args')];
        }

        return $watches;
    }

    /** Every connection starts with an authoritative snapshot, not a replayed decision.
     * @param  list<string>  $ids
     * @return list<State>|null
     */
    public function snapshot(Request $request, string $owner, array $ids, ?string $previous): ?array
    {
        return $this->projections->connection()->transaction(function () use ($request, $owner, $ids, $previous): ?array {
            $this->projections->lock();
            $watches = $this->owned($owner, $ids);
            try {
                abort_unless($this->owner($request) === $owner, 404);
                // Only selected, still-active watches are leases. Unselected pages
                // expire naturally; a foreign/expired selection never renews any.
                $this->projections->connection()->table('permission_access_watches')
                    ->where('actor', $owner)->whereIn('id', $ids)->where('expires_at', '>', now())
                    ->update(['expires_at' => now()->addSeconds($this->ttl())]);
            } catch (AuthenticationException) {
                // A deleted subject is denied below, never renewed from a captured user.
            }

            $revision = $this->revision();
            if ($revision === $previous) {
                return null;
            }

            $states = [];
            foreach ($watches as $watch) {
                $state = ['id' => $watch['id'], 'generation' => $watch['generation'], 'revision' => $revision, 'state' => 'allowed'];
                try {
                    $this->projections->assertReady();
                    $this->invoke($request, $this->registration($watch['query']), $watch['args']);
                } catch (AuthorizationException $exception) {
                    $state['state'] = 'denied';
                    $state['status'] = $this->deniedStatus($exception);
                } catch (ModelNotFoundException) {
                    $state['state'] = 'denied';
                    $state['status'] = 404;
                } catch (AuthenticationException) {
                    $state['state'] = 'denied';
                    $state['status'] = $this->deniedStatus();
                } catch (Throwable $exception) {
                    if ($exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [403, 404], true)) {
                        $state['state'] = 'denied';
                        $state['status'] = $exception->getStatusCode() === 404 ? 404 : 403;
                    } else {
                        report($exception);
                        $state['state'] = 'unknown';
                    }
                }

                $states[] = $state;
            }

            return $states;
        });
    }

    public function destroy(Request $request, string $id): void
    {
        abort_unless(Str::isUuid($id), 404);
        $this->projections->connection()->transaction(function () use ($request, $id): void {
            $this->projections->lock();
            $owner = $this->owner($request);
            $deleted = $this->projections->connection()->table('permission_access_watches')
                ->where('id', $id)->where('actor', $owner)->where('expires_at', '>', now())->delete();
            abort_unless($deleted === 1, 404);
        });
    }

    private function freshActor(Request $request): Model&Authenticatable
    {
        $class = config()->string('permissions.subject.class');
        if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
            throw new LogicException('Access watches require an authenticatable permission subject model.');
        }

        $user = $request->user();
        if (! $user instanceof $class) {
            throw new AuthenticationException;
        }

        $this->projections->assertConnection($user);
        $subject = new $class;
        $this->projections->assertConnection($subject);
        $actor = $subject->newQuery()->whereKey($user->getAuthIdentifier())->first();
        if (! $actor instanceof Model || ! $actor instanceof Authenticatable) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    private function registration(string $name): RegisteredQuery
    {
        $query = resolve(RegisteredQueries::class)->all()[$name] ?? null;
        if ($query === null || $query->guards === []) {
            throw ValidationException::withMessages(['query' => 'An application-registered query with captured instance Gates is required.']);
        }

        return $query;
    }

    /** @param array<string, mixed> $args */
    private function invoke(Request $request, RegisteredQuery $query, array $args): void
    {
        $actor = $this->freshActor($request);
        $this->asActor($request, $actor, function () use ($request, $query, $args, $actor): void {
            $identity = ContextDefinition::configured()->resolve($request);
            if ($identity->userId() !== Binding::id($actor->getAuthIdentifier())) {
                throw new LogicException('Access watches require the authenticated permission subject identity.');
            }

            $query->invoke($args, $identity, resolve(QueryCompiler::class));
        });
    }

    /** Bind fresh attributes for middleware, Request::user(), Auth and Gates; restore even on failure. */
    private function asActor(Request $request, Authenticatable $actor, Closure $callback): void
    {
        $default = Auth::getDefaultDriver();
        $guard = Auth::guard($default);
        $previous = $guard->user();
        if ($previous === null && ! method_exists($guard, 'forgetUser')) {
            throw new LogicException('Access watches require a restorable authentication guard.');
        }

        $authResolver = Auth::userResolver();
        $resolver = $request->getUserResolver();
        $request->setUserResolver(function (?string $name = null) use ($actor, $default, $resolver): ?Authenticatable {
            $user = $name === null || $name === $default ? $actor : $resolver($name);
            if ($user !== null && ! $user instanceof Authenticatable) {
                throw new LogicException('Invalid authentication user resolver.');
            }

            return $user;
        });
        $guard->setUser($actor);
        try {
            $callback();
        } finally {
            $request->setUserResolver($resolver);
            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }

            Auth::shouldUse($default);
            Auth::resolveUsersUsing($authResolver);
        }
    }

    /** @return 403|404 */
    private function deniedStatus(?AuthorizationException $exception = null): int
    {
        $status = $exception?->status() ?? config()->integer('permissions.denied_status', 403);
        if (! in_array($status, [403, 404], true)) {
            throw new LogicException('Access denial status must be 403 or 404.');
        }

        return $status;
    }

    private function ttl(): int
    {
        return max(1, min(3600, config()->integer('permissions.access.ttl_seconds', 300)));
    }

    private function revision(): string
    {
        $db = $this->projections->connection();

        // The durable log wakes readers. The model vector also detects retention,
        // truncation and rebuilds without trusting Last-Event-ID or JS integers.
        return hash('sha256', json_encode([
            $db->table('permission_events')->max('id'),
            $db->table('permission_models')->orderBy('id')->get(['id', 'revision', 'fingerprint'])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
