<?php

declare(strict_types=1);

namespace App\Permissions;

use App\Models\TodoList;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionManager;

/** The only ACL is managed Casbin rules, scoped to a list's immutable identity. */
final class ListPermissions
{
    public static function grantOwner(TodoList $list): void
    {
        self::installRoles($list);
        Casbin::addGroupingPolicy('user:'.$list->user_id, 'role:owner', 'list:'.$list->id);
    }

    public static function grantReader(TodoList $list, int $userId): void
    {
        self::installRoles($list);
        Casbin::addGroupingPolicy('user:'.$userId, 'role:reader', 'list:'.$list->id);
    }

    public static function revokeReader(TodoList $list, int $userId): void
    {
        // Removing a direct reader grant never removes the owner's independent role.
        Casbin::removeGroupingPolicy('user:'.$userId, 'role:reader', 'list:'.$list->id);
    }

    public static function forget(TodoList $list): void
    {
        foreach (resolve(PermissionManager::class)->rules() as $rule) {
            $domain = $rule['fields'][$rule['ptype'] === 'p' ? 1 : 2] ?? null;
            if ($domain === 'list:'.$list->id) {
                if ($rule['ptype'] === 'p') {
                    Casbin::removePolicy(...$rule['fields']);
                } else {
                    Casbin::removeGroupingPolicy(...$rule['fields']);
                }
            }
        }
    }

    private static function installRoles(TodoList $list): void
    {
        $domain = 'list:'.$list->id;
        foreach (['update', 'delete', 'share'] as $action) {
            Casbin::addPolicy('role:owner', $domain, 'todo-list', $action, 'any', 'allow');
        }

        foreach (['view', 'create-todo'] as $action) {
            Casbin::addPolicy('role:reader', $domain, 'todo-list', $action, 'any', 'allow');
        }

        // A reader collaborates on todos, but cannot rename, delete or share the list.
        foreach (['view', 'update', 'delete'] as $action) {
            Casbin::addPolicy('role:reader', $domain, 'todo', $action, 'any', 'allow');
        }

        Casbin::addGroupingPolicy('role:owner', 'role:reader', $domain);
    }
}
