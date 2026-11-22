<?php

namespace App\Services\Backup;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;
use ZipArchive;

/**
 * Backups: one ZIP per backup in storage/app/private/backups containing
 *   database.sql   pure-PHP SQL dump (see DatabaseDumper)
 *   manifest.json  product version, date, row counts, file count
 *   files/private  uploaded masters, documents, statements (storage/app/private)
 *   files/public   artwork and other public uploads (storage/app/public)
 *
 * The .env file (APP_KEY, database and mail passwords) is never included.
 * Restoring is a documented manual process (docs/backups.md) so a click can
 * never overwrite live data.
 */
class BackupService
{
    public const NAME_PATTERN = '/^labeldesk-backup-\d{4}-\d{2}-\d{2}-\d{6}(-db)?\.zip$/';

    public const DEFAULT_RETENTION = 7;

    /** Folders inside storage/app/private that are not backed up. */
    protected const EXCLUDE = ['backups', 'livewire-tmp', 'tmp', 'imports-tmp'];

    public function __construct(
        protected DatabaseDumper $dumper,
        protected SettingsService $settings,
        protected ActivityLogger $activity,
    ) {}

    public function directory(): string
    {
        return (string) config('labeldesk.backups.path', storage_path('app/private/backups'));
    }

    /**
     * @return array{name: string, size: int, tables: int, rows: int, files: int}
     */
    public function create(bool $includeFiles = true, ?User $user = null, string $trigger = 'manual'): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('The PHP zip extension is required to create backups.'));
        }

        @set_time_limit(0);
        File::ensureDirectoryExists($this->directory(), 0750);
        $this->protectDirectory();

        $name = 'labeldesk-backup-'.now()->format('Y-m-d-His').($includeFiles ? '' : '-db').'.zip';
        $zipPath = $this->directory().DIRECTORY_SEPARATOR.$name;
        $sqlPath = $this->directory().DIRECTORY_SEPARATOR.'.dump-'.Str::random(12).'.sql';

        try {
            $counts = $this->dumper->dump($sqlPath);

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(__('Could not create the backup file. Check that storage/app/private is writable.'));
            }

            $zip->addFile($sqlPath, 'database.sql');

            $fileCount = 0;
            if ($includeFiles) {
                $fileCount += $this->addFolder($zip, (string) config('labeldesk.backups.private'), 'files/private', [...self::EXCLUDE, ...$this->excludedInside((string) config('labeldesk.backups.private'))]);
                $fileCount += $this->addFolder($zip, (string) config('labeldesk.backups.public'), 'files/public');
            }

            $zip->addFromString('manifest.json', json_encode([
                'format' => 'labeldesk-backup-1',
                'product' => config('labeldesk.product.name'),
                'version' => config('labeldesk.product.version'),
                'created_at' => now()->toIso8601String(),
                'trigger' => $trigger,
                'database_driver' => config('database.connections.'.config('database.default').'.driver'),
                'includes_files' => $includeFiles,
                'file_count' => $fileCount,
                'tables' => $counts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException(__('Could not finish writing the backup file.'));
            }
        } catch (Throwable $e) {
            @unlink($zipPath);
            throw $e instanceof RuntimeException ? $e : new RuntimeException(__('The backup failed: :reason', ['reason' => $e->getMessage()]), 0, $e);
        } finally {
            @unlink($sqlPath);
        }

        $this->activity->log('backup.created', __('Created backup :name', ['name' => $name]), null, ['trigger' => $trigger, 'files' => $includeFiles], $user);
        $this->prune();

        return [
            'name' => $name,
            'size' => (int) filesize($zipPath),
            'tables' => count($counts),
            'rows' => array_sum($counts),
            'files' => $fileCount,
        ];
    }

    /**
     * @return list<array{name: string, size: int, created_at: CarbonImmutable, database_only: bool}>
     */
    public function list(): array
    {
        if (! is_dir($this->directory())) {
            return [];
        }

        $backups = [];
        foreach (scandir($this->directory()) ?: [] as $file) {
            if (preg_match(self::NAME_PATTERN, $file)) {
                $path = $this->directory().DIRECTORY_SEPARATOR.$file;
                $backups[] = [
                    'name' => $file,
                    'size' => (int) filesize($path),
                    'created_at' => CarbonImmutable::createFromTimestamp((int) filemtime($path)),
                    'database_only' => str_ends_with($file, '-db.zip'),
                ];
            }
        }

        usort($backups, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Absolute path of a backup, or null when the name is not a backup.
     */
    public function path(string $name): ?string
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    public function delete(string $name, ?User $user = null): bool
    {
        $path = $this->path($name);
        if ($path === null || ! @unlink($path)) {
            return false;
        }

        $this->activity->log('backup.deleted', __('Deleted backup :name', ['name' => $name]), null, [], $user);

        return true;
    }

    public function retention(): int
    {
        return max(1, min(90, (int) $this->settings->get('backups.retention', self::DEFAULT_RETENTION)));
    }

    public function scheduled(): bool
    {
        return (bool) $this->settings->get('backups.schedule', false);
    }

    /**
     * Keep only the newest backups, as set in the retention setting.
     */
    public function prune(): int
    {
        $removed = 0;
        foreach (array_slice($this->list(), $this->retention()) as $old) {
            if (@unlink($this->directory().DIRECTORY_SEPARATOR.$old['name'])) {
                $removed++;
            }
        }

        return $removed;
    }

    protected function addFolder(ZipArchive $zip, string $root, string $prefix, array $exclude = []): int
    {
        if (! is_dir($root)) {
            return 0;
        }

        $finder = Finder::create()->files()->in($root)->ignoreDotFiles(false)->ignoreVCS(true)
            ->exclude($exclude)->notName(['.gitignore', '.htaccess']);

        $count = 0;
        foreach ($finder as $file) {
            $zip->addFile($file->getRealPath(), $prefix.'/'.str_replace('\\', '/', $file->getRelativePathname()));
            $count++;
        }

        return $count;
    }

    /**
     * The backups folder itself, when it lives inside the folder being backed up.
     *
     * @return list<string>
     */
    protected function excludedInside(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $dir = str_replace('\\', '/', $this->directory());

        return str_starts_with($dir, $root) ? [substr($dir, strlen($root))] : [];
    }

    /**
     * Extra safety if storage/ is ever exposed by a misconfigured host.
     */
    protected function protectDirectory(): void
    {
        $htaccess = $this->directory().DIRECTORY_SEPARATOR.'.htaccess';
        if (! is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
    }
}
