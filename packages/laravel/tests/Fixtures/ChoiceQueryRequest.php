<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;

final class ChoiceQueryRequest extends FormRequest
{
    /** @return array<string, list<string|Enum|In>> */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', 'nullable', Rule::enum(QueryDirection::class)],
            'priority' => ['sometimes', 'integer', Rule::in([2, 7])],
            'status' => ['sometimes', 'string', 'in:active,completed'],
        ];
    }
}
