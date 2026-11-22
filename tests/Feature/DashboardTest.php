<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_see_the_dashboard_shell(): void
    {
        $user = User::factory()->create(['name' => 'Asha Rao'])->fresh();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Asha')
            ->assertSee('ld-sidebar', false)
            ->assertSee('data-theme-preference="system"', false)
            ->assertSee('Sign out');
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create()->fresh())
            ->get('/login')
            ->assertRedirect('/dashboard');
    }

    public function test_navigation_only_links_to_registered_routes(): void
    {
        $this->actingAs(User::factory()->create()->fresh())
            ->get('/dashboard')
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('/artists', false);
    }
}
