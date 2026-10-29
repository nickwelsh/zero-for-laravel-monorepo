<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZeroForLaravel\Http\ContextResolver;
use ZeroForLaravel\Metadata\ModelRegistry;

/** Register behind the application's session authentication middleware. */
final class ScoutSearchController
{
    public function __invoke(Request $request, ModelRegistry $registry, ContextResolver $resolver): JsonResponse
    {
        abort_unless($request->user() !== null, 401);
        $input = $request->validate(['searches' => ['required', 'array', 'min:1', 'max:10'], 'searches.*' => ['required', 'array']]);
        $context = $resolver($request);
        $searches = [];
        $body = \ZeroForLaravel\Support\Input::object($input, 'search');
        if (! is_array($body['searches'])) {
            abort(422);
        }

        foreach ($body['searches'] as $parameters) {
            $parameters = \ZeroForLaravel\Support\Input::object($parameters, 'search');
            abort_if(array_diff(array_keys($parameters), ['collection', 'q', 'page', 'per_page', 'query_by', 'filter_by', 'facet_by', 'max_facet_values', 'sort_by', 'include_fields', 'highlight_fields', 'highlight_full_fields', 'highlight_affix_num_tokens', 'highlight_start_tag', 'highlight_end_tag', 'snippet_threshold', 'drop_tokens_threshold', 'num_typos', 'typo_tokens_threshold']) !== [], 422, 'Unsupported search parameter.');
            $index = $parameters['collection'] ?? null;
            $search = null;
            $sort = null;
            foreach ($registry->all() as $model) {
                if ($model->definition->search === null) {
                    continue;
                }

                $candidate = $model->definition->search;
                $meta = $candidate->metadata();
                if ($index === $meta['index']) {
                    $search = $candidate;
                    break;
                }

                foreach ($meta['sorts'] as $allowed) {
                    if ($index === $meta['index'].'/sort/'.$allowed) {
                        $search = $candidate;
                        $sort = $allowed;
                        break 2;
                    }
                }
            }

            abort_if($search === null, 422, 'Unknown search index.');
            $meta = $search->metadata();
            $query = $parameters['q'] ?? '*';
            $page = filter_var($parameters['page'] ?? 1, FILTER_VALIDATE_INT);
            $size = filter_var($parameters['per_page'] ?? 25, FILTER_VALIDATE_INT);
            abort_unless(is_string($query) && strlen($query) <= 4096 && $page >= 1 && $page <= 40 && $size >= 1 && $size <= 100, 422, 'Invalid search bounds.');
            $filter = $parameters['filter_by'] ?? '';
            abort_unless(is_string($filter) && strlen($filter) <= 8192, 422, 'Invalid filter.');
            $filter = (new TypesenseFilter)->validate($filter, $meta['fields']);
            $facets = $parameters['facet_by'] ?? '';
            abort_unless(is_string($facets) && ($facets === '' || array_diff(explode(',', $facets), $meta['facets']) === []), 422, 'Unknown facet.');
            $sortBy = implode(',', array_filter([$query === '*' ? null : '_text_match:desc', $sort ?? ($meta['sorts'][0] ?? null)]));
            $searches[] = ['collection' => $meta['index'], 'q' => $query, 'query_by' => $meta['queryBy'],
                'page' => $page, 'per_page' => $size, 'filter_by' => '('.$search->filter($context).')'.($filter === '' ? '' : ' && ('.$filter.')'),
                'facet_by' => $facets, 'max_facet_values' => 100,
                ...($sortBy === '' ? [] : ['sort_by' => $sortBy]),
                'include_fields' => implode(',', array_unique(['id', $meta['key']])), 'highlight_fields' => 'none', 'highlight_full_fields' => '', 'enable_highlight_v1' => false];
        }

        $client = resolve(\Typesense\Client::class);
        $response = \ZeroForLaravel\Support\Input::object($client->multiSearch->perform(['searches' => $searches]), 'response');
        if (! is_array($response['results'] ?? null)) {
            abort(502, 'Invalid search response.');
        }

        foreach ($response['results'] ?? [] as $index => $result) {
            $result = \ZeroForLaravel\Support\Input::object($result, 'result');
            if (! is_array($result['hits'] ?? [])) {
                abort(502, 'Invalid search hits.');
            }

            $hits = $result['hits'] ?? [];
            foreach ($hits as $position => $hit) {
                $hit = \ZeroForLaravel\Support\Input::object($hit, 'hit');
                $keys = array_fill_keys(explode(',', $searches[$index]['include_fields']), true);
                $hits[$position] = ['document' => array_intersect_key(\ZeroForLaravel\Support\Input::object($hit['document'] ?? [], 'document'), $keys), 'highlights' => []];
            }

            $result['hits'] = $hits;
            $response['results'][$index] = $result;
        }

        return response()->json($response);
    }
}
