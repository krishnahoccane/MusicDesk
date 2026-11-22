<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Logo, dark-mode logo, favicon and sign-in background image.
 *
 * Files live on the public disk under branding/ and are served by the
 * BrandingController route (no storage symlink needed on shared hosting).
 * Settings keep only the file name.
 */
final class Branding
{
    public const KINDS = [
        'logo' => ['png', 'jpg', 'jpeg', 'webp', 'svg'],
        'logo_dark' => ['png', 'jpg', 'jpeg', 'webp', 'svg'],
        'favicon' => ['png', 'ico', 'svg'],
        'login_image' => ['jpg', 'jpeg', 'png', 'webp'],
    ];

    public const MAX_KB = ['logo' => 1024, 'logo_dark' => 1024, 'favicon' => 256, 'login_image' => 6144];

    public const NAME_PATTERN = '/^(logo|logo_dark|favicon|login_image)-[a-z0-9]{16}\.(png|jpe?g|webp|svg|ico)$/';

    public static function store(string $kind, UploadedFile $file): string
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw new RuntimeException('Unknown branding file.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::KINDS[$kind], true)) {
            throw new RuntimeException(__('This file type is not allowed here.'));
        }

        if ($extension === 'svg' && ! self::safeSvg((string) file_get_contents($file->getRealPath()))) {
            throw new RuntimeException(__('This SVG contains scripts or external references and was refused.'));
        }

        $name = $kind.'-'.Str::lower(Str::random(16)).'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        Storage::disk('public')->putFileAs('branding', $file, $name);

        return $name;
    }

    public static function delete(?string $name): void
    {
        if ($name && preg_match(self::NAME_PATTERN, $name)) {
            Storage::disk('public')->delete('branding/'.$name);
        }
    }

    public static function url(string $kind): ?string
    {
        $name = (string) config('labeldesk.appearance.'.$kind, '');
        if ($name === '' || ! preg_match(self::NAME_PATTERN, $name) || ! Storage::disk('public')->exists('branding/'.$name)) {
            return null;
        }

        return route('branding.file', $name);
    }

    /**
     * SVGs are images that can carry scripts: refuse scripts, event handlers,
     * foreign objects and external references.
     */
    public static function safeSvg(string $svg): bool
    {
        return ! preg_match('/<\s*script|on[a-z]+\s*=|<\s*foreignObject|javascript:|xlink:href\s*=\s*["\'](?!#)|href\s*=\s*["\'](?!#)|<!ENTITY/i', $svg);
    }
}
