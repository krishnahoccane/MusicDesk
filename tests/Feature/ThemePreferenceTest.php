<?php

namespace Tests\Feature;

use App\Enums\ThemePreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_theme_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/preferences/theme', ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertSame(ThemePreference::Dark, $user->fresh()->theme);
    }

    public function test_saved_theme_is_rendered_on_next_page_load(): void
    {
        $user = User::factory()->create(['theme' => 'dark'])->fresh();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertSee('data-theme-preference="dark"', false);
    }

    public function test_invalid_theme_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/preferences/theme', ['theme' => 'neon'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme');
    }

    public function test_guests_cannot_save_theme(): void
    {
        $this->patchJson('/preferences/theme', ['theme' => 'dark'])->assertUnauthorized();
    }
}
