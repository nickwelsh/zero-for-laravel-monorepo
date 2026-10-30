<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class BulkMutationRequest extends FormRequest
{
    /** @return array{items:list<array{key:string,title:string,at:string|null}>} */
    public function validated($key = null, $default = null): array
    {
        $value = parent::validated($key, $default);
        if (! is_array($value) || ! is_array($value['items'] ?? null) || ! array_is_list($value['items'])) {
            throw new LogicException('Expected a validated list.');
        }

        $items = [];
        foreach ($value['items'] as $item) {
            if (! is_array($item) || ! is_string($item['key'] ?? null) || ! is_string($item['title'] ?? null) || ! array_key_exists('at', $item) || ($item['at'] !== null && ! is_string($item['at']))) {
                throw new LogicException('Expected validated item fields.');
            }

            $items[] = ['key' => $item['key'], 'title' => $item['title'], 'at' => $item['at']];
        }

        return ['items' => $items];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'list', 'max:20'],
            'items.*' => ['required', 'array'],
            'items.*.key' => ['required', 'string', 'max:100', 'distinct:strict'],
            'items.*.title' => ['required', 'string', 'max:100'],
            'items.*.at' => ['present', 'nullable', 'date'],
        ];
    }
}
