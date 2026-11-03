<?php

declare(strict_types=1);

use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;

return [
    'connection' => null,
    'denied_status' => 404,
    'models' => ['default' => __DIR__.'/permissions/lists.conf'],
    'subject' => ['class' => User::class, 'prefix' => 'user:', 'attributes' => []],
    // Domains bind raw database columns: a todo inherits its list's grant scope.
    'resources' => [
        TodoList::class => [
            'type' => 'todo-list',
            'domain' => ['column' => 'id', 'prefix' => 'list:'],
            'attributes' => [],
        ],
        Todo::class => [
            'type' => 'todo',
            'domain' => ['column' => 'list_id', 'prefix' => 'list:'],
            'attributes' => [],
        ],
    ],
];
