<?php

declare(strict_types=1);

namespace ZeroForLaravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\Tables\Metadata;
use ZeroForLaravel\Tables\TableRegistry;

/** Browser/session endpoint, deliberately separate from the Zero aggregate-free protocol. */
final class TableMetadataController
{
    public function __invoke(Request $request, string $table, TableRegistry $tables, Metadata $metadata): JsonResponse
    {
        abort_unless($request->user() !== null, 401);
        $input = Input::object($request->json()->all(), 'metadata');
        $contract = $input['contract'] ?? null;
        if (! is_string($contract)) {
            Input::fail('contract', 'A paired table contract is required.');
        }

        $definition = $tables->find($table, $contract);

        return response()->json($metadata->read($definition, ContextDefinition::configured()->resolve($request), $request->user(), $input))
            ->header('Cache-Control', 'private, no-store');
    }
}
