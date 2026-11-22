<?php

namespace Tests\Installation;

use App\Services\Installation\EnvironmentBootstrapper;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class EnvironmentBootstrapperTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld-env-'.uniqid();
        mkdir($this->dir.'/storage/app/private', 0777, true);
        file_put_contents($this->dir.'/.env.example', "APP_NAME=LabelDesk\nAPP_KEY=\nAPP_DEBUG=false\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_creates_env_with_a_key_on_first_upload(): void
    {
        EnvironmentBootstrapper::ensure($this->dir);

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S{40,}$/m', (string) file_get_contents($this->dir.'/.env'));
    }

    public function test_adds_a_key_to_a_hand_copied_env_before_installation(): void
    {
        copy($this->dir.'/.env.example', $this->dir.'/.env');

        EnvironmentBootstrapper::ensure($this->dir);

        $env = (string) file_get_contents($this->dir.'/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S{40,}$/m', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env, 'other lines are kept');
    }

    public function test_never_replaces_an_existing_key(): void
    {
        file_put_contents($this->dir.'/.env', "APP_KEY=base64:existing\nAPP_DEBUG=false\n");

        EnvironmentBootstrapper::ensure($this->dir);

        $this->assertSame("APP_KEY=base64:existing\nAPP_DEBUG=false\n", file_get_contents($this->dir.'/.env'));
    }

    public function test_an_installed_site_is_never_given_a_new_key(): void
    {
        file_put_contents($this->dir.'/.env', "APP_KEY=\nAPP_DEBUG=false\n");
        file_put_contents($this->dir.'/storage/app/private/installed.lock', '{}');

        EnvironmentBootstrapper::ensure($this->dir);

        $this->assertSame("APP_KEY=\nAPP_DEBUG=false\n", file_get_contents($this->dir.'/.env'));
    }
}
