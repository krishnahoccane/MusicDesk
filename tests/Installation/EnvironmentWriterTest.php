<?php

namespace Tests\Installation;

use App\Services\Installation\EnvironmentWriter;
use Dotenv\Dotenv;
use InvalidArgumentException;
use Tests\TestCase;

class EnvironmentWriterTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/env-'.uniqid());
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/.env', "APP_NAME=Old\nDB_PASSWORD=\nKEEP=me\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_values_with_special_characters_round_trip_through_dotenv(): void
    {
        $writer = new EnvironmentWriter($this->dir.'/.env');
        $password = 'p@ss "w0rd" #$HOME \\ ${APP_KEY} end';

        $writer->set([
            'APP_NAME' => 'My Label Records',
            'DB_PASSWORD' => $password,
            'NEW_KEY' => 'value',
            'FLAG' => true,
        ]);

        $parsed = Dotenv::parse((string) file_get_contents($this->dir.'/.env'));

        $this->assertSame('My Label Records', $parsed['APP_NAME']);
        $this->assertSame($password, $parsed['DB_PASSWORD']);
        $this->assertSame('value', $parsed['NEW_KEY']);
        $this->assertSame('true', $parsed['FLAG']);
        $this->assertSame('me', $parsed['KEEP']);
        $this->assertSame($password, $writer->get('DB_PASSWORD'));
    }

    public function test_line_breaks_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EnvironmentWriter($this->dir.'/.env'))->set(['APP_NAME' => "evil\nAPP_DEBUG=true"]);
    }

    public function test_invalid_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EnvironmentWriter($this->dir.'/.env'))->set(['bad key' => 'x']);
    }
}
