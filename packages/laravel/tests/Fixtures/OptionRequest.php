<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Formbraid\Laravel\Field;
use Formbraid\Laravel\OptionInput;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use ZeroForLaravel\Formbraid\ZeroOptions;

final class OptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        throw new LogicException('Option reads must not authorize the mutation.');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string'], 'done' => ['required', 'boolean'], 'reference' => ['required', 'uuid']];
    }

    /** @return array<string, Field> */
    public function fields(): array
    {
        return [
            'reference' => Field::make()->options(ZeroOptions::query(
                Task::zeroQuery()
                    ->where('done', OptionInput::field('done'))
                    ->where('id', '!=', OptionInput::context('task'))
                    ->where('title', 'ILIKE', ZeroOptions::contains(OptionInput::value()))
                    ->orderBy('title', 'asc')->orderBy('id', 'asc')->limit(20),
                value: 'id', label: 'title',
                resolve: Task::zeroQuery()
                    ->where('done', OptionInput::field('done'))
                    ->where('id', OptionInput::field('reference'))
                    ->where('id', '!=', OptionInput::context('task'))->one(),
                authorize: ['task' => 'view'],
            )),
        ];
    }
}
