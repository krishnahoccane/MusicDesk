<?php

namespace Tests\Feature\Roles;

use App\Enums\SystemRole;
use App\Livewire\Roles\Form;
use App\Livewire\Roles\Index;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
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
        $viewerRole = Role::findByName(SystemRole::Viewer->value);

        $this->actingAs($viewer)->get('/roles')->assertForbidden();
        $this->actingAs($manager)->get('/roles')->assertOk()->assertDontSee('New role');
        $this->actingAs($manager)->get("/roles/{$viewerRole->id}")->assertOk()->assertSee('You can view this role but not change it.');
        $this->actingAs($manager)->get('/roles/create')->assertForbidden();
        $this->actingAs($this->admin)->get('/roles')->assertOk()->assertSee('New role');
    }

    public function test_custom_role_can_be_created_when_the_plan_allows(): void
    {
        $this->activateLicense('ACTV');

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'A&R Scout')
            ->set('permissions', ['artists.view', 'releases.view', 'not.a.permission'])
            ->call('save')
            ->assertHasErrors('permissions.2');

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'A&R Scout')
            ->set('permissions', ['artists.view', 'releases.view'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('roles.index'));

        $role = Role::findByName('A&R Scout');
        $this->assertEqualsCanonicalizing(['artists.view', 'releases.view'], $role->permissions->pluck('name')->all());
        $this->assertDatabaseHas('activity_logs', ['event' => 'role.created']);
    }

    public function test_custom_roles_require_the_license_feature(): void
    {
        $this->activateLicense('STRT'); // no custom_roles

        $this->actingAs($this->admin)->get('/roles/create')->assertForbidden();
        $this->actingAs($this->admin)->get('/roles')->assertSee('Custom roles are not included in your license plan.');

        // Built-in role permissions remain editable on any plan.
        $viewer = Role::findByName(SystemRole::Viewer->value);
        Livewire::actingAs($this->admin)->test(Form::class, ['role' => $viewer])
            ->set('permissions', ['artists.view'])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(['artists.view'], $viewer->fresh()->permissions->pluck('name')->all());
    }

    public function test_super_admin_role_is_immutable_and_system_roles_keep_their_names(): void
    {
        $this->activateLicense();
        $super = Role::findByName(SystemRole::SuperAdmin->value);
        $viewer = Role::findByName(SystemRole::Viewer->value);

        Livewire::actingAs($this->admin)->test(Form::class, ['role' => $super])
            ->set('permissions', [])
            ->call('save')
            ->assertForbidden();
        $this->assertGreaterThan(30, $super->fresh()->permissions()->count());

        Livewire::actingAs($this->admin)->test(Form::class, ['role' => $viewer])
            ->set('name', 'Guest')
            ->call('save')
            ->assertHasErrors('form');
        $this->assertSame(SystemRole::Viewer->value, $viewer->fresh()->name);

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $viewer->id)->assertForbidden();
        $this->assertNotNull(Role::query()->find($viewer->id));
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $this->activateLicense();
        $role = Role::create(['name' => 'Interns', 'guard_name' => 'web']);
        $intern = $this->makeUser('Interns');

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $role->id);
        $this->assertNotNull(Role::query()->find($role->id));

        $intern->syncRoles([SystemRole::Viewer->value]);
        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $role->id);
        $this->assertNull(Role::query()->find($role->id));
        $this->assertDatabaseHas('activity_logs', ['event' => 'role.deleted']);
    }

    public function test_role_managers_cannot_grant_permissions_they_lack(): void
    {
        $this->activateLicense();
        $roleAdmin = Role::create(['name' => 'Role Admin', 'guard_name' => 'web']);
        $roleAdmin->givePermissionTo(['roles.view', 'roles.manage', 'artists.view']);
        $actor = $this->makeUser('Role Admin');
        $viewer = Role::findByName(SystemRole::Viewer->value);

        Livewire::actingAs($actor)->test(Form::class, ['role' => $viewer])
            ->set('permissions', [...$viewer->permissions->pluck('name')->all(), 'license.manage'])
            ->call('save')
            ->assertHasErrors('form');
        $this->assertFalse($viewer->fresh()->hasPermissionTo('license.manage'));
    }

    public function test_seeder_does_not_undo_administrator_changes(): void
    {
        $viewer = Role::findByName(SystemRole::Viewer->value);
        $viewer->revokePermissionTo('reports.view');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse($viewer->fresh()->hasPermissionTo('reports.view'));
    }
}
