<?php

namespace App\Support;

/**
 * Laravel keeps lists in bootstrap/cache that it never rebuilds on its own:
 * packages.php / services.php (service providers of the installed packages),
 * and optionally config.php / routes-v7.php / events.php.
 *
 * After new files are put in place — by an over-the-air update or by FTP — those
 * lists can name classes that no longer exist (for example a development package
 * that release packages leave out), and every request fails before LabelDesk can
 * even show an error page. This runs first in bootstrap/app.php, before Laravel
 * reads the lists, and removes the ones that are out of date; Laravel then rebuilds them.
 *
 * Plain PHP only: it must work while the rest of the application cannot start.
 */
final class StaleCacheGuard
{
    /** @return list<string> the cache files that were removed */
    public static function check(string $basePath): array
    {
        $cache = $basePath.'/bootstrap/cache';
        if (! is_dir($cache)) {
            return [];
        }

        $removed = [];
        $remove = function (string ...$files) use ($cache, &$removed): void {
            foreach ($files as $file) {
                if (is_file($cache.'/'.$file) && @unlink($cache.'/'.$file)) {
                    $removed[] = $file;
                }
            }
        };

        // Package lists: older than vendor/, or naming a provider that cannot be loaded.
        $packages = $cache.'/packages.php';
        if (is_file($packages)) {
            $vendor = self::newest($basePath.'/vendor/composer', ['installed.php', 'installed.json', 'autoload_static.php']);
            if ($vendor > @filemtime($packages) || self::missingProvider($packages)) {
                $remove('packages.php', 'services.php');
            }
        }

        // Cached config / routes / events: older than the files they were built from.
        if (is_file($cache.'/config.php') && self::newest($basePath.'/config', null) > @filemtime($cache.'/config.php')) {
            $remove('config.php');
        }
        foreach (glob($cache.'/routes*.php') ?: [] as $routes) {
            if (self::newest($basePath.'/routes', null) > @filemtime($routes)) {
                $remove(basename($routes));
            }
        }
        if (is_file($cache.'/events.php') && self::newest($basePath.'/app', ['Providers/AppServiceProvider.php', 'Providers/EventServiceProvider.php']) > @filemtime($cache.'/events.php')) {
            $remove('events.php');
        }

        if ($removed !== [] && function_exists('opcache_invalidate')) {
            foreach ($removed as $file) {
                @opcache_invalidate($cache.'/'.$file, true);
            }
        }

        return $removed;
    }

    /** @param  list<string>|null  $files  null = every .php file directly in $dir */
    private static function newest(string $dir, ?array $files): int
    {
        $paths = $files === null ? (glob($dir.'/*.php') ?: []) : array_map(fn ($f) => $dir.'/'.$f, $files);
        $newest = 0;
        foreach ($paths as $path) {
            $newest = max($newest, (int) @filemtime($path));
        }

        return $newest;
    }

    private static function missingProvider(string $packagesFile): bool
    {
        try {
            $packages = require $packagesFile;
        } catch (\Throwable) {
            return true;
        }
        if (! is_array($packages)) {
            return true;
        }
        foreach ($packages as $package) {
            foreach ((array) ($package['providers'] ?? []) as $provider) {
                if (is_string($provider) && ! class_exists($provider)) {
                    return true;
                }
            }
        }

        return false;
    }
}
