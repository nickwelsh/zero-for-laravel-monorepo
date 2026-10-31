<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChoiceQueryRequest extends FormRequest
{
    /** @return array<string, list<string|\Illuminate\Validation\Rules\Enum|\Illuminate\Validation\Rules\In>> */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', 'nullable', Rule::enum(QueryDirection::class)],
            'priority' => ['sometimes', 'integer', Rule::in([2, 7])],
            'status' => ['sometimes', 'string', 'in:active,completed'],
        ];
    }
}
