<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Services\Backup\BackupService;
use App\Services\SettingsService;
use App\Services\System\AutomaticTasks;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Background work runs by itself after page loads — no cron job.
 */
class AutomaticTasksTest extends TestCase
{
    use RefreshDatabase;

    protected string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['labeldesk.display_timezone' => 'UTC']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->activateLicense();

        $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld-auto-'.uniqid();
        config(['labeldesk.backups.path' => $this->backupDir, 'labeldesk.backups.private' => $this->backupDir.'-p', 'labeldesk.backups.public' => $this->backupDir.'-u']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        parent::tearDown();
    }

    public function test_due_tasks_run_and_are_not_repeated_too_soon(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00'));

        $ran = app(AutomaticTasks::class)->runDue();
        $this->assertSame(['license', 'queue', 'media', 'backup', 'cleanup', 'updates'], $ran);
        $this->assertNotNull(Cache::get(AutomaticTasks::LAST_RUN_KEY));

        // Within the same minute nothing runs (cheap gate).
        $this->assertSame([], app(AutomaticTasks::class)->runDue());

        // Two minutes later only the every-minute queue is due.
        $this->travel(2)->minutes();
        $this->assertSame(['queue'], app(AutomaticTasks::class)->runDue());

        // Six minutes later the media sync is due again.
        $this->travel(5)->minutes();
        $this->assertSame(['queue', 'media'], app(AutomaticTasks::class)->runDue());
    }

    public function test_nightly_backup_runs_once_a_day_from_two_am(): void
    {
        app(SettingsService::class)->set('backups.schedule', true);

        $this->travelTo(CarbonImmutable::parse('2026-11-25 01:30:00'));
        $this->assertNotContains('backup', app(AutomaticTasks::class)->runDue(force: true), 'not before 02:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-25 02:05:00'));
        $this->assertContains('backup', app(AutomaticTasks::class)->runDue(force: true));
        $this->assertCount(1, app(BackupService::class)->list());

        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00:00'));
        $this->assertNotContains('backup', app(AutomaticTasks::class)->runDue(force: true), 'once a day');

        $this->travelTo(CarbonImmutable::parse('2026-11-26 03:00:00'));
        $this->assertContains('backup', app(AutomaticTasks::class)->runDue(force: true));
    }

    public function test_only_one_run_at_a_time(): void
    {
        $lock = Cache::lock('labeldesk:auto:running', 600);
        $lock->get();

        $this->assertSame([], app(AutomaticTasks::class)->runDue(force: true));
        $lock->release();
    }

    public function test_system_page_shows_automatic_tasks_instead_of_cron(): void
    {
        app(AutomaticTasks::class)->runDue(force: true);

        $this->actingAs($this->makeUser(SystemRole::SuperAdmin))->get('/system')->assertOk()
            ->assertSee('No cron job needed')
            ->assertDontSee('License check with MusicDesk.tech')->assertSee('Cloud media uploads and clean-up')
            ->assertDontSee('schedule:run');
    }
}
