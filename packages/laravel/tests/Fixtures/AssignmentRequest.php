<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

final class AssignmentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['list_id' => ['sometimes', 'nullable', 'string']];
    }
}
