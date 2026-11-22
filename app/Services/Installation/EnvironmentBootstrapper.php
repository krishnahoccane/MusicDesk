<?php

namespace App\Services\Installation;

/**
 * Runs before Laravel boots (see bootstrap/app.php).
 *
 * On a brand-new upload there is no .env file and no application key, so
 * sessions, CSRF tokens and encryption cannot work. This creates .env from
 * the shipped .env.example and generates a random APP_KEY, so the browser
 * installer can run without the customer editing any file.
 *
 * It never touches an existing .env.
 */
final class EnvironmentBootstrapper
{
    public static function ensure(string $basePath): void
    {
        $env = $basePath.DIRECTORY_SEPARATOR.'.env';
        $template = $basePath.DIRECTORY_SEPARATOR.'.env.example';

        if (is_file($env) || ! is_file($template)) {
            return;
        }

        if (! is_writable($basePath)) {
            self::fail('The application directory is not writable. Grant write permission to the LabelDesk folder (or create a .env file from .env.example) and reload this page.');
        }

        $contents = (string) file_get_contents($template);
        $key = 'base64:'.base64_encode(random_bytes(32));
        $contents = preg_match('/^APP_KEY=.*$/m', $contents)
            ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents, 1)
            : $contents.PHP_EOL.'APP_KEY='.$key.PHP_EOL;

        if (file_put_contents($env, $contents, LOCK_EX) === false) {
            self::fail('LabelDesk could not create its .env file. Check folder permissions and reload this page.');
        }

        @chmod($env, 0640);
    }

    private static function fail(string $message): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message.PHP_EOL);
            exit(1);
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>LabelDesk setup</title>'
            .'<div style="font-family:system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px">'
            .'<h1 style="font-size:20px;margin:0 0 12px">LabelDesk cannot start yet</h1><p style="color:#374151;line-height:1.5">'
            .htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p></div>';
        exit;
    }
}
