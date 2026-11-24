<?php

namespace App\Services\Updates;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Puts the files of a verified LabelDesk package in place, the way WordPress
 * updates itself on shared hosting (PHP only, no SSH):
 *
 *  1. unpack everything into storage/app/private/updates/staging (slow part, site keeps running)
 *  2. maintenance page on (Laravel's storage/framework/down, shows "being updated")
 *  3. move each current file into a rollback folder and the new file into place
 *     (renames on the same disk, so this takes seconds); any error puts every file back
 *  4. files the previous version had and this one does not are moved to the rollback folder too
 *     (only when the installed version's signature.json is valid)
 *  5. bootstrap/cache lists (packages, services, config, routes, events) are removed so Laravel
 *     rebuilds them for the new files; maintenance page off, opcode cache reset
 *
 * The database upgrade (migrate --force, caches) then runs on the next page view
 * (AutoUpgrade middleware). Never touched: .env, storage/, plugins/, bootstrap/cache,
 * and an existing .htaccess (customers often add their own rules).
 */
class CoreUpdater
{
    /** Paths (relative, with /) that an update never writes. */
    public const PROTECTED_PREFIXES = ['storage/', 'plugins/', 'bootstrap/cache/', 'public/storage/', '.git/'];

    public const PROTECTED_FILES = ['.env', 'signature.json'];

    /** Customer-editable: only written when missing. */
    public const KEEP_IF_PRESENT = ['.htaccess', 'public/.htaccess', 'public/robots.txt', '.user.ini', 'public/.user.ini'];

    protected string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root ?? (config('labeldesk.updates.root') ?: base_path())), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<string> problems that stop an over-the-air update (empty when it can run) */
    public function problems(): array
    {
        $problems = [];
        if (! class_exists(\ZipArchive::class)) {
            $problems[] = __('The PHP zip extension is missing.');
        }
        foreach (['', '/app', '/bootstrap', '/config', '/database', '/public', '/resources', '/routes', '/vendor'] as $dir) {
            $path = $this->root.$dir;
            if (is_dir($path) && ! is_writable($path)) {
                $problems[] = __('The web server cannot write to :path.', ['path' => $dir === '' ? __('the LabelDesk folder') : ltrim($dir, '/')]);
            }
        }
        $free = @disk_free_space($this->root);
        if ($free !== false && $free < 300 * 1024 * 1024) {
            $problems[] = __('Less than 300 MB of free disk space.');
        }

        return $problems;
    }

    public function updatesDir(): string
    {
        return $this->root.'/storage/app/private/updates';
    }

    /**
     * @return array{written: int, kept: list<string>, rollback: string}
     */
    public function install(CorePackage $package): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        if (($problems = $this->problems()) !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $stamp = now()->format('YmdHis');
        $staging = $this->updatesDir().'/staging-'.$package->version.'-'.$stamp;
        $rollback = $this->updatesDir().'/rollback-'.$stamp;
        File::deleteDirectory($staging);
        $package->extractTo($staging);

        $plan = [];
        $kept = [];
        foreach ($package->files() as $relative) {
            if ($this->isProtected($relative)) {
                continue;
            }
            if (in_array($relative, self::KEEP_IF_PRESENT, true) && is_file($this->root.'/'.$relative)) {
                $kept[] = $relative;

                continue;
            }
            $plan[] = $relative;
        }
        $obsolete = $this->obsoleteFiles($package->files());

        $moved = []; // [relative, hadOriginal]
        $this->maintenance(true);
        $shutdown = true;
        register_shutdown_function(function () use (&$shutdown) {
            if ($shutdown) {
                $this->maintenance(false); // never leave the site down, even after a fatal error
            }
        });

        try {
            foreach ($plan as $relative) {
                $target = $this->root.'/'.$relative;
                $had = is_file($target);
                if ($had) {
                    $this->move($target, $rollback.'/'.$relative);
                }
                $moved[] = [$relative, $had];
                $this->move($staging.'/'.$relative, $target);
            }
            foreach ($obsolete as $relative) {
                $this->move($this->root.'/'.$relative, $rollback.'/'.$relative);
                $moved[] = [$relative, true];
            }
        } catch (Throwable $e) {
            $this->restore($moved, $rollback);
            $this->maintenance(false);
            $shutdown = false;
            File::deleteDirectory($staging);
            throw new RuntimeException(__('The update could not be installed, nothing was changed: :reason', ['reason' => $e->getMessage()]), 0, $e);
        }

        // Remember this version's file list (to remove files the next version drops).
        if (($signature = $package->signatureJson()) !== '') {
            @file_put_contents($this->root.'/signature.json', $signature);
        }
        $this->clearBootstrapCache();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->maintenance(false);
        $shutdown = false;
        File::deleteDirectory($staging);
        $this->pruneRollbacks(basename($rollback));

        return ['written' => count($plan), 'kept' => $kept, 'removed' => $obsolete, 'rollback' => $rollback];
    }

    /**
     * Files of the installed version that the new one does not have (e.g. code moved to an add-on).
     *
     * @param  list<string>  $newFiles
     * @return list<string>
     */
    public function obsoleteFiles(array $newFiles): array
    {
        $old = is_file($this->root.'/signature.json') ? CorePackage::signedFiles((string) file_get_contents($this->root.'/signature.json')) : null;
        if ($old === null) {
            return [];
        }
        $new = array_flip($newFiles);

        return array_values(array_filter($old, fn (string $relative) => ! isset($new[$relative]) && ! $this->isProtected($relative)
            && ! in_array($relative, self::KEEP_IF_PRESENT, true) && ! str_contains($relative, '..') && is_file($this->root.'/'.$relative)));
    }

    /** Laravel's cached lists name classes and files of the previous version: remove them so they are rebuilt. */
    public function clearBootstrapCache(): void
    {
        foreach (['packages.php', 'services.php', 'config.php', 'events.php'] as $file) {
            @unlink($this->root.'/bootstrap/cache/'.$file);
        }
        foreach (glob($this->root.'/bootstrap/cache/routes*.php') ?: [] as $routes) {
            @unlink($routes);
        }
    }

    public function isProtected(string $relative): bool
    {
        if (in_array($relative, self::PROTECTED_FILES, true)) {
            return true;
        }
        if (str_starts_with($relative, 'bootstrap/cache/') && $relative === 'bootstrap/cache/.gitignore') {
            return false;
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0: string, 1: bool}>  $moved */
    protected function restore(array $moved, string $rollback): void
    {
        foreach (array_reverse($moved) as [$relative, $had]) {
            $target = $this->root.'/'.$relative;
            rescue(function () use ($target, $had, $rollback, $relative) {
                if (is_file($target)) {
                    @unlink($target);
                }
                if ($had && is_file($rollback.'/'.$relative)) {
                    $this->move($rollback.'/'.$relative, $target);
                }
            }, report: true);
        }
    }

    protected function move(string $from, string $to): void
    {
        $dir = dirname($to);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException(__('Cannot create the folder :dir.', ['dir' => $this->relative($dir)]));
        }
        if (@rename($from, $to)) {
            return;
        }
        // Different disks or a locked file: copy, then remove the source.
        if (! @copy($from, $to)) {
            throw new RuntimeException(__('Cannot write :file.', ['file' => $this->relative($to)]));
        }
        @unlink($from);
    }

    /**
     * Laravel's own maintenance mode (public/index.php checks storage/framework/maintenance.php
     * before anything else is loaded), with the "being updated" page.
     */
    public function maintenance(bool $on): void
    {
        $framework = $this->root.'/storage/framework';
        if (! $on) {
            @unlink($framework.'/maintenance.php');
            @unlink($framework.'/down');

            return;
        }

        File::ensureDirectoryExists($framework);
        $template = rescue(fn () => view('errors.updating')->render(), null, report: false);
        file_put_contents($framework.'/down', json_encode([
            'except' => [], 'redirect' => null, 'retry' => 10, 'refresh' => 10, 'secret' => null, 'status' => 503, 'template' => $template,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $stub = base_path('vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/maintenance-mode.stub');
        file_put_contents($framework.'/maintenance.php', str_replace('{{ $path }}', $framework, (string) @file_get_contents($stub)));
    }

    /** Keep only the newest rollback folder. */
    protected function pruneRollbacks(string $keep): void
    {
        foreach (glob($this->updatesDir().'/rollback-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== $keep) {
                File::deleteDirectory($dir);
            }
        }
    }

    protected function relative(string $path): string
    {
        return ltrim(substr(str_replace('\\', '/', $path), strlen($this->root)), '/');
    }
}
