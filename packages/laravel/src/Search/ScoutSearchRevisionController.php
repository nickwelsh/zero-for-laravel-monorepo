<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZeroForLaravel\Http\ContextResolver;
use ZeroForLaravel\Metadata\ModelRegistry;

/** Mount behind the same session authentication as the search transport. */
final class ScoutSearchRevisionController
{
    public function __invoke(Request $request, ModelRegistry $registry, ContextResolver $resolver): JsonResponse
    {
        abort_unless($request->user() !== null, 401);
        $request->validate(['index' => ['required', 'string', 'max:255']]);
        $index = $request->string('index')->toString();
        foreach ($registry->all() as $model) {
            $search = $model->definition->search;
            if ($search !== null && $search->metadata()['index'] === $index) {
                $search->filter($resolver($request));

                return response()->json(['revision' => SearchRevision::current($index)])
                    ->header('Cache-Control', 'no-store, private');
            }
        }

        abort(422, 'Unknown search index.');
    }
}
