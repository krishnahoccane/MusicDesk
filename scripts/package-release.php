<?php

/**
 * Builds the customer download: build/labeldesk-<version>.zip
 *
 *   php scripts/package-release.php            (runs npm run build + composer --no-dev)
 *   php scripts/package-release.php --skip-build   (use the current public/build)
 *
 * The ZIP contains everything a customer uploads to their server: the
 * application, production vendor/ (no dev packages), built assets and an
 * empty storage/ skeleton. It never contains .env, logs, uploads, backups,
 * tests, node_modules or development tooling.
 */
$root = dirname(__DIR__);
chdir($root);

$skipBuild = in_array('--skip-build', $argv, true);
// Read the version without booting Laravel (config files call env()).
preg_match("/'version'\s*=>\s*'([^']+)'/", (string) file_get_contents($root.'/config/labeldesk.php'), $m);
$version = preg_replace('/[^0-9A-Za-z.\-]/', '', $m[1] ?? 'dev');
$name = "labeldesk-{$version}";
$stage = $root."/build/{$name}";
$zipPath = $root."/build/{$name}.zip";

function run(string $command, string $cwd): void
{
    echo "> {$command}\n";
    passthru('cd '.escapeshellarg($cwd).' && '.$command, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Command failed ({$code}): {$command}\n");
        exit(1);
    }
}

function remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $item) {
        if ($item !== '.' && $item !== '..') {
            remove($path.'/'.$item);
        }
    }
    rmdir($path);
}

function copyTree(string $from, string $to, array $skip = []): void
{
    if (is_file($from)) {
        @mkdir(dirname($to), 0777, true);
        copy($from, $to);

        return;
    }
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $skip, true)) {
            continue;
        }
        copyTree($from.'/'.$item, $to.'/'.$item, $skip);
    }
}

if (! $skipBuild) {
    run('npm run build', $root);
}
if (! is_file($root.'/public/build/manifest.json')) {
    fwrite(STDERR, "public/build is missing. Run npm run build first.\n");
    exit(1);
}

remove($stage);
remove($zipPath);
@mkdir($stage, 0777, true);

// Application files.
foreach (['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'docs'] as $dir) {
    if (is_dir($root.'/'.$dir)) {
        copyTree($root.'/'.$dir, $stage.'/'.$dir, ['hot', 'storage']);
    }
}
foreach (['artisan', 'composer.json', 'composer.lock', '.env.example', 'README.md', 'CHANGELOG.md', 'LICENSE_API_SPEC.md'] as $file) {
    if (is_file($root.'/'.$file)) {
        copy($root.'/'.$file, $stage.'/'.$file);
    }
}

// Cached config/routes/services from the developer machine must not ship.
foreach (glob($stage.'/bootstrap/cache/*.php') ?: [] as $cached) {
    unlink($cached);
}

// Empty storage skeleton (writable folders the app expects).
foreach ([
    'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions',
    'storage/framework/views', 'storage/framework/testing', 'storage/logs',
] as $dir) {
    @mkdir($stage.'/'.$dir, 0777, true);
    file_put_contents($stage.'/'.$dir.'/.gitignore', "*\n!.gitignore\n");
}

// Production dependencies only.
run('composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts', $stage);
run('composer dump-autoload --optimize --no-dev --no-scripts', $stage);

// Zip it.
$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create {$zipPath}\n");
    exit(1);
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
$count = 0;
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($stage) + 1));
    $zip->addFile($file->getPathname(), $name.'/'.$relative);
    $count++;
}
$zip->close();

// Safety check: nothing secret or development-only in the package.
$zip->open($zipPath);
$forbidden = ['/.env', '/node_modules/', '/tests/', '/storage/logs/laravel', '/storage/app/private/backups/', '/.git/', '/vendor/phpunit/'];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->getNameIndex($i);
    foreach ($forbidden as $bad) {
        if (str_contains($entry, $bad) && ! str_ends_with($entry, '.env.example')) {
            fwrite(STDERR, "Refusing package: it contains {$entry}\n");
            $zip->close();
            unlink($zipPath);
            exit(1);
        }
    }
}
$zip->close();

remove($stage);
printf("Built %s (%d files, %.1f MB)\n", $zipPath, $count, filesize($zipPath) / 1048576);
