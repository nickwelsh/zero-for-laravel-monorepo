<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class CoalesceMutationRequest extends FormRequest
{
    /** @return array{note: mixed, flag: mixed, count: mixed, settings?: array<array-key, mixed>|null} */
    public function validated($key = null, $default = null): array
    {
        $value = parent::validated($key, $default);
        if (! is_array($value)) {
            throw new LogicException('Expected validated optional settings.');
        }

        $settings = $value['settings'] ?? null;
        if ($settings !== null && ! is_array($settings)) {
            throw new LogicException('Expected validated optional settings.');
        }

        $validated = ['note' => $value['note'] ?? null, 'flag' => $value['flag'] ?? null, 'count' => $value['count'] ?? null];
        if (array_key_exists('settings', $value)) {
            $validated['settings'] = $settings;
        }

        return $validated;
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string'],
            'flag' => ['sometimes', 'nullable', 'boolean'],
            'count' => ['sometimes', 'nullable', 'integer'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.label' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
