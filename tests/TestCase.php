<?php

namespace Tests;

use App\Enums\SystemRole;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\Installation\InstallationState;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseValidationService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Most tests run against an installed application. Installer tests set
     * this to false.
     */
    protected bool $installed = true;

    public const INSTALLATION_ID = '00000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $token = getmypid().'-'.spl_object_id($this);
        config([
            'labeldesk.installer.lock_path' => $dir."/installed-{$token}.lock",
            'labeldesk.installer.progress_path' => $dir."/progress-{$token}.json",
        ]);

        if ($this->installed) {
            app(InstallationState::class)->writeLock(['installation_id' => self::INSTALLATION_ID]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['labeldesk.installer.lock_path', 'labeldesk.installer.progress_path'] as $key) {
            $path = (string) config($key);
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * Activate a development-mock license for this installation.
     * Codes: ACTV (professional), STRT (starter).
     */
    protected function activateLicense(string $code = 'ACTV'): LicenseActivation
    {
        $context = ActivationContext::for((string) config('app.url'), self::INSTALLATION_ID);
        $key = "MD-LD-TEST-{$code}-AAAA-BBBB";

        $service = app(LicenseActivationService::class);
        $activation = $service->persist($context, $key, $service->activate($context, $key, 'mock'), 'mock');

        app(LicenseValidationService::class)->forget();

        return $activation;
    }

    /**
     * A fresh active user holding the given role (roles must be seeded).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(SystemRole|string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes)->fresh();
        $user->assignRole($role instanceof SystemRole ? $role->value : $role);

        return $user;
    }
}
