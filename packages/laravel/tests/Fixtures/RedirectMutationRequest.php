<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

final class RedirectMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->input('title') !== 'forbidden';
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string'], 'open_after_saving' => ['sometimes', 'boolean']];
    }
}
