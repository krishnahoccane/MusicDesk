<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Re-encodes uploaded images with GD: resizes to a maximum edge and writes a
 * fresh JPEG. Re-encoding strips EXIF/GPS metadata and anything appended to
 * the image, so only clean pixels are stored.
 */
class ImageProcessor
{
    /** Refuse to decode anything larger than this (decompression-bomb guard). */
    public const MAX_PIXELS = 40_000_000;

    /**
     * @return array{0: int, 1: int} width, height
     */
    public function dimensions(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info) {
            throw new RuntimeException(__('The file is not a supported image.'));
        }

        return [(int) $info[0], (int) $info[1]];
    }

    public function toJpeg(UploadedFile $file, int $maxEdge = 1000, int $quality = 85): string
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new RuntimeException(__('The file is not a supported image.'));
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new RuntimeException(__('The image dimensions are too large.'));
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw new RuntimeException(__('The image could not be read.'));
        }

        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        // Flatten transparency onto white (JPEG has no alpha channel).
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $binary = (string) ob_get_clean();
        unset($source, $canvas); // GD images are freed with the objects (PHP 8+)

        return $binary;
    }
}
