<?php

namespace Tests\Feature\Profile;

use App\Enums\SystemRole;
use App\Livewire\Profile\Edit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = $this->makeUser(SystemRole::Viewer);
    }

    public function test_profile_page_is_available_to_every_user(): void
    {
        $this->actingAs($this->user)->get('/profile')->assertOk()->assertSee('My profile')->assertSee($this->user->email);
        $this->get('/dashboard')->assertSee(route('profile'));
    }

    public function test_name_and_theme_can_be_updated(): void
    {
        Livewire::actingAs($this->user)->test(Edit::class)
            ->set('name', 'New Name')
            ->set('theme', 'dark')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $fresh = $this->user->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('dark', $fresh->theme->value);
        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.updated', 'user_id' => $this->user->id]);
    }

    public function test_email_change_requires_the_current_password(): void
    {
        Livewire::actingAs($this->user)->test(Edit::class)
            ->set('email', 'new@example.com')
            ->call('saveProfile')
            ->assertHasErrors('email_password')
            ->set('email_password', 'wrong')
            ->call('saveProfile')
            ->assertHasErrors('email_password')
            ->set('email_password', 'password')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->assertSame('new@example.com', $this->user->fresh()->email);
    }

    public function test_password_change(): void
    {
        Livewire::actingAs($this->user)->test(Edit::class)
            ->set('current_password', 'wrong')
            ->set('password', 'BrandNew12345')
            ->set('password_confirmation', 'BrandNew12345')
            ->call('savePassword')
            ->assertHasErrors('current_password')
            ->set('current_password', 'password')
            ->set('password', 'weak')
            ->set('password_confirmation', 'weak')
            ->call('savePassword')
            ->assertHasErrors('password')
            ->set('password', 'BrandNew12345')
            ->set('password_confirmation', 'BrandNew12345')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertSet('current_password', '');

        $this->assertTrue(Hash::check('BrandNew12345', $this->user->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.password_changed']);
    }

    public function test_password_change_stays_available_in_restricted_mode(): void
    {
        // No license activated: the application is unlicensed (read-only).
        Livewire::actingAs($this->user)->test(Edit::class)
            ->set('current_password', 'password')
            ->set('password', 'BrandNew12345')
            ->set('password_confirmation', 'BrandNew12345')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('BrandNew12345', $this->user->fresh()->password));
    }
}
