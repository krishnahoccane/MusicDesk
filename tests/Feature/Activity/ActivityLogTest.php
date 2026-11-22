<?php

namespace Tests\Feature\Activity;

use App\Enums\SystemRole;
use App\Livewire\Activity\Index;
use App\Models\ActivityLog;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_sign_in_sign_out_and_failures_are_logged(): void
    {
        $user = $this->makeUser(SystemRole::Viewer);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.failed', 'user_id' => null]);
        $this->assertStringNotContainsString('wrong-password', (string) ActivityLog::query()->latest('id')->first()->getRawOriginal('properties'));

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.login', 'user_id' => $user->id]);

        $this->post('/logout');
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_sensitive_properties_are_never_stored(): void
    {
        $entry = app(ActivityLogger::class)->log('test.event', 'Test', null, [
            'password' => 'x', 'new_password' => 'x', 'activation_secret' => 'x', 'api_key' => 'x',
            'license_key' => 'x', 'token' => 'x', 'key' => 'x',
            'name' => 'kept', 'nested' => ['smtp_password' => 'x', 'host' => 'kept'],
        ]);

        $this->assertSame(['name' => 'kept', 'nested' => ['host' => 'kept']], $entry->properties);
    }

    public function test_only_users_with_permission_can_view_the_log(): void
    {
        $admin = $this->makeUser(SystemRole::SuperAdmin);
        $manager = $this->makeUser(SystemRole::LabelManager);
        $viewer = $this->makeUser(SystemRole::Viewer);
        app(ActivityLogger::class)->log('user.created', 'Created user Somebody', causer: $admin);

        $this->actingAs($viewer)->get('/activity')->assertForbidden();
        $this->actingAs($manager)->get('/activity')->assertOk()->assertSee('Created user Somebody');
        $this->actingAs($admin)->get('/activity')->assertOk();
    }

    public function test_filters(): void
    {
        $admin = $this->makeUser(SystemRole::SuperAdmin);
        $other = $this->makeUser(SystemRole::Viewer);
        $logger = app(ActivityLogger::class);
        $logger->log('user.created', 'Created user Alpha', causer: $admin);
        $logger->log('role.updated', 'Updated role Beta', causer: $other);
        $old = $logger->log('settings.updated', 'Updated general settings Gamma', causer: $admin);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Alpha')->assertSee('Beta')->assertSee('Gamma')
            ->set('event', 'role.updated')
            ->assertSee('Beta')->assertDontSee('Alpha')
            ->set('event', '')
            ->set('user', (string) $admin->id)
            ->assertSee('Alpha')->assertDontSee('Beta')
            ->set('user', '')
            ->set('from', now()->subDays(2)->format('Y-m-d'))
            ->assertSee('Alpha')->assertDontSee('Gamma')
            ->set('from', '')
            ->set('search', 'gamma')
            ->assertSee('Gamma')->assertDontSee('Alpha');
    }

    public function test_old_entries_are_pruned_by_retention(): void
    {
        app(SettingsService::class)->set('activity.retention_days', 30);
        $logger = app(ActivityLogger::class);
        $logger->log('user.created', 'Recent');
        $logger->log('user.created', 'Old')->forceFill(['created_at' => now()->subDays(31)])->save();

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertSame(['Recent'], ActivityLog::query()->pluck('description')->all());
    }

    public function test_zero_retention_keeps_everything(): void
    {
        app(SettingsService::class)->set('activity.retention_days', 0);
        app(ActivityLogger::class)->log('user.created', 'Ancient')->forceFill(['created_at' => now()->subYears(5)])->save();

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->count());
    }
}
