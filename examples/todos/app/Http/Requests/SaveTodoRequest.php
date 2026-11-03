<?php

namespace App\Http\Requests;

use App\Models\TodoList;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

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

        return $user instanceof User && $list instanceof TodoList && $user->can('createTodo', $list);
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
}
