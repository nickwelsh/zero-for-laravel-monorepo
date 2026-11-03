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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** Short authoritative checks; only physically safe result rows reach the browser. */
final readonly class AccessQueries
{
    public const string REVISION = '__zero_access_revision';

    public const string STATUS = '__zero_access_status';

    public function __construct(private ProjectionManager $projections) {}

    /** @return array<string, mixed> */
    public function transform(string $name, mixed $arguments, ResolvedContext $identity): array
    {
        $input = Input::object($arguments, 'access');
        $context = ContextDefinition::configured()->resolve(resolve(Request::class));
        if ($context->userId() !== $identity->userId()) {
            throw new AuthorizationException('Access identity differs from the authenticated Zero identity.');
        }

        $owner = $this->owner($context);
        if ($name === self::REVISION) {
            Input::keys($input, ['generation'], 'access');
            if (isset($input['generation']) && (! is_string($input['generation']) || ! Str::isUuid($input['generation']))) {
                Input::fail('generation', 'A revision generation must be a UUID.');
            }

            return ['table' => 'permission_access_revision', 'where' => $this->equal('id', 'global')];
        }

        Input::keys($input, ['generation', 'bindings'], 'access');
        $generation = $input['generation'] ?? null;
        $bindings = $input['bindings'] ?? null;
        $limit = max(1, min(32, config()->integer('permissions.access.max_bindings', 32)));
        if (! is_string($generation) || ! Str::isUuid($generation)
            || ! is_array($bindings) || ! array_is_list($bindings) || $bindings === [] || count($bindings) > $limit) {
            Input::fail('access', 'A UUID generation and a bounded list of guarded query bindings are required.');
        }

        $checks = [];
        foreach ($bindings as $value) {
            $binding = Input::object($value, 'binding');
            Input::keys($binding, ['binding', 'query', 'args'], 'binding');
            $key = $binding['binding'] ?? null;
            $query = $binding['query'] ?? null;
            if (! is_string($key) || ! Str::isUuid($key) || isset($checks[$key]) || ! is_string($query)) {
                Input::fail('binding', 'Unique UUID bindings and application-registered queries are required.');
            }

            $registered = resolve(RegisteredQueries::class)->all()[$query] ?? null;
            if ($registered === null || $registered->guards === []) {
                Input::fail('query', 'An application-registered query with captured instance Gates is required.');
            }

            $args = Input::object($binding['args'] ?? null, 'args');
            $fingerprint = json_encode([$query, $args], JSON_THROW_ON_ERROR);
            if (strlen($fingerprint) > 16384) {
                Input::fail('args', 'Access binding arguments exceed 16384 bytes.');
            }

            $checks[$key] = ['query' => $registered, 'args' => $args, 'fingerprint' => hash('sha256', $fingerprint),
                'id' => hash('sha256', $owner."\0".$generation."\0".$key)];
        }

        $this->projections->connection()->transaction(function () use ($checks, $owner, $generation, $identity, $limit): void {
            $this->projections->lock();
            $db = $this->projections->connection();
            $expired = $db->table('permission_access_checks')->where('expires_at', '<=', now())->limit(128)->pluck('id')->all();
            $db->table('permission_access_results')->whereIn('id', $expired)->delete();
            $db->table('permission_access_checks')->whereIn('id', $expired)->delete();
            $existing = $db->table('permission_access_checks')->where('owner', $owner)->where('generation', $generation)->get()->keyBy('id');
            if ($existing->isNotEmpty()) {
                if ($existing->count() !== count($checks)) {
                    Input::fail('generation', 'A check generation cannot be reused with different bindings.');
                }

                foreach ($checks as $check) {
                    $row = $existing->get($check['id']);
                    if ($row === null || $row->fingerprint !== $check['fingerprint']) {
                        Input::fail('generation', 'A check generation cannot be reused with different bindings.');
                    }
                }

                // Background retransform must not renew an old decision or lease.
                return;
            }

            if ($db->table('permission_access_checks')->where('owner', $owner)->where('expires_at', '>', now())->count() + count($checks) > $limit * 4) {
                abort(429, 'Access check quota exceeded.');
            }

            $revision = $db->table('permission_access_revision')->where('id', 'global')->value('revision');
            if (! is_string($revision)) {
                throw new LogicException('Access revision is missing. Run the permission migrations.');
            }

            $metadata = [];
            $results = [];
            foreach ($checks as $key => $check) {
                $state = 'allowed';
                $status = null;
                try {
                    $this->projections->assertReady();
                    $this->invoke(resolve(Request::class), $check['query'], $check['args'], $identity);
                } catch (AuthorizationException $error) {
                    $state = 'denied';
                    $status = $this->deniedStatus($error);
                } catch (ModelNotFoundException) {
                    $state = 'denied';
                    $status = 404;
                } catch (AuthenticationException) {
                    $state = 'denied';
                    $status = $this->deniedStatus();
                } catch (Throwable $error) {
                    if ($error instanceof ValidationException) {
                        throw $error;
                    }

                    if ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404], true)) {
                        $state = 'denied';
                        $status = $error->getStatusCode();
                    } else {
                        report($error);
                        $state = 'unknown';
                    }
                }

                $metadata[] = ['id' => $check['id'], 'owner' => $owner, 'generation' => $generation,
                    'fingerprint' => $check['fingerprint'], 'revision' => $revision, 'expires_at' => now()->addSeconds(20)];
                $results[] = ['id' => $check['id'], 'generation' => $generation, 'binding' => $key, 'state' => $state, 'status' => $status];
            }

            $db->table('permission_access_checks')->insert($metadata);
            $db->table('permission_access_results')->insert($results);
        }, 3);

        return ['table' => 'permission_access_results', 'where' => ['type' => 'and', 'conditions' => [
            ['type' => 'simple', 'op' => 'IN', 'left' => ['type' => 'column', 'name' => 'id'],
                'right' => ['type' => 'literal', 'value' => array_column($checks, 'id')]],
            $this->exists('permission_access_checks', 'id', 'id', ['type' => 'and', 'conditions' => [
                $this->equal('owner', $owner),
                $this->exists('permission_access_revision', 'revision', 'revision', $this->equal('id', 'global')),
            ]]),
        ]]];
    }

    private function owner(ResolvedContext $identity): string
    {
        if ($identity->userId() === null) {
            throw new AuthenticationException;
        }

        $context = $identity->values;
        ksort($context);
        $request = resolve(Request::class);

        return hash('sha256', json_encode([
            config()->string('permissions.subject.class'), $identity->userId(), $context,
            $request->hasSession() ? $request->session()->getId() : null,
            $request->bearerToken(),
        ], JSON_THROW_ON_ERROR));
    }

    private function freshActor(Request $request): Model&Authenticatable
    {
        $class = config()->string('permissions.subject.class');
        if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
            throw new LogicException('Access checks require an authenticatable permission subject model.');
        }

        $user = $request->user();
        if (! $user instanceof $class) {
            throw new AuthenticationException;
        }

        $subject = new $class;
        $this->projections->assertConnection($subject);
        $actor = $subject->newQuery()->whereKey($user->getAuthIdentifier())->first();
        if (! $actor instanceof Model || ! $actor instanceof Authenticatable) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    /** @param array<string, mixed> $args */
    private function invoke(Request $request, RegisteredQuery $query, array $args, ResolvedContext $identity): void
    {
        $actor = $this->freshActor($request);
        $this->asActor($request, $actor, function () use ($request, $query, $args, $actor, $identity): void {
            $context = ContextDefinition::configured()->resolve($request);
            if ($context->userId() !== $identity->userId() || $identity->userId() !== Binding::id($actor->getAuthIdentifier())) {
                throw new LogicException('Access checks require the authenticated permission subject identity.');
            }

            $query->invoke($args, $context, resolve(QueryCompiler::class));
        });
    }

    /** Restore both Request and Auth even when real route middleware rejects the check. */
    private function asActor(Request $request, Authenticatable $actor, Closure $callback): void
    {
        $default = Auth::getDefaultDriver();
        $guard = Auth::guard($default);
        $previous = $guard->user();
        if ($previous === null && ! method_exists($guard, 'forgetUser')) {
            throw new LogicException('Access checks require a restorable authentication guard.');
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
    private function deniedStatus(?AuthorizationException $error = null): int
    {
        $status = $error?->status() ?? config()->integer('permissions.denied_status', 403);
        if (! in_array($status, [403, 404], true)) {
            throw new LogicException('Access denial status must be 403 or 404.');
        }

        return $status;
    }

    /** @return array<string, mixed> */
    private function equal(string $column, string $value): array
    {
        return ['type' => 'simple', 'op' => '=', 'left' => ['type' => 'column', 'name' => $column], 'right' => ['type' => 'literal', 'value' => $value]];
    }

    /** @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function exists(string $table, string $parent, string $child, array $where): array
    {
        return ['type' => 'correlatedSubquery', 'op' => 'EXISTS', 'related' => [
            'system' => 'permissions', 'hidden' => true,
            'correlation' => ['parentField' => [$parent], 'childField' => [$child]],
            'subquery' => ['table' => $table, 'alias' => 'zsubq_'.$table, 'where' => $where],
        ]];
    }
}
