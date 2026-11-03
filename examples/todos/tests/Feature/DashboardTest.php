<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Maintained policy visibility requires PostgreSQL 15+.');
        }

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();

        $this->get(route('dashboard', ['status' => 'completed']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('__zero.bindings.lists.args.input.status', 'completed'));
    }

    public function test_the_dashboard_rejects_unknown_status_filters()
    {
        $this->actingAs(User::factory()->create());
        $this->getJson(route('dashboard', ['status' => 'unknown']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
