<?php

namespace App\Services\Installation;

use Illuminate\Support\Str;

/**
 * Tracks whether LabelDesk is installed and the progress of an in-flight
 * installation.
 *
 * - The lock file (outside public/) is the single source of truth for
 *   "installed". It is written last, after everything else succeeded.
 * - The progress file records non-secret wizard state so an interrupted
 *   installation can be resumed safely. It never contains passwords.
 */
class InstallationState
{
    public function lockPath(): string
    {
        return (string) config('labeldesk.installer.lock_path');
    }

    public function progressPath(): string
    {
        return (string) config('labeldesk.installer.progress_path');
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lock(): ?array
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($this->lockPath()), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeLock(array $data): void
    {
        $this->writeJson($this->lockPath(), $data + [
            'installed_at' => now()->toIso8601String(),
            'version' => config('labeldesk.product.version'),
        ]);
    }

    public function installationId(): ?string
    {
        return $this->lock()['installation_id'] ?? $this->progress()['installation_id'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(): array
    {
        $path = $this->progressPath();
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Merge non-secret values into the progress file.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateProgress(array $values): array
    {
        $progress = array_replace($this->progress(), $values, ['updated_at' => now()->toIso8601String()]);

        if (empty($progress['installation_id'])) {
            $progress['installation_id'] = (string) Str::uuid();
        }

        $this->writeJson($this->progressPath(), $progress);

        return $progress;
    }

    /**
     * Ensure a stable installation id exists for this wizard run.
     */
    public function ensureInstallationId(): string
    {
        return (string) ($this->progress()['installation_id'] ?? $this->updateProgress([])['installation_id']);
    }

    public function clearProgress(): void
    {
        if (is_file($this->progressPath())) {
            @unlink($this->progressPath());
        }
    }

    /**
     * True when a previous run reached the "install" stage against this
     * database but never finished. Such a database may be resumed (migrations
     * only ever move forward), never wiped.
     */
    public function isResumableFor(string $host, string $database, string $prefix): bool
    {
        $progress = $this->progress();

        return ($progress['status'] ?? null) === 'installing'
            && ($progress['database_target'] ?? null) === $this->databaseTarget($host, $database, $prefix);
    }

    public function databaseTarget(string $host, string $database, string $prefix): string
    {
        return hash('sha256', strtolower($host).'|'.$database.'|'.$prefix);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $path.'.'.Str::random(8).'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($tmp, 0640);
        rename($tmp, $path);
    }
}
