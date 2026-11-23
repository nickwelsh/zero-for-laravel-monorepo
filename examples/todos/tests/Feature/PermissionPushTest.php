<?php

namespace Tests\Feature;

use App\Http\Controllers\ShareListController;
use App\Models\User;
use App\Permissions\ListPermissions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRegistry;
use ZeroForLaravel\Permissions\ProjectionManager;

class PermissionPushTest extends TestCase
{
    // RefreshDatabase wraps the push in an outer transaction, hiding commit failures.
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Commit-time permission limits require PostgreSQL.');
        }
    }

    public function test_a_full_permission_index_rejects_only_the_overflowing_share(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $outsider = User::factory()->create();
        $list = createTodoList($owner);
        ListPermissions::grantReader($list, $reader->id);
        config(['permissions.max_index_rows' => DB::table('permission_index')->count(),
            'zero.api_key' => 'test', 'zero.app_id' => 'test', 'zero.upstream_schema' => 'pg_temp']);
        (new ProjectionManager)->rebuild();
        DB::statement('CREATE TEMP TABLE clients ("clientGroupID" text, "clientID" text, "lastMutationID" bigint, PRIMARY KEY ("clientGroupID", "clientID"))');
        DB::statement('CREATE TEMP TABLE mutations ("clientGroupID" text, "clientID" text, "mutationID" bigint, result json, PRIMARY KEY ("clientGroupID", "clientID", "mutationID"))');

        $compiler = app(ControllerMutationCompiler::class);
        $programs = ['store' => $compiler->compile(ShareListController::class, 'store'), 'destroy' => $compiler->compile(ShareListController::class, 'destroy')];
        $factory = eval(substr((new ControllerMutationGenerator)->php(array_values($programs)), 5));
        app()->instance(ControllerMutationRegistry::class, $factory(app(ModelRegistry::class), app(ControllerRouteDispatcher::class)));
        $mutation = function (int $id, string $method, User $target) use ($list, $programs): array {
            $program = $programs[$method];

            return ['type' => 'custom', 'id' => $id, 'clientID' => 'client', 'timestamp' => 1, 'name' => $program->name(),
                'args' => [['version' => $program->fingerprint, 'input' => ['email' => $target->email], 'route' => ['list' => $list->id],
                    'prepared' => ['timestamp' => 1, 'ids' => (object) []]]]];
        };
        $this->actingAs($owner)->withHeader('X-Api-Key', 'test')->postJson('/zero/mutate?appID=test&schema=pg_temp', [
            'pushVersion' => 1, 'clientGroupID' => 'group', 'requestID' => 'push', 'timestamp' => 1,
            'mutations' => [$mutation(1, 'store', $outsider), $mutation(2, 'destroy', $reader)],
        ])->assertOk()->assertJsonPath('kind', 'MutateResponse')->assertJsonPath('mutations.0.result.details.code', 'permission_index_full')
            ->assertJsonPath('mutations.1.id.id', 2)->assertJsonMissingPath('mutations.1.result.error');

        $this->assertSame(2, DB::table('pg_temp.clients')->value('lastMutationID'));
        $rejection = DB::table('pg_temp.mutations')->where('mutationID', 1)->value('result');
        $this->assertSame('permission_index_full', json_decode($rejection, true, flags: JSON_THROW_ON_ERROR)['details']['code']);
        $this->assertFalse(Gate::forUser($outsider)->allows('view', $list));
        $this->assertFalse(Gate::forUser($reader)->allows('view', $list));
        $this->assertTrue(Gate::forUser($owner)->allows('share', $list));
    }
}
