<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures\Todos;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTodoRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'open_after_saving' => ['sometimes', 'boolean'],
        ];
    }
}
