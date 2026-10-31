<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TodoStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', Rule::enum(TodoStatus::class)]];
    }
}
