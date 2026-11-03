<?php

namespace App\Policies;

use App\Models\TodoList;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use ZeroForLaravel\Permissions\Casbin;

class TodoListPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, TodoList $list): Response
    {
        return Casbin::inspect($user, 'view', $list)->asNotFound();
    }

    public function update(User $user, TodoList $list): Response
    {
        return Casbin::inspect($user, 'update', $list)->asNotFound();
    }

    public function delete(User $user, TodoList $list): Response
    {
        return Casbin::inspect($user, 'delete', $list)->asNotFound();
    }

    public function share(User $user, TodoList $list): Response
    {
        return Casbin::inspect($user, 'share', $list)->asNotFound();
    }

    public function createTodo(User $user, TodoList $list): Response
    {
        return Casbin::inspect($user, 'create-todo', $list)->asNotFound();
    }
}
