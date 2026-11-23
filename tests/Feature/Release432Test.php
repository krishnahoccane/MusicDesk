<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\Labels\Index as LabelsIndex;
use App\Livewire\Releases\Form as ReleaseForm;
use App\Livewire\Settings\General;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\Artist;
use App\Models\Label;
use App\Models\LicenseActivation;
use App\Models\Release;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseCacheService;
use App\Services\License\LicenseValidationService;
use App\Services\License\Providers\MockLicenseProvider;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 4.3.2: backups pause near expiry, layout fixes, labels (sub-labels) and sign-up.
 */
class Release432Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected LicenseActivation $activation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activation = $this->activateLicense();
    }

    protected function licenseExpires(?CarbonImmutable $expires): void
    {
        $context = ActivationContext::for((string) config('app.url'), self::INSTALLATION_ID);
        $result = app(MockLicenseProvider::class)->issue($context, (string) $this->activation->activation_id, [
            'plan' => 'business', 'status' => 'active', 'user_limit' => 25,
            'features' => ['artists', 'releases', 'tracks', 'distribution', 'royalties', 'documents', 'reports', 'backups'],
            'expires' => $expires?->timestamp,
        ], 30);
        app(LicenseActivationService::class)->applyResult($this->activation, $result);
        app(LicenseCacheService::class)->store($this->activation, $result);
        app(LicenseValidationService::class)->forget();
    }

    // ------------------------------------------------------------ 1. backups

    public function test_backups_pause_within_30_days_of_expiry(): void
    {
        $this->licenseExpires(now()->addDays(45)->toImmutable());
        $this->assertNull(app(BackupService::class)->pausedReason());

        $this->licenseExpires(now()->addDays(20)->toImmutable());
        $reason = app(BackupService::class)->pausedReason();
        $this->assertStringContainsString('paused', (string) $reason);

        try {
            app(BackupService::class)->create(false);
            $this->fail('Backups should be paused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('paused', $e->getMessage());
        }

        Livewire::actingAs($this->admin)->test(BackupsIndex::class)
            ->assertSee('Backups are paused')->call('create')->assertDispatched('toast', type: 'warning');
    }

    // ------------------------------------------------------------ 2-5. layout

    public function test_layout_shows_version_only_and_no_product_name(): void
    {
        $page = $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $page->assertSee('v'.config('labeldesk.product.version'));
        $page->assertDontSee('<small>LabelDesk</small>', false);
        $page->assertDontSee('LabelDesk '.config('labeldesk.product.version'));

        auth()->logout();
        $this->get('/login')->assertOk()->assertDontSee('Powered by');
    }

    // ------------------------------------------------------------ 7. labels

    public function test_labels_can_be_managed_and_assigned_to_releases(): void
    {
        Livewire::actingAs($this->admin)->test(LabelsIndex::class)
            ->call('create')->set('name', 'Night Owl')->set('code', 'nowl')->call('save')->assertHasNoErrors();
        $parent = Label::query()->sole();
        $this->assertSame('NOWL', $parent->code);

        Livewire::actingAs($this->admin)->test(LabelsIndex::class)
            ->call('create')->set('name', 'Night Owl Deep')->set('parent_id', (string) $parent->id)->call('save')->assertHasNoErrors();
        $child = Label::query()->where('name', 'Night Owl Deep')->sole();
        $this->assertSame('Night Owl › Night Owl Deep', $child->path());

        // No cycles.
        Livewire::actingAs($this->admin)->test(LabelsIndex::class)
            ->call('edit', $parent->id)->set('parent_id', (string) $child->id)->call('save')->assertHasErrors('parent_id');

        $artist = Artist::factory()->create();
        Livewire::actingAs($this->admin)->test(ReleaseForm::class)
            ->set('title', 'Moonlight')->set('label_id', (string) $child->id)
            ->set('artistRows', [['artist_id' => $artist->id, 'role' => 'primary']])
            ->call('save')->assertHasNoErrors();
        $release = Release::query()->where('title', 'Moonlight')->sole();
        $this->assertSame($child->id, $release->label_id);
        $this->assertSame('Night Owl Deep', $release->label_name, 'label name delivered to stores follows the label');

        // A label with releases cannot be deleted.
        Livewire::actingAs($this->admin)->test(LabelsIndex::class)->call('delete', $child->id)->assertDispatched('toast', type: 'danger');
        $this->assertNotSoftDeleted($child);

        $this->actingAs($this->admin)->get(route('labels.index'))->assertOk()->assertSee('Night Owl Deep');
        $this->actingAs($this->makeUser(SystemRole::Viewer))->get(route('labels.index'))->assertOk()->assertDontSee('Add label');
        $this->actingAs($this->makeUser(SystemRole::Artist))->get(route('labels.index'))->assertForbidden();
    }

    // ------------------------------------------------------------ 6. sign-up

    public function test_sign_up_is_off_by_default(): void
    {
        $this->get('/login')->assertDontSee('Create an account');
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_settings_turn_sign_up_on(): void
    {
        Livewire::actingAs($this->admin)->test(General::class)
            ->set('registration', true)->set('registration_artists', false)->set('registration_labels', false)
            ->call('save')->assertHasErrors('registration_artists')
            ->set('registration_labels', true)->call('save')->assertHasNoErrors();

        $this->assertTrue((bool) app(SettingsService::class)->get('auth.registration'));
        auth()->logout();
        $this->get('/login')->assertSee('Create an account');
        $this->get('/register')->assertOk()->assertSee('Label name')->assertDontSee('I&#039;m an artist', false);
    }

    public function test_artist_and_label_sign_up_wait_for_approval(): void
    {
        Mail::fake();
        app(SettingsService::class)->setMany(['auth.registration' => true]);

        $this->post('/register', [
            'type' => 'artist', 'name' => 'Ana Ruiz', 'artist_name' => 'Nova Ray', 'country' => 'es',
            'email' => 'Ana@Example.com', 'password' => 'Str0ng-pass!', 'password_confirmation' => 'Str0ng-pass!', 'confirm' => '1',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $artistUser = User::query()->where('email', 'ana@example.com')->sole();
        $this->assertFalse($artistUser->is_active);
        $this->assertTrue($artistUser->isArtistAccount());
        $this->assertSame(['Nova Ray'], $artistUser->artists()->pluck('name')->all());

        // Cannot sign in before approval.
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'Str0ng-pass!'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/register', [
            'type' => 'label', 'name' => 'Sam Lee', 'label_name' => 'Deep Current', 'website' => 'https://deepcurrent.example',
            'email' => 'sam@example.com', 'password' => 'Str0ng-pass!', 'password_confirmation' => 'Str0ng-pass!', 'confirm' => '1',
        ])->assertRedirect(route('login'));
        $labelUser = User::query()->where('email', 'sam@example.com')->sole();
        $label = Label::query()->where('name', 'Deep Current')->sole();
        $this->assertFalse($label->is_active);
        $this->assertSame($labelUser->id, $label->user_id);
        $this->assertSame([], $labelUser->getRoleNames()->all(), 'role is given by the administrator');

        // Approval: activating the user activates the label and emails the person.
        Livewire::actingAs($this->admin)->test(UsersIndex::class)->call('toggleActive', $labelUser->id);
        $this->assertTrue($labelUser->fresh()->is_active);
        $this->assertNotNull($labelUser->fresh()->approved_at);
        $this->assertTrue($label->fresh()->is_active);
    }

    public function test_sign_up_validation_and_bot_trap(): void
    {
        app(SettingsService::class)->setMany(['auth.registration' => true]);

        $this->post('/register', ['type' => 'artist', 'name' => 'X', 'email' => $this->admin->email, 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertSessionHasErrors(['email', 'password', 'artist_name', 'confirm']);

        $this->post('/register', ['type' => 'label', 'name' => 'Bot', 'label_name' => 'Spam', 'email' => 'bot@example.com',
            'password' => 'Str0ng-pass!', 'password_confirmation' => 'Str0ng-pass!', 'confirm' => '1', 'company_url' => 'http://spam.example'])
            ->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'bot@example.com']);
    }
}
