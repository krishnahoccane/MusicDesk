<?php

namespace Tests\Feature\Settings;

use App\Enums\SystemRole;
use App\Livewire\Settings\Appearance;
use App\Models\User;
use App\Services\SettingsApplier;
use App\Services\SettingsService;
use App\Support\Appearance as AppearanceSupport;
use App\Support\Branding;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        Storage::fake('public');
    }

    protected function settings(array $values): void
    {
        app(SettingsService::class)->setMany($values);
        app(SettingsApplier::class)->apply();
    }

    public function test_musicdesk_is_the_default_theme_in_dark_mode(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')
            ->assertSee('data-ld-theme="musicdesk"', false)
            ->assertSee('data-theme-preference="dark"', false)
            ->assertSee('data-ld-font="inter-tight"', false)
            ->assertSee('serif-accent text-gradient', false);
    }

    public function test_custom_brand_colours_are_printed_as_css(): void
    {
        $this->settings(['appearance.brand_color' => '#ff5500', 'appearance.brand_color_2' => '#22d9a0']);

        $this->actingAs($this->admin)->get('/dashboard')
            ->assertSee('--ld-accent:#ff5500;--ld-accent-rgb:255, 85, 0;', false)
            ->assertSee("[data-bs-theme='dark']", false);

        // Anything that is not a #rrggbb colour is ignored.
        $this->settings(['appearance.brand_color' => 'red;}</style><script>', 'appearance.brand_color_2' => 'nope']);
        $this->assertSame('', AppearanceSupport::themeCss());
    }

    public function test_sign_in_page_uses_the_chosen_design_and_texts(): void
    {
        $this->settings(['appearance.login_layout' => 'cover', 'appearance.login_accent' => 'Moonlight', 'appearance.login_note' => 'Need access? Ask Priya.']);

        $this->get('/login')->assertOk()
            ->assertSee('ld-login--cover', false)
            ->assertSee('Moonlight')
            ->assertSee('Need access? Ask Priya.');

        $this->settings(['appearance.login_layout' => 'minimal']);
        $this->get('/login')->assertSee('ld-login--minimal', false);
    }

    public function test_admins_can_preview_every_design(): void
    {
        foreach (array_keys(AppearanceSupport::LOGIN_LAYOUTS) as $design) {
            $this->actingAs($this->admin)->get('/settings/appearance/login-preview/'.$design)
                ->assertOk()->assertSee('ld-login--'.$design, false)->assertSee('The form is disabled');
        }

        $this->actingAs($this->makeUser(SystemRole::Viewer))->get('/settings/appearance/login-preview/split')->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/appearance/login-preview/unknown')->assertNotFound();
    }

    public function test_logo_upload_and_branding_route(): void
    {
        Livewire::actingAs($this->admin)->test(Appearance::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 400, 100))
            ->set('login_layout', 'split')
            ->set('login.accent', 'Acme')
            ->call('save')
            ->assertHasNoErrors();

        $name = (string) app(SettingsService::class)->get('appearance.logo');
        $this->assertMatchesRegularExpression(Branding::NAME_PATTERN, $name);
        $this->assertSame('split', app(SettingsService::class)->get('appearance.login_layout'));

        // Served publicly (sign-in page), with a strict content policy.
        auth()->logout();
        $this->get('/branding/'.$name)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/login')->assertSee(route('branding.file', $name), false);

        $this->get('/branding/..%2F..%2F.env')->assertNotFound();
        $this->get('/branding/logo-aaaaaaaaaaaaaaaa.png')->assertNotFound();
    }

    public function test_unsafe_svg_logos_are_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>');

        Livewire::actingAs($this->admin)->test(Appearance::class)
            ->set('logo', $svg)
            ->call('save')
            ->assertHasErrors('logo');

        $this->assertNull(app(SettingsService::class)->get('appearance.logo'));
        $this->assertTrue(Branding::safeSvg('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1"/></svg>'));
    }
}
