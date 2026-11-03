<?php

declare(strict_types=1);

namespace ZeroForLaravel\Access;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use ZeroForLaravel\Support\Input;

/** Browser-authenticated control plane only; never a native data authorization boundary. */
final readonly class AccessController
{
    public function __construct(private AccessWatches $watches) {}

    public function store(Request $request): JsonResponse
    {
        try {
            // Authentication precedes request validation, even without route middleware.
            $this->watches->owner($request);

            return response()->json($this->watches->store($request), 201)->header('Cache-Control', 'no-store');
        } catch (Throwable $throwable) {
            return $this->failure($throwable);
        }
    }

    public function events(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $owner = $this->watches->owner($request);
            $selected = $request->query('watches');
            if (! is_string($selected) || strlen($selected) > 1200) {
                Input::fail('watches', 'A bounded comma-separated list of watch IDs is required.');
            }

            $ids = array_values(array_unique(explode(',', $selected)));
            if (count($ids) > $this->watches->limit()) {
                Input::fail('watches', 'Too many access watches.');
            }

            foreach ($ids as $id) {
                abort_unless(Str::isUuid($id), 404);
            }

            $registered = $this->watches->owned($owner, $ids);
        } catch (Throwable $throwable) {
            return $this->failure($throwable);
        }

        return response()->stream(function () use ($request, $owner, $ids, $registered): void {
            $deadline = microtime(true) + max(0, min(20, config()->integer('permissions.access.stream_seconds', 20)));
            $poll = max(10, min(1000, config()->integer('permissions.access.poll_milliseconds', 250)));
            $heartbeat = max(1, min(10, config()->integer('permissions.access.heartbeat_seconds', 5)));
            $lastHeartbeat = microtime(true);
            $revision = null;
            do {
                try {
                    $states = $this->watches->snapshot($request, $owner, $ids, $revision);
                } catch (NotFoundHttpException) {
                    // Deleted, expired and foreign watches are equally unreadable.
                    break;
                } catch (Throwable $exception) {
                    report($exception);
                    foreach ($registered as $watch) {
                        $this->emit(['id' => $watch['id'], 'generation' => $watch['generation'],
                            'revision' => $revision ?? 'unknown', 'state' => 'unknown']);
                    }

                    $this->flush();
                    break;
                }

                foreach ($states ?? [] as $state) {
                    $revision = $state['revision'];
                    $this->emit($state);
                }

                if (microtime(true) - $lastHeartbeat >= $heartbeat) {
                    echo ": heartbeat\n\n";
                    $lastHeartbeat = microtime(true);
                }

                $this->flush();
                if (connection_aborted() || microtime(true) >= $deadline) {
                    break;
                }

                Sleep::usleep($poll * 1000);
            } while (microtime(true) < $deadline);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }

    public function destroy(Request $request, string $id): Response
    {
        try {
            $this->watches->owner($request);
            $this->watches->destroy($request, $id);

            return response()->noContent()->header('Cache-Control', 'no-store');
        } catch (Throwable $throwable) {
            return $this->failure($throwable);
        }
    }

    private function failure(Throwable $exception): JsonResponse
    {
        // Preserve intentional HTTP validation/authentication/denial responses.
        if ($exception instanceof AuthenticationException || $exception instanceof AuthorizationException
            || $exception instanceof ValidationException || $exception instanceof ModelNotFoundException
            || $exception instanceof HttpExceptionInterface) {
            throw $exception;
        }

        report($exception);

        return response()->json(['state' => 'unknown'], 503)->header('Cache-Control', 'no-store');
    }

    /** @param array{id: string, generation: string, revision: string, state: string, status?: int} $state */
    private function emit(array $state): void
    {
        echo 'id: '.$state['revision']."\nevent: access\ndata: ".json_encode($state, JSON_THROW_ON_ERROR)."\n\n";
    }

    private function flush(): void
    {
        // Preserve test/output capture buffers; unbuffer actual web SAPIs.
        if (PHP_SAPI !== 'cli') {
            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }
    }
}
