<?php

namespace App\Http\Requests;

use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Formbraid\Laravel\Field;
use Formbraid\Laravel\OptionInput;
use Illuminate\Foundation\Http\FormRequest;
use ZeroForLaravel\Formbraid\ZeroOptions;

class SaveTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policy authorization protects the original row; validate the destination too.
        if (! $this->exists('list_id')) {
            return true;
        }
        $id = $this->input('list_id');
        $user = $this->user();

        $list = is_string($id) ? TodoList::find($id) : null;

        return $user instanceof User && $list instanceof TodoList && $user->can('create', [Todo::class, $list]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'list_id' => ['sometimes', 'required', 'uuid', 'exists:todo_lists,id'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'completed' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, Field> */
    public function fields(): array
    {
        return [
            'list_id' => Field::make('ComboboxField')->label('Destination list')->options(ZeroOptions::query(
                TodoList::zeroQuery()
                    ->where('name', 'ILIKE', ZeroOptions::contains(OptionInput::value()))
                    ->orderBy('name', 'asc')->orderBy('id', 'asc')->limit(20),
                value: 'id', label: 'name',
                resolve: TodoList::zeroQuery()->where('id', OptionInput::field('list_id'))->one(),
            )),
            'title' => Field::make()->label('New todo')->props(['placeholder' => 'What needs doing?']),
        ];
    }
}
