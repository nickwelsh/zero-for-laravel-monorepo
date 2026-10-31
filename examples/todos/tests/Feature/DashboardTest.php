<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();

        $this->get(route('dashboard', ['status' => 'completed']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('__zero.bindings.lists.args.input.status', 'completed'));
        $this->getJson(route('dashboard', ['status' => 'unknown']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
