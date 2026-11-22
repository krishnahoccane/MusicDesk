<?php

namespace Tests\Feature\Settings;

use App\Enums\SystemRole;
use App\Livewire\Settings\General;
use App\Livewire\Settings\Mail;
use App\Models\LicenseActivation;
use App\Models\Setting;
use App\Models\User;
use App\Services\License\LicenseValidationService;
use App\Services\SettingsApplier;
use App\Services\SettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
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

    public function test_only_settings_managers_can_open_settings(): void
    {
        $manager = $this->makeUser(SystemRole::LabelManager);

        $this->actingAs($manager)->get('/settings')->assertForbidden();
        $this->actingAs($manager)->get('/settings/mail')->assertForbidden();
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee('Display timezone');
        $this->actingAs($this->admin)->get('/settings/mail')->assertOk()->assertSee('Outgoing mail');
    }

    public function test_general_settings_are_saved_and_applied(): void
    {
        Livewire::actingAs($this->admin)->test(General::class)
            ->set('app_name', 'Moonlight Records')
            ->set('timezone', 'Asia/Kolkata')
            ->set('currency', 'EUR')
            ->set('date_format', 'Y-m-d')
            ->set('time_format', 'h:i A')
            ->set('retention_days', 90)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Moonlight Records', config('app.name'));
        $this->assertSame('Asia/Kolkata', config('labeldesk.display_timezone'));
        $this->assertSame('EUR', config('labeldesk.currency'));
        $this->assertSame('Y-m-d, h:i A', config('labeldesk.datetime_format'));
        $this->assertSame(90, app(SettingsService::class)->get('activity.retention_days'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'settings.updated']);
    }

    public function test_general_settings_validation(): void
    {
        Livewire::actingAs($this->admin)->test(General::class)
            ->set('timezone', 'Mars/Olympus')
            ->set('currency', 'XXX')
            ->set('date_format', 'Y')
            ->set('retention_days', -1)
            ->call('save')
            ->assertHasErrors(['timezone', 'currency', 'date_format', 'retention_days']);
    }

    public function test_smtp_password_is_encrypted_and_never_returned(): void
    {
        Livewire::actingAs($this->admin)->test(Mail::class)
            ->set('mailer', 'smtp')
            ->set('host', 'smtp.example.com')
            ->set('port', 465)
            ->set('scheme', 'smtps')
            ->set('username', 'mailer')
            ->set('password', 'Sup3r$ecret')
            ->set('from_address', 'label@example.com')
            ->set('from_name', 'Moonlight')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('password', '')
            ->assertSet('hasPassword', true);

        $row = Setting::query()->where('group', 'mail')->where('key', 'password')->firstOrFail();
        $this->assertNotSame('Sup3r$ecret', $row->value);
        $this->assertStringNotContainsString('Sup3r$ecret', (string) json_encode(Cache::get('labeldesk.settings')));

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('Sup3r$ecret', config('mail.mailers.smtp.password'));

        // Reopening the page never sends the password back to the browser.
        Livewire::actingAs($this->admin)->test(Mail::class)
            ->assertSet('password', '')
            ->assertDontSee('Sup3r$ecret');

        // Blank keeps the saved password; "remove" clears it.
        Livewire::actingAs($this->admin)->test(Mail::class)->call('save');
        $this->assertSame('Sup3r$ecret', app(SettingsService::class)->get('mail.password'));
        Livewire::actingAs($this->admin)->test(Mail::class)->set('forget_password', true)->call('save');
        $this->assertNull(app(SettingsService::class)->get('mail.password'));

        $this->assertDatabaseMissing('activity_logs', ['properties' => '%Sup3r%']);
    }

    public function test_test_email_uses_saved_settings(): void
    {
        app(SettingsService::class)->setMany(['mail.mailer' => 'log', 'mail.from_address' => 'label@example.com', 'mail.from_name' => 'Label']);
        app(SettingsApplier::class)->apply();

        Livewire::actingAs($this->admin)->test(Mail::class)
            ->call('sendTest')
            ->assertDispatched('toast', type: 'success');

        $this->assertDatabaseHas('activity_logs', ['event' => 'settings.mail_test']);
    }

    public function test_settings_cannot_be_changed_in_restricted_mode(): void
    {
        LicenseActivation::query()->update(['status' => 'deactivated']);
        app(LicenseValidationService::class)->forget();

        Livewire::actingAs($this->admin)->test(General::class)
            ->set('app_name', 'Changed')
            ->call('save')
            ->assertForbidden();
    }
}
