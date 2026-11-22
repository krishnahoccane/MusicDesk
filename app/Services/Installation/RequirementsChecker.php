<?php

namespace App\Services\Installation;

/**
 * Checks the hosting environment before installation.
 *
 * Each check returns: label, status (pass | warn | fail), current, required.
 * "fail" blocks installation; "warn" is shown but does not block.
 */
class RequirementsChecker
{
    /** @var list<string> */
    public const REQUIRED_EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'fileinfo', 'filter', 'gd', 'json', 'mbstring',
        'openssl', 'pdo', 'pdo_mysql', 'sodium', 'tokenizer', 'xml', 'zip',
    ];

    /** @var list<string> */
    public const RECOMMENDED_EXTENSIONS = ['intl'];

    /** @var list<string> */
    public const REQUIRED_FUNCTIONS = [
        'file_put_contents', 'json_encode', 'random_bytes', 'password_hash',
        'hash_hmac', 'sodium_crypto_sign_verify_detached', 'curl_init',
    ];

    /**
     * @return array<string, list<array{label: string, status: string, current: string, required: string}>>
     */
    public function check(): array
    {
        return [
            'PHP' => [$this->phpVersion()],
            'Extensions' => $this->extensions(),
            'Functions' => $this->functions(),
            'Permissions' => $this->permissions(),
            'Uploads & limits' => $this->limits(),
            'Database drivers' => [$this->databaseDrivers()],
        ];
    }

    public function passes(): bool
    {
        foreach ($this->check() as $checks) {
            foreach ($checks as $check) {
                if ($check['status'] === 'fail') {
                    return false;
                }
            }
        }

        return true;
    }

    protected function phpVersion(): array
    {
        $min = (string) config('labeldesk.installer.min_php', '8.2.0');
        $ok = version_compare(PHP_VERSION, $min, '>=');

        return [
            'label' => 'PHP version',
            'status' => $ok ? (version_compare(PHP_VERSION, '8.3.0', '>=') ? 'pass' : 'warn') : 'fail',
            'current' => PHP_VERSION,
            'required' => $min.'+ (8.3 recommended)',
        ];
    }

    protected function extensions(): array
    {
        $checks = [];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = ['label' => $ext, 'status' => $loaded ? 'pass' : 'fail', 'current' => $loaded ? 'Enabled' : 'Missing', 'required' => 'Required'];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = ['label' => $ext, 'status' => $loaded ? 'pass' : 'warn', 'current' => $loaded ? 'Enabled' : 'Missing', 'required' => 'Recommended'];
        }

        return $checks;
    }

    protected function functions(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return array_map(function (string $fn) use ($disabled) {
            $ok = function_exists($fn) && ! in_array($fn, $disabled, true);

            return ['label' => $fn.'()', 'status' => $ok ? 'pass' : 'fail', 'current' => $ok ? 'Available' : 'Disabled', 'required' => 'Required'];
        }, self::REQUIRED_FUNCTIONS);
    }

    protected function permissions(): array
    {
        $paths = [
            'storage/app' => storage_path('app'),
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $checks = [];
        foreach ($paths as $label => $path) {
            $ok = is_dir($path) && is_writable($path);
            $checks[] = ['label' => $label, 'status' => $ok ? 'pass' : 'fail', 'current' => $ok ? 'Writable' : 'Not writable', 'required' => 'Writable'];
        }

        $env = base_path('.env');
        $envOk = is_file($env) ? is_writable($env) : is_writable(base_path());
        $checks[] = ['label' => '.env file', 'status' => $envOk ? 'pass' : 'fail', 'current' => $envOk ? 'Writable' : 'Not writable', 'required' => 'Writable during installation'];

        return $checks;
    }

    protected function limits(): array
    {
        $upload = $this->bytes((string) ini_get('upload_max_filesize'));
        $post = $this->bytes((string) ini_get('post_max_size'));
        $memory = (string) ini_get('memory_limit');
        $memoryBytes = $memory === '-1' ? PHP_INT_MAX : $this->bytes($memory);
        $execution = (int) ini_get('max_execution_time');

        return [
            ['label' => 'file_uploads', 'status' => ini_get('file_uploads') ? 'pass' : 'fail', 'current' => ini_get('file_uploads') ? 'On' : 'Off', 'required' => 'On'],
            ['label' => 'upload_max_filesize', 'status' => $upload >= 64 * 1024 * 1024 ? 'pass' : 'warn', 'current' => (string) ini_get('upload_max_filesize'), 'required' => '64M+ recommended for WAV masters'],
            ['label' => 'post_max_size', 'status' => $post >= $upload ? 'pass' : 'warn', 'current' => (string) ini_get('post_max_size'), 'required' => '≥ upload_max_filesize'],
            ['label' => 'memory_limit', 'status' => $memoryBytes >= 128 * 1024 * 1024 ? 'pass' : 'fail', 'current' => $memory, 'required' => '128M+'],
            ['label' => 'max_execution_time', 'status' => $execution === 0 || $execution >= 60 ? 'pass' : 'warn', 'current' => $execution === 0 ? 'Unlimited' : $execution.'s', 'required' => '60s+ recommended'],
        ];
    }

    protected function databaseDrivers(): array
    {
        $drivers = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        $ok = in_array('mysql', $drivers, true);

        return ['label' => 'PDO MySQL / MariaDB', 'status' => $ok ? 'pass' : 'fail', 'current' => $drivers ? implode(', ', $drivers) : 'None', 'required' => 'mysql'];
    }

    public function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
