<?php

namespace Tests\Feature\Settings;

use App\Enums\SystemRole;
use App\Livewire\Dashboard\Overview;
use App\Livewire\GlobalSearch;
use App\Livewire\Settings\Appearance;
use App\Models\Artist;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
    }

    public function test_only_settings_managers_can_open_appearance(): void
    {
        $this->actingAs($this->makeUser(SystemRole::LabelManager))->get('/settings/appearance')->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/appearance')->assertOk()->assertSee('Colour theme')->assertSee('Ocean');
    }

    public function test_appearance_is_saved_and_rendered_for_everyone(): void
    {
        Livewire::actingAs($this->admin)->test(Appearance::class)
            ->set('theme', 'ocean')
            ->set('sidebar', 'dark')
            ->set('modes', 'both')
            ->set('default_mode', 'dark')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.appearance'));

        $viewer = $this->makeUser(SystemRole::Viewer);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-ld-theme="ocean"', false)
            ->assertSee('data-ld-sidebar="dark"', false)
            ->assertSee('data-theme-preference="dark"', false)
            ->assertSee('data-theme-endpoint', false);

        $this->assertDatabaseHas('activity_logs', ['event' => 'settings.updated']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        Livewire::actingAs($this->admin)->test(Appearance::class)
            ->set('theme', 'neon')
            ->set('modes', 'sometimes')
            ->set('sidebar', 'floating')
            ->call('save')
            ->assertHasErrors(['theme', 'modes', 'sidebar']);
    }

    public function test_fixed_mode_locks_the_theme_and_hides_the_switch(): void
    {
        Livewire::actingAs($this->admin)->test(Appearance::class)
            ->set('modes', 'dark')
            ->call('save')
            ->assertHasNoErrors();

        // A user who chose light still gets dark, and cannot change it.
        $user = $this->makeUser(SystemRole::Viewer, ['theme' => 'light']);

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('data-theme-preference="dark"', false)
            ->assertSee('data-theme-locked', false)
            ->assertDontSee('data-theme-endpoint', false);

        $this->actingAs($user)->patchJson('/preferences/theme', ['theme' => 'light'])->assertForbidden();
    }

    public function test_global_search_respects_permissions(): void
    {
        Artist::factory()->create(['name' => 'Nightfall Collective']);
        $this->admin->update(['name' => 'Nightingale Admin']);

        Livewire::actingAs($this->admin)->test(GlobalSearch::class)
            ->set('query', 'Night')
            ->assertSee('Nightfall Collective')
            ->assertSee('Nightingale Admin');

        // Viewers may see artists but not users.
        Livewire::actingAs($this->makeUser(SystemRole::Viewer))->test(GlobalSearch::class)
            ->set('query', 'Night')
            ->assertSee('Nightfall Collective')
            ->assertDontSee('Nightingale Admin');

        // Too short: nothing is searched.
        Livewire::actingAs($this->admin)->test(GlobalSearch::class)
            ->set('query', 'N')
            ->assertDontSee('Nightfall Collective');
    }

    public function test_dashboard_sections_follow_the_role(): void
    {
        Livewire::actingAs($this->admin)->test(Overview::class)
            ->assertSee('Administration')
            ->assertSee('System health')
            ->assertSee('Owed to artists');

        Livewire::actingAs($this->makeUser(SystemRole::Viewer))->test(Overview::class)
            ->assertDontSee('Administration')
            ->assertDontSee('System health')
            ->assertDontSee('Owed to artists')
            ->assertSee('Artists');
    }
}
