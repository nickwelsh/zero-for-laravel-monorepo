<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;
use Throwable;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\PermissionIndexLimit;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** @phpstan-type Invocation array{clientID: non-empty-string, name: non-empty-string, type: 'custom', id: int, timestamp: numeric-string|int|float, args: list<mixed>} */
final readonly class MutationProcessor
{
    public function __construct(private ModelRegistry $registry, private string $appId, private string $upstreamSchema) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function process(mixed $body, array $params, ResolvedContext $context): array
    {
        try {
            $body = Input::object($body, 'push');
            if (($params['appID'] ?? null) !== $this->appId || ($params['schema'] ?? null) !== $this->upstreamSchema) {
                Input::fail('params', 'Unknown upstream app or schema.');
            }

            $this->identifier($body['clientGroupID'] ?? null, 'clientGroupID');
            $this->identifier($body['requestID'] ?? null, 'requestID');
            if (! is_int($body['pushVersion'] ?? null) || ! is_numeric($body['timestamp'] ?? null) || ! is_array($body['mutations'] ?? null) || ! array_is_list($body['mutations']) || count($body['mutations']) > 100) {
                Input::fail('push', 'Invalid push envelope.');
            }

            $mutations = [];
            foreach ($body['mutations'] as $value) {
                $mutation = Input::object($value, 'mutation');
                $this->identifier($mutation['clientID'] ?? null, 'clientID');
                $this->identifier($mutation['name'] ?? null, 'name');
                $minimum = $mutation['name'] === '_zero_cleanupResults' ? 0 : 1;
                if (($mutation['type'] ?? null) !== 'custom' || ! is_int($mutation['id'] ?? null) || $mutation['id'] < $minimum || $mutation['id'] > 9007199254740991 || ! is_numeric($mutation['timestamp'] ?? null) || ! is_array($mutation['args'] ?? null) || ! array_is_list($mutation['args'])) {
                    Input::fail('mutation', 'Invalid mutation invocation.');
                }

                $mutations[] = $mutation;
            }
        } catch (ValidationException) {
            return $this->failure('parse', [], 'Invalid push envelope or upstream configuration.');
        }

        if ($body['pushVersion'] !== 1) {
            return $this->failure('unsupportedPushVersion', $mutations, 'Only push version 1 is supported.');
        }

        $responses = [];
        foreach ($mutations as $index => $mutation) {
            try {
                if ($mutation['name'] === '_zero_cleanupResults') {
                    $this->cleanup($body['clientGroupID'], $mutation, $context);

                    continue;
                }

                $responses[] = $this->apply($body['clientGroupID'], $mutation, $context);
            } catch (OutOfOrder) {
                return $this->failure('oooMutation', array_slice($mutations, $index), 'Mutation is out of order.');
            } catch (MutationIdentityConflict) {
                return $this->failure('internal', array_slice($mutations, $index), 'Mutation processing failed; retry the push.');
            } catch (QueryException $error) {
                report($error);

                return $this->failure('database', array_slice($mutations, $index), 'Database transaction failed; retry the push.');
            } catch (Throwable $error) {
                report($error);

                return $this->failure('internal', array_slice($mutations, $index), 'Mutation processing failed; retry the push.');
            }
        }

        return ['kind' => 'MutateResponse', 'mutations' => $responses, 'userID' => $context->userId()];
    }

    /**
     * @param  Invocation  $mutation
     * @return array<string, mixed>
     */
    private function apply(string $group, array $mutation, ResolvedContext $context): array
    {
        $id = ['clientID' => $mutation['clientID'], 'id' => $mutation['id']];
        $failure = null;
        try {
            try {
                return $this->registry->connection()->transaction(function () use ($group, $mutation, $context, $id): array {
                    $this->claimIdentity($group, $context);
                    $this->advance($group, $mutation);
                    if (count($mutation['args']) !== 1) {
                        Input::fail('args', 'Expected one argument object.');
                    }

                    if (! str_starts_with($mutation['name'], 'm_')) {
                        throw new OperationException('unknown_mutation', 'Unsupported mutation version.');
                    }

                    $result = resolve(ControllerMutationRegistry::class)->execute($mutation['name'], $mutation['args'][0], $context);

                    if (array_key_exists('data', $result)) {
                        $this->registry->connection()->table($this->upstreamSchema.'.mutations')->insert([
                            'clientGroupID' => $group, 'clientID' => $mutation['clientID'],
                            'mutationID' => $mutation['id'], 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                        ]);
                    }

                    return ['id' => $id, 'result' => (object) $result];
                });
            } catch (ValidationException $error) {
                $failure = ['error' => 'app', 'message' => 'Validation failed.', 'details' => ['code' => 'validation', 'fields' => $error->errors()]];
            } catch (AuthorizationException $error) {
                $failure = $error->status() === 404
                    ? ['error' => 'app', 'message' => 'The record is unavailable.', 'details' => ['code' => 'not_found']]
                    : ['error' => 'app', 'message' => 'This mutation is not authorized.', 'details' => ['code' => 'forbidden']];
            } catch (OperationException $error) {
                $failure = ['error' => 'app', 'message' => $error->getMessage(), 'details' => ['code' => $error->operationCode]];
            } catch (ModelNotFoundException) {
                $failure = ['error' => 'app', 'message' => 'The record is unavailable.', 'details' => ['code' => 'not_found']];
            } catch (PDOException $error) {
                // Deferred triggers can fail at COMMIT, outside Laravel's query wrapper.
                $failure = match (true) {
                    $error->getCode() === PermissionIndexLimit::EXCEEDED_SQL_STATE => ['error' => 'app', 'message' => 'This change would exceed the permission index limit.', 'details' => ['code' => 'permission_index_full']],
                    $error instanceof QueryException && str_starts_with((string) $error->getCode(), '23') => ['error' => 'app', 'message' => 'A database constraint rejected the mutation.', 'details' => ['code' => 'constraint']],
                    default => throw $error,
                };
            }

            // A failed application transaction is fully rolled back before its
            // rejection and progress are committed together in a new transaction.
            return $this->registry->connection()->transaction(function () use ($group, $mutation, $context, $id, $failure): array {
                $this->claimIdentity($group, $context);
                $this->advance($group, $mutation);
                $this->registry->connection()->table($this->upstreamSchema.'.mutations')->insert([
                    'clientGroupID' => $group, 'clientID' => $mutation['clientID'],
                    'mutationID' => $mutation['id'], 'result' => json_encode($failure, JSON_THROW_ON_ERROR),
                ]);

                return ['id' => $id, 'result' => $failure];
            });
        } catch (AlreadyProcessed) {
            return ['id' => $id, 'result' => ['error' => 'alreadyProcessed']];
        }
    }

    /**
     * @param  Invocation  $mutation
     */
    private function advance(string $group, array $mutation): void
    {
        $connection = $this->registry->connection();
        $schema = $connection->getQueryGrammar()->wrap($this->upstreamSchema);
        $progress = $connection->scalar('INSERT INTO '.$schema.'.clients AS current ("clientGroupID", "clientID", "lastMutationID") VALUES (?, ?, 1) ON CONFLICT ("clientGroupID", "clientID") DO UPDATE SET "lastMutationID" = current."lastMutationID" + 1 RETURNING "lastMutationID"', [$group, $mutation['clientID']]);
        if (! is_int($progress) && (! is_string($progress) || ! ctype_digit($progress))) {
            throw new LogicException('Mutation progress must be an integer.');
        }

        $expected = (int) $progress;
        if ($mutation['id'] < $expected) {
            throw new AlreadyProcessed;
        }

        if ($mutation['id'] > $expected) {
            throw new OutOfOrder;
        }
    }

    /**
     * @param  Invocation  $mutation
     */
    private function cleanup(string $group, array $mutation, ResolvedContext $context): void
    {
        try {
            if (count($mutation['args']) !== 1) {
                return;
            }

            $args = Input::object($mutation['args'][0], 'cleanup');
            if (($args['clientGroupID'] ?? null) !== $group) {
                return;
            }

            $query = $this->registry->connection()->table($this->upstreamSchema.'.mutations')->where('clientGroupID', $group);
            if (($args['type'] ?? null) === 'bulk') {
                $ids = $args['clientIDs'] ?? null;
                if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > 1000) {
                    return;
                }

                foreach ($ids as $id) {
                    $this->identifier($id, 'clientIDs');
                }

                $query->whereIn('clientID', $ids);
            } else {
                $this->identifier($args['clientID'] ?? null, 'clientID');
                if (! is_int($args['upToMutationID'] ?? null) || $args['upToMutationID'] < 0 || ($args['type'] ?? 'single') !== 'single') {
                    return;
                }

                $query->where('clientID', $args['clientID'])->where('mutationID', '<=', $args['upToMutationID']);
            }

            if ($this->ownsIdentity($group, $context)) {
                $query->delete();
            }
        } catch (ValidationException) {
            // Like Zero's native handler, invalid internal cleanup is ignored.
        }
    }

    private function claimIdentity(string $group, ResolvedContext $context): void
    {
        $identity = $context->userId();
        if ($identity === null) {
            throw new MutationIdentityConflict;
        }

        $connection = $this->registry->connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable(MutationIdentitySchema::TABLE);
        $schema = $grammar->wrap($this->upstreamSchema);
        $storage = $this->storageHash();
        $expected = $this->identityHash($identity);
        // The binding and first progress update share one transaction. Existing
        // unbound bookkeeping predates identity evidence and cannot be claimed.
        $owner = $connection->scalar(
            'INSERT INTO '.$table.' AS bindings ("upstream_hash", "client_group_id", "identity_hash") SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM '.$schema.'.clients WHERE "clientGroupID" = ?) AND NOT EXISTS (SELECT 1 FROM '.$schema.'.mutations WHERE "clientGroupID" = ?) ON CONFLICT ("upstream_hash", "client_group_id") DO UPDATE SET "identity_hash" = bindings."identity_hash" RETURNING "identity_hash"',
            [$storage, $group, $expected, $group, $group],
        );
        $owner ??= $connection->table(MutationIdentitySchema::TABLE)
            ->where('upstream_hash', $storage)->where('client_group_id', $group)->value('identity_hash');

        if (! is_string($owner) || ! hash_equals($owner, $expected)) {
            throw new MutationIdentityConflict;
        }
    }

    private function ownsIdentity(string $group, ResolvedContext $context): bool
    {
        $identity = $context->userId();
        if ($identity === null) {
            return false;
        }

        $owner = $this->registry->connection()->table(MutationIdentitySchema::TABLE)
            ->where('upstream_hash', $this->storageHash())->where('client_group_id', $group)->value('identity_hash');

        return is_string($owner) && hash_equals($owner, $this->identityHash($identity));
    }

    private function storageHash(): string
    {
        return hash('sha256', $this->upstreamSchema);
    }

    private function identityHash(string $identity): string
    {
        return hash('sha256', json_encode([$this->appId, $identity], JSON_THROW_ON_ERROR));
    }

    /** @phpstan-assert non-empty-string $value */
    private function identifier(mixed $value, string $path): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 256) {
            Input::fail($path, 'Expected a non-empty identifier.');
        }
    }

    /**
     * @param  list<Invocation>  $mutations
     * @return array<string, mixed>
     */
    private function failure(string $reason, array $mutations, string $message): array
    {
        return ['kind' => 'PushFailed', 'origin' => 'server', 'reason' => $reason, 'message' => $message,
            'mutationIDs' => array_map(fn (array $m): array => ['clientID' => $m['clientID'], 'id' => $m['id']], $mutations)];
    }
}
