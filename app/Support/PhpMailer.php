<?php

namespace App\Support;

/**
 * Thin wrapper around PHP's mail() so it can be replaced in tests.
 */
class PhpMailer
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $to, string $subject, string $body, array $headers = []): bool
    {
        if (! function_exists('mail')) {
            return false;
        }

        $clean = fn (string $v) => str_replace(["\r", "\n"], ' ', $v);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $clean($name).': '.$clean($value);
        }

        return (bool) @mail($clean($to), $clean($subject), $body, implode("\r\n", $lines));
    }
}
