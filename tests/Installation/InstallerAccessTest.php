<?php

namespace Tests\Installation;

use App\Services\Installation\InstallationState;
use App\Services\Installation\RequirementsChecker;
use Tests\TestCase;

class InstallerAccessTest extends TestCase
{
    protected bool $installed = false;

    public function test_application_redirects_to_installer_before_installation(): void
    {
        $this->get('/')->assertRedirect('/install');
        $this->get('/login')->assertRedirect('/install');
        $this->get('/dashboard')->assertRedirect('/install');
    }

    public function test_welcome_page_renders_with_version(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('Welcome to')
            ->assertSee('LabelDesk')
            ->assertSee(config('labeldesk.product.version'));
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->post('/install', [])->assertSessionHasErrors('terms');
        $this->post('/install', ['terms' => '1'])->assertRedirect('/install/license');
    }

    protected function passLicense(): void
    {
        $this->post('/install', ['terms' => '1']);
        $this->post('/install/license', ['license_key' => 'MD-LD-TEST-ACTV-AAAA-BBBB', 'url' => 'https://label.example.com', 'accept' => '1']);
        $this->post('/install/license/continue')->assertRedirect('/install/requirements');
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $this->get('/install/database')->assertRedirect('/install');
        $this->get('/install/run')->assertRedirect('/install');
        $this->post('/install/run')->assertRedirect('/install');
    }

    public function test_requirements_page_lists_checks(): void
    {
        $this->passLicense();

        $this->get('/install/requirements')
            ->assertOk()
            ->assertSee('PHP version')
            ->assertSee('pdo_mysql')
            ->assertSee('bootstrap/cache');
    }

    public function test_failed_requirements_block_progress(): void
    {
        $this->mock(RequirementsChecker::class, function ($mock) {
            $mock->shouldReceive('passes')->andReturn(false);
        });

        $this->passLicense();
        $this->post('/install/requirements')->assertSessionHas('error');
        $this->get('/install/database')->assertRedirect('/install/requirements');
    }

    public function test_installer_is_unavailable_after_installation(): void
    {
        app(InstallationState::class)->writeLock(['installation_id' => self::INSTALLATION_ID]);

        $this->get('/install')->assertNotFound();
        $this->get('/install/database')->assertNotFound();
        $this->post('/install/run')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_completion_page_requires_valid_token(): void
    {
        $this->get('/install/complete')->assertNotFound();
        $this->get('/install/complete?token=guess')->assertNotFound();
    }
}
