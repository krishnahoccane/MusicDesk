<?php

namespace App\Support;

/**
 * __() for the error pages: those must render even when the application could not
 * start completely (then the translator is not available and the English text is used).
 */
final class Text
{
    /** @param  array<string, scalar|null>  $replace */
    public static function t(string $key, array $replace = []): string
    {
        try {
            if (function_exists('app') && app()->bound('translator')) {
                return (string) __($key, $replace);
            }
        } catch (\Throwable) {
            // fall through to the plain text
        }

        foreach ($replace as $name => $value) {
            $key = str_replace(':'.$name, (string) $value, $key);
        }

        return $key;
    }
}
