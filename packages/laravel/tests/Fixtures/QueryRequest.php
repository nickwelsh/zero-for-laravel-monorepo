<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class QueryRequest extends FormRequest
{
    /** @return ($key is null ? array{title?: string|null} : mixed) */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);
        if ($key !== null) {
            return $validated;
        }

        if (! is_array($validated) || (isset($validated['title']) && ! is_string($validated['title']))) {
            throw new LogicException('Unexpected validated query input.');
        }

        return array_key_exists('title', $validated) ? ['title' => $validated['title']] : [];
    }

    public function authorize(): bool
    {
        return $this->user()?->getAuthIdentifier() === 'alice';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['sometimes', 'nullable', 'string', 'max:255']];
    }
}
