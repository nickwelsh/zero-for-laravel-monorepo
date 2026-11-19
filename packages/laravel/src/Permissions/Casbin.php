<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use ZeroForLaravel\ResolvedContext;

/**
 * @method static AuthorizationResponse inspect(Authenticatable $user, string $action, Model|class-string<Model> $resource, ?ResolvedContext $context = null)
 * @method static bool addPolicy(string ...$fields)
 * @method static bool removePolicy(string ...$fields)
 * @method static bool addGroupingPolicy(string ...$fields)
 * @method static bool removeGroupingPolicy(string ...$fields)
 * @method static PermissionManager model(string $name)
 */
final class Casbin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PermissionManager::class;
    }
}
