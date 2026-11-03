<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\ProjectionManager;

/** Shared real owner bindings; private fixtures are never public read opt-ins. */
final class OwnerPermissions
{
    /** @param list<class-string<Model>> $classes */
    public static function configure(array $classes = [Task::class, TaskList::class, Tag::class, Comment::class]): void
    {
        app()->register(PermissionServiceProvider::class);
        config(['permissions.models.owner' => __DIR__.'/owner.conf',
            'permissions.subject.class' => User::class,
            'permissions.subject.prefix' => 'user:',
            'permissions.subject.attributes' => [],
            'permissions.denied_status' => 403,
            'zero.public_models' => [...config()->array('zero.public_models', []), VisibleTask::class, MutationPreference::class],
            'zero.mutation_context' => [Task::class => ['user_id' => ContextField::UserId]],
        ]);
        $resources = [];
        foreach ($classes as $class) {
            $resources[$class] = ['type' => (new $class)->getTable(), 'model' => 'owner',
                'domain' => ['column' => 'user_id', 'prefix' => 'owner:'],
                'attributes' => ['owner_id' => ['column' => 'user_id', 'type' => 'id']]];
            Gate::policy($class, OwnerPolicy::class);
        }

        config(['permissions.resources' => $resources]);
        if (config()->array('zero.models', []) === []) {
            config(['zero.models' => $classes]);
        }
    }

    /** Install actual grants for scalar Gates, and maintained projections on PostgreSQL. */
    public static function install(): void
    {
        PermissionSchema::create(DB::connection());
        Schema::create('users', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        DB::table('users')->insert([['id' => 'alice'], ['id' => 'bob']]);
        if (DB::connection()->getDriverName() === 'pgsql') {
            (new ProjectionManager)->rebuild();
        }

        foreach (array_keys(config()->array('permissions.resources')) as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw new LogicException('Owner fixtures require Eloquent resource classes.');
            }

            $resource = Binding::resource($class);
            foreach (['alice', 'bob'] as $user) {
                Casbin::model('owner')->addPolicy('owner:'.$user, $resource->type, 'view', 'owner', 'allow');
            }
        }
    }
}
