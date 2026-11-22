<?php

namespace App\Services\Installation;

use InvalidArgumentException;
use RuntimeException;

/**
 * Safely updates key/value pairs in the .env file.
 *
 * - Values are always double-quoted and escaped, so a password containing
 *   "#", spaces, quotes or "$" cannot break the file or trigger variable
 *   interpolation.
 * - Writes are atomic (temp file + rename) so a crash never leaves a
 *   half-written .env behind.
 */
class EnvironmentWriter
{
    public function __construct(protected ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? app()->environmentFilePath();
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function isWritable(): bool
    {
        $path = $this->path();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function set(array $values): void
    {
        $path = $this->path();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Invalid environment key [{$key}].");
            }

            $line = $key.'='.$this->format($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, fn () => $line, $contents, 1)
                : rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
        }

        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the environment file. Check that the application directory is writable.');
        }

        @chmod($tmp, 0640);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to replace the environment file. Check file permissions.');
        }
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents($this->path()), $m)) {
            $value = trim($m[1]);
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = stripcslashes(substr($value, 1, -1));
            }

            return $value;
        }

        return null;
    }

    public function format(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('Environment values may not contain line breaks.');
        }

        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+\-]+$/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }
}
