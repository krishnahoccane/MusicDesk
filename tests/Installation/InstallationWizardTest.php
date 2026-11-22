<?php

namespace Tests\Installation;

use App\Enums\SystemRole;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\Installation\DatabaseConfigurator;
use App\Services\Installation\DatabaseInspector;
use App\Services\Installation\EnvironmentWriter;
use App\Services\Installation\InstallationState;
use App\Services\SettingsService;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Walks the complete browser wizard against the in-memory SQLite test
 * database (the MySQL-specific connection test is mocked).
 */
class InstallationWizardTest extends TestCase
{
    protected bool $installed = false;

    protected string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = storage_path('framework/testing/install-'.uniqid().'.env');
        file_put_contents($this->envPath, "APP_NAME=LabelDesk\nAPP_ENV=production\nDB_CONNECTION=mysql\n");
        $this->app->instance(EnvironmentWriter::class, new EnvironmentWriter($this->envPath));

        // Use the test connection instead of switching to MySQL.
        $this->app->instance(DatabaseConfigurator::class, new class extends DatabaseConfigurator
        {
            public function apply(array $credentials): string
            {
                return config('database.default');
            }
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        parent::tearDown();
    }

    protected function inspectorReturns(array $result): void
    {
        $this->mock(DatabaseInspector::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn($result));
    }

    protected function walkToDatabaseStep(): void
    {
        $this->post('/install', ['terms' => '1'])->assertRedirect('/install/requirements');
        $this->post('/install/requirements')->assertRedirect('/install/database');
    }

    protected function databaseInput(array $overrides = []): array
    {
        return array_merge([
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'labeldesk',
            'username' => 'labeldesk', 'password' => 'S3cret "pass" #1', 'prefix' => '',
        ], $overrides);
    }

    public function test_database_connection_failure_is_reported_without_echoing_password(): void
    {
        $this->inspectorReturns(['ok' => false, 'message' => 'Access denied. Check the database username and password.']);
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput())
            ->assertSessionHasErrors('database')
            ->assertSessionMissing('_old_input.password');

        $this->get('/install/application')->assertRedirect('/install/database');
    }

    public function test_non_empty_database_is_never_used(): void
    {
        $this->inspectorReturns(['ok' => true, 'message' => 'Connected', 'server' => 'MySQL', 'version' => '8.0.36', 'existing_tables' => 12]);
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput())->assertSessionHasErrors('database');
        $this->get('/install/application')->assertRedirect('/install/database');
    }

    public function test_database_input_is_validated(): void
    {
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput(['host' => 'bad host;', 'port' => 0, 'prefix' => 'Bad-Prefix']))
            ->assertSessionHasErrors(['host', 'port', 'prefix']);
    }

    public function test_weak_administrator_password_is_rejected(): void
    {
        $this->inspectorReturns(['ok' => true, 'message' => 'Connected', 'server' => 'MySQL', 'version' => '8.0.36', 'existing_tables' => 0]);
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());
        $this->post('/install/application', ['name' => 'Acme Records', 'url' => 'https://label.example.com', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'locale' => 'en']);

        $this->post('/install/administrator', ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
    }

    public function test_invalid_license_blocks_installation(): void
    {
        $this->inspectorReturns(['ok' => true, 'message' => 'Connected', 'server' => 'MySQL', 'version' => '8.0.36', 'existing_tables' => 0]);
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());
        $this->post('/install/application', ['name' => 'Acme Records', 'url' => 'https://label.example.com', 'timezone' => 'UTC', 'currency' => 'USD', 'locale' => 'en']);
        $this->post('/install/administrator', ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd']);

        $this->post('/install/license', ['license_key' => 'MD-LD-TEST-EXPD-AAAA-BBBB', 'accept' => '1'])->assertSessionHasErrors('license_key');
        $this->post('/install/license', ['license_key' => 'MD-LD-XXXX-XXXX-XXXX-XXXX', 'accept' => '1'])->assertSessionHasErrors('license_key');
        $this->post('/install/license/continue')->assertSessionHasErrors('license_key');
        $this->get('/install/run')->assertRedirect('/install/license');
    }

    public function test_full_installation_creates_admin_license_settings_env_and_lock(): void
    {
        $this->inspectorReturns(['ok' => true, 'message' => 'Connected to MySQL 8.0.36.', 'server' => 'MySQL', 'version' => '8.0.36', 'existing_tables' => 0]);
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput())->assertRedirect('/install/application');
        $this->post('/install/application', [
            'name' => 'Acme Records', 'url' => 'https://label.example.com/', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'locale' => 'en',
        ])->assertRedirect('/install/administrator');
        $this->post('/install/administrator', [
            'name' => 'Priya Admin', 'email' => 'Admin@Example.com', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
        ])->assertRedirect('/install/license');
        $this->post('/install/license', ['license_key' => 'md-ld-test-actv-aaaa-bbbb', 'accept' => '1'])->assertRedirect('/install/license');
        $this->get('/install/license')->assertSee('License activated')->assertSee('business');
        $this->post('/install/license/continue')->assertRedirect('/install/run');
        $this->get('/install/run')->assertOk()->assertSee('Acme Records')->assertDontSee('S3cret');

        $response = $this->post('/install/run');
        $response->assertRedirect();
        $this->assertStringContainsString('/install/complete?token=', $response->headers->get('Location'));

        // Lock + database
        $this->assertTrue(app(InstallationState::class)->isInstalled());
        $this->assertTrue(Schema::hasTable('license_activations'));

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Str0ngPassw0rd', $admin->password));
        $this->assertTrue($admin->hasRole(SystemRole::SuperAdmin->value));
        $this->assertSame(6, Role::query()->count());

        $activation = LicenseActivation::query()->firstOrFail();
        $this->assertSame('label.example.com', $activation->domain);
        $this->assertSame('business', $activation->plan);
        $this->assertSame('MD-LD-TEST-ACTV-AAAA-BBBB', $activation->license_key);
        $this->assertNotSame('MD-LD-TEST-ACTV-AAAA-BBBB', $activation->getRawOriginal('license_key'), 'License key must be stored encrypted');

        $this->assertSame('INR', app(SettingsService::class)->get('general.currency'));

        // Environment file
        $env = Dotenv::parse((string) file_get_contents($this->envPath));
        $this->assertSame('S3cret "pass" #1', $env['DB_PASSWORD']);
        $this->assertSame('https://label.example.com', $env['APP_URL']);
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('database', $env['SESSION_DRIVER']);
        $this->assertSame('Asia/Kolkata', $env['LABELDESK_TIMEZONE']);

        // Completion page works exactly once, then the installer is gone.
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('LabelDesk is installed')->assertSee('admin@example.com');
        $this->get($response->headers->get('Location'))->assertNotFound();
        $this->get('/install')->assertNotFound();
    }

    public function test_installer_session_never_stores_plain_passwords(): void
    {
        $this->inspectorReturns(['ok' => true, 'message' => 'Connected', 'server' => 'MySQL', 'version' => '8.0.36', 'existing_tables' => 0]);
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());
        $this->post('/install/application', ['name' => 'Acme', 'url' => 'https://label.example.com', 'timezone' => 'UTC', 'currency' => 'USD', 'locale' => 'en']);
        $this->post('/install/administrator', ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd']);

        $session = json_encode(session()->all());
        $this->assertStringNotContainsString('S3cret', $session);
        $this->assertStringNotContainsString('Str0ngPassw0rd', $session);

        $progress = (string) @file_get_contents(config('labeldesk.installer.progress_path'));
        $this->assertStringNotContainsString('S3cret', $progress);
    }
}
