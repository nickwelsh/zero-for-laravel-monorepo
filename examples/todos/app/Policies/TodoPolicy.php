<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use ZeroForLaravel\Permissions\Casbin;

class TodoPolicy
{
    public function view(User $user, Todo $todo): Response
    {
        return Casbin::inspect($user, 'view', $todo)->asNotFound();
    }

    public function create(User $user, TodoList $list): Response
    {
        return Casbin::inspect($user, 'create-todo', $list)->asNotFound();
    }

    public function update(User $user, Todo $todo): Response
    {
        return Casbin::inspect($user, 'update', $todo)->asNotFound();
    }

    public function delete(User $user, Todo $todo): Response
    {
        return Casbin::inspect($user, 'delete', $todo)->asNotFound();
    }
}
