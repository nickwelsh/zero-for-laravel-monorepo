<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

final class ControllerMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->input('title') !== 'forbidden';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:100'], 'done' => ['sometimes', 'required', 'boolean'], 'reference' => ['sometimes', 'required', 'uuid']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }
}
