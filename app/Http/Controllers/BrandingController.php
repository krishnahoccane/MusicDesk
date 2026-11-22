<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves uploaded logos, favicon and sign-in images (public: they appear on
 * the sign-in page). Names are random and strictly validated.
 */
class BrandingController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(preg_match(Branding::NAME_PATTERN, $file), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists('branding/'.$file), 404);

        $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'image/jpeg',
        };

        return response()->file($disk->path('branding/'.$file), [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened directly must not run anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
