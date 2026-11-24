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

class PermissionPushTest extends TestCase
{
    // Exercise the commit of each mutation in the push.
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Maintained permission projections require PostgreSQL.');
        }
    }

    public function test_a_push_grants_and_revokes_list_access(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $outsider = User::factory()->create();
        $list = createTodoList($owner);
        ListPermissions::grantReader($list, $reader->id);
        config(['zero.api_key' => 'test', 'zero.app_id' => 'test', 'zero.upstream_schema' => 'pg_temp']);
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
        ])->assertOk()->assertJsonPath('kind', 'MutateResponse')->assertJsonMissingPath('mutations.0.result.error')
            ->assertJsonPath('mutations.1.id.id', 2)->assertJsonMissingPath('mutations.1.result.error');

        $this->assertSame(2, DB::table('pg_temp.clients')->value('lastMutationID'));
        $this->assertTrue(Gate::forUser($outsider)->allows('view', $list));
        $this->assertFalse(Gate::forUser($reader)->allows('view', $list));
        $this->assertTrue(Gate::forUser($owner)->allows('share', $list));
    }
}
