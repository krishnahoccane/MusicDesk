<?php

namespace Tests\Feature\Settings;

use App\Enums\SystemRole;
use App\Livewire\Backups\Index as Backups;
use App\Livewire\Settings\Catalog;
use App\Livewire\System\Info;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Release;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\SettingsService;
use App\Services\System\UpgradeService;
use App\Support\CatalogDefaults;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;
use ZipArchive;

class BackupsAndSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();

        // Isolated storage so backups never touch real files.
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app/private/documents');
        File::ensureDirectoryExists($this->storage.'/app/private/livewire-tmp');
        File::ensureDirectoryExists($this->storage.'/app/public/artwork');
        file_put_contents($this->storage.'/app/private/documents/contract.pdf', '%PDF-1.4 test');
        file_put_contents($this->storage.'/app/private/livewire-tmp/upload.tmp', 'temporary');
        file_put_contents($this->storage.'/app/public/artwork/cover.jpg', 'jpeg');
        config([
            'labeldesk.backups.path' => $this->storage.'/app/private/backups',
            'labeldesk.backups.private' => $this->storage.'/app/private',
            'labeldesk.backups.public' => $this->storage.'/app/public',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_backup_contains_database_files_and_manifest(): void
    {
        Release::factory()->create(['title' => "It's \"Quoted\" — ünïcode"]);

        $result = app(BackupService::class)->create(true, $this->admin);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->storage.'/app/private/backups/'.$result['name']));
        $this->assertNotFalse($zip->locateName('database.sql'));
        $this->assertNotFalse($zip->locateName('files/private/documents/contract.pdf'));
        $this->assertNotFalse($zip->locateName('files/public/artwork/cover.jpg'));
        $this->assertFalse($zip->locateName('files/private/livewire-tmp/upload.tmp'));
        $this->assertFalse($zip->locateName('.env'));

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $sql = $zip->getFromName('database.sql');
        $zip->close();

        $this->assertSame('labeldesk-backup-1', $manifest['format']);
        $this->assertSame(1, $manifest['tables']['releases']);
        $this->assertSame(0, $manifest['tables']['sessions'], 'session rows are never dumped');

        // The dump restores into an empty database with identical row counts.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($sql);
        foreach ($manifest['tables'] as $table => $count) {
            $this->assertSame($count, (int) $pdo->query("select count(*) from \"{$table}\"")->fetchColumn(), $table);
        }
        $this->assertSame("It's \"Quoted\" — ünïcode", $pdo->query('select title from releases')->fetchColumn());

        $this->assertDatabaseHas('activity_logs', ['event' => 'backup.created']);
    }

    public function test_retention_keeps_only_the_newest_backups(): void
    {
        app(SettingsService::class)->set('backups.retention', 2);
        $dir = $this->storage.'/app/private/backups';
        File::ensureDirectoryExists($dir);
        foreach (['2026-01-01-010101', '2026-01-02-010101', '2026-01-03-010101'] as $stamp) {
            file_put_contents("{$dir}/labeldesk-backup-{$stamp}.zip", 'x');
        }
        file_put_contents("{$dir}/not-a-backup.zip", 'x');

        $this->assertSame(1, app(BackupService::class)->prune());
        $this->assertFileDoesNotExist("{$dir}/labeldesk-backup-2026-01-01-010101.zip");
        $this->assertFileExists("{$dir}/labeldesk-backup-2026-01-03-010101.zip");
        $this->assertFileExists("{$dir}/not-a-backup.zip");
    }

    public function test_backups_screen_creates_downloads_and_deletes(): void
    {
        $this->actingAs($this->admin)->get('/backups')->assertOk()->assertSee('No backups yet');

        Livewire::actingAs($this->admin)->test(Backups::class)
            ->set('includeFiles', false)
            ->call('create')
            ->assertDispatched('toast', type: 'success');

        $name = app(BackupService::class)->list()[0]['name'];
        $this->assertStringEndsWith('-db.zip', $name);

        $this->actingAs($this->admin)->get("/backups/{$name}/download")->assertOk()->assertDownload($name);
        $this->actingAs($this->admin)->get('/backups/..%2F..%2F.env/download')->assertNotFound();

        Livewire::actingAs($this->admin)->test(Backups::class)->call('delete', $name)->assertDispatched('toast', type: 'success');
        $this->assertSame([], app(BackupService::class)->list());

        Livewire::actingAs($this->admin)->test(Backups::class)->call('delete', '../../.env')->assertDispatched('toast', type: 'error');
    }

    public function test_backup_schedule_settings_and_command(): void
    {
        Livewire::actingAs($this->admin)->test(Backups::class)
            ->set('retention', 0)->call('saveSettings')->assertHasErrors('retention')
            ->set('retention', 5)->set('schedule', true)->call('saveSettings')->assertHasNoErrors();

        $this->assertTrue(app(BackupService::class)->scheduled());
        $this->assertSame(5, app(BackupService::class)->retention());

        $this->artisan('labeldesk:backup --scheduled --database-only')->assertSuccessful();
        $this->assertCount(1, app(BackupService::class)->list());
    }

    public function test_only_backup_managers_can_use_backups(): void
    {
        $manager = $this->makeUser(SystemRole::LabelManager);
        $this->actingAs($manager)->get('/backups')->assertForbidden();
        $this->actingAs($manager)->get('/system')->assertForbidden();
        $this->actingAs($manager)->get('/settings/catalog')->assertForbidden();
    }

    public function test_catalogue_defaults_are_used_for_new_releases(): void
    {
        Livewire::actingAs($this->admin)->test(Catalog::class)
            ->set('label_name', 'Moonlight Music')
            ->set('c_line_format', '{year} {label} Ltd.')
            ->set('p_line_format', '{label}')
            ->set('default_language', 'hi')
            ->call('saveDefaults')
            ->assertHasNoErrors();

        $this->assertSame(now()->year.' Moonlight Music Ltd.', CatalogDefaults::cLine());
        $this->assertSame('Moonlight Music', CatalogDefaults::pLine());

        $this->actingAs($this->admin)->get('/releases/create')->assertOk()
            ->assertSee(now()->year.' Moonlight Music Ltd.');
    }

    public function test_genres_and_languages_can_be_managed(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Catalog::class)
            ->set('newGenre', 'Sufi Rock')->call('addGenre')->assertHasNoErrors()
            ->set('newGenre', 'Sufi Rock')->call('addGenre')->assertHasErrors('newGenre');

        $genre = Genre::query()->where('name', 'Sufi Rock')->sole();
        $component->call('toggleGenre', $genre->id);
        $this->assertFalse($genre->fresh()->is_active);

        // A genre in use cannot be deleted.
        Release::factory()->create(['genre_id' => $genre->id]);
        $component->call('deleteGenre', $genre->id)->assertDispatched('toast', type: 'error');
        $this->assertModelExists($genre);

        $component->set('newLanguageCode', 'XX1')->call('addLanguage')->assertHasErrors('newLanguageCode')
            ->set('newLanguageCode', 'bho')->set('newLanguageName', 'Bhojpuri')->call('addLanguage')->assertHasNoErrors();
        $language = Language::query()->where('code', 'bho')->sole();
        $component->call('deleteLanguage', $language->id);
        $this->assertModelMissing($language);

        // Upgrades never re-add deleted reference data.
        Genre::query()->where('name', 'Jazz')->delete();
        $this->seed(CatalogReferenceSeeder::class);
        $this->assertFalse(Genre::query()->where('name', 'Jazz')->exists());
    }

    public function test_system_page_and_upgrade(): void
    {
        app(SettingsService::class)->set('system.installed_version', '0.9.0');

        $this->actingAs($this->admin)->get('/system')->assertOk()
            ->assertSee('A database upgrade is ready')->assertSee('Scheduler (cron)');

        Livewire::actingAs($this->admin)->test(Info::class)->call('upgrade')->assertDispatched('toast', type: 'success');

        $this->assertSame(config('labeldesk.product.version'), app(UpgradeService::class)->installedVersion());
        $this->assertFalse(app(UpgradeService::class)->needed());
        $this->assertDatabaseHas('activity_logs', ['event' => 'system.upgraded']);

        $this->artisan('labeldesk:upgrade --force')->expectsOutputToContain('already up to date')->assertSuccessful();
    }
}
