<?php

namespace Tests\Feature\Users;

use App\Enums\SystemRole;
use App\Exceptions\UserManagementException;
use App\Livewire\Users\Form;
use App\Livewire\Users\Index;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
    }

    public function test_permission_boundaries_on_pages(): void
    {
        $this->activateLicense();
        $viewer = $this->makeUser(SystemRole::Viewer);
        $manager = $this->makeUser(SystemRole::LabelManager);

        $this->actingAs($viewer)->get('/users')->assertForbidden();
        $this->actingAs($viewer)->get('/users/create')->assertForbidden();

        // Label Managers can view users but not manage them.
        $this->actingAs($manager)->get('/users')->assertOk()->assertDontSee('Add user');
        $this->actingAs($manager)->get('/users/create')->assertForbidden();
        $this->actingAs($manager)->get("/users/{$viewer->id}/edit")->assertForbidden();

        $this->actingAs($this->admin)->get('/users')->assertOk()->assertSee($viewer->email)->assertSee('Add user');
        $this->actingAs($this->admin)->get('/users/create')->assertOk();
    }

    public function test_admin_creates_a_user_with_a_password(): void
    {
        $this->activateLicense();

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Asha Rao')
            ->set('email', 'Asha@Example.com')
            ->set('role', SystemRole::Accountant->value)
            ->set('send_link', false)
            ->set('password', 'Welcome12345')
            ->set('password_confirmation', 'Welcome12345')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('users.index'));

        $user = User::query()->where('email', 'asha@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(SystemRole::Accountant->value));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('Welcome12345', $user->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.created', 'subject_id' => $user->id]);
    }

    public function test_invited_user_receives_a_set_password_link(): void
    {
        Notification::fake();
        $this->activateLicense();

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Invited Person')
            ->set('email', 'invited@example.com')
            ->set('role', SystemRole::Viewer->value)
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertSentTo(User::query()->where('email', 'invited@example.com')->first(), ResetPassword::class);
    }

    public function test_validation_rejects_duplicate_email_and_weak_password(): void
    {
        $this->activateLicense();

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Dup')
            ->set('email', $this->admin->email)
            ->set('role', SystemRole::Viewer->value)
            ->set('send_link', false)
            ->set('password', 'weak')
            ->set('password_confirmation', 'weak')
            ->call('save')
            ->assertHasErrors(['email', 'password']);
    }

    public function test_license_user_allowance_is_enforced(): void
    {
        $this->activateLicense('STRT'); // user_limit 3
        $this->makeUser(SystemRole::Viewer);
        $third = $this->makeUser(SystemRole::Viewer);  // admin + 2 = 3 active

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Fourth')
            ->set('email', 'fourth@example.com')
            ->set('role', SystemRole::Viewer->value)
            ->set('send_link', false)
            ->set('password', 'Welcome12345')
            ->set('password_confirmation', 'Welcome12345')
            ->call('save')
            ->assertHasErrors('form');
        $this->assertDatabaseMissing('users', ['email' => 'fourth@example.com']);

        // A deactivated account may still be created and does not use a seat …
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Fourth')
            ->set('email', 'fourth@example.com')
            ->set('role', SystemRole::Viewer->value)
            ->set('is_active', false)
            ->set('password', 'Welcome12345')
            ->set('password_confirmation', 'Welcome12345')
            ->call('save')
            ->assertHasNoErrors();
        $fourth = User::query()->where('email', 'fourth@example.com')->firstOrFail();
        $this->assertFalse($fourth->is_active);

        // … but cannot be activated until a seat frees up.
        Livewire::actingAs($this->admin)->test(Index::class)->call('toggleActive', $fourth->id);
        $this->assertFalse($fourth->fresh()->is_active);

        Livewire::actingAs($this->admin)->test(Index::class)->call('toggleActive', $third->id);
        $this->assertFalse($third->fresh()->is_active);

        Livewire::actingAs($this->admin)->test(Index::class)->call('toggleActive', $fourth->id);
        $this->assertTrue($fourth->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_delete_or_demote_themselves(): void
    {
        $this->activateLicense();

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('toggleActive', $this->admin->id)
            ->call('delete', $this->admin->id);
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertFalse($this->admin->fresh()->trashed());

        Livewire::actingAs($this->admin)->test(Form::class, ['user' => $this->admin])
            ->set('role', SystemRole::Viewer->value)
            ->call('save')
            ->assertHasErrors('form');
        $this->assertTrue($this->admin->fresh()->hasRole(SystemRole::SuperAdmin->value));
    }

    public function test_the_last_super_admin_is_protected(): void
    {
        $this->activateLicense();
        $other = $this->makeUser(SystemRole::SuperAdmin);

        // With two Super Admins, one can be deactivated …
        Livewire::actingAs($this->admin)->test(Index::class)->call('toggleActive', $other->id);
        $this->assertFalse($other->fresh()->is_active);

        // … and the remaining one cannot be demoted by the (now inactive) other.
        $this->assertThrows(
            fn () => app(UserService::class)->update($other->fresh(), $this->admin, ['name' => 'X', 'email' => $this->admin->email], SystemRole::Viewer->value),
            UserManagementException::class,
        );
    }

    public function test_managers_cannot_escalate_privileges(): void
    {
        $this->activateLicense();
        $role = Role::create(['name' => 'User Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['users.view', 'users.manage', 'artists.view']);
        $userAdmin = $this->makeUser('User Admin');
        $viewer = $this->makeUser(SystemRole::Viewer);

        // Cannot create a Super Admin or a role with more permissions.
        Livewire::actingAs($userAdmin)->test(Form::class)
            ->set('name', 'Sneaky')
            ->set('email', 'sneaky@example.com')
            ->set('role', SystemRole::SuperAdmin->value)
            ->set('send_link', false)
            ->set('password', 'Welcome12345')
            ->set('password_confirmation', 'Welcome12345')
            ->call('save')
            ->assertHasErrors('form');
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);

        // Cannot edit or delete an account with more access than they have.
        $this->actingAs($userAdmin)->get("/users/{$this->admin->id}/edit")->assertForbidden();
        $this->actingAs($userAdmin)->get("/users/{$viewer->id}/edit")->assertForbidden(); // viewer has permissions the user admin lacks
        Livewire::actingAs($userAdmin)->test(Index::class)->call('delete', $this->admin->id)->assertForbidden();
        $this->assertFalse($this->admin->fresh()->trashed());
    }

    public function test_deleted_users_can_be_restored_deactivated(): void
    {
        $this->activateLicense();
        $user = $this->makeUser(SystemRole::Viewer);

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $user->id);
        $this->assertSoftDeleted($user);

        $this->actingAs($this->admin)->get('/users?status=deleted')->assertSee($user->email);

        Livewire::actingAs($this->admin)->test(Index::class)->call('restore', $user->id);
        $restored = $user->fresh();
        $this->assertFalse($restored->trashed());
        $this->assertFalse($restored->is_active);
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.restored', 'subject_id' => $user->id]);
    }

    public function test_deactivated_user_is_signed_out_on_next_request(): void
    {
        $this->activateLicense();
        $user = $this->makeUser(SystemRole::Viewer);

        Livewire::actingAs($this->admin)->test(Index::class)->call('toggleActive', $user->id);

        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_writes_are_blocked_without_a_license(): void
    {
        $user = $this->makeUser(SystemRole::Viewer);

        Livewire::actingAs($this->admin)->test(Index::class)->call('toggleActive', $user->id)->assertForbidden();
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_search_and_filters(): void
    {
        $this->activateLicense();
        $this->makeUser(SystemRole::Accountant, ['name' => 'Zed Accountant', 'email' => 'zed@example.com']);
        $this->makeUser(SystemRole::Viewer, ['name' => 'Yan Viewer', 'email' => 'yan@example.com']);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('search', 'zed')
            ->assertSee('zed@example.com')
            ->assertDontSee('yan@example.com')
            ->set('search', '')
            ->set('role', SystemRole::Viewer->value)
            ->assertSee('yan@example.com')
            ->assertDontSee('zed@example.com');
    }
}
