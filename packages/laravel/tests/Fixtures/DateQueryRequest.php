<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class DateQueryRequest extends FormRequest
{
    /** @return array{after:string} */
    public function validated($key = null, $default = null): array
    {
        $value = parent::validated($key, $default);
        if (! is_array($value) || ! is_string($value['after'] ?? null)) {
            throw new LogicException('Expected a validated date string.');
        }

        return ['after' => $value['after']];
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['after' => ['required', 'date']];
    }
}
