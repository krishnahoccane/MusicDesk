<?php

namespace App\Services\Media;

use RuntimeException;

/**
 * Reads the technical details of lossless master files from their headers —
 * no external tools (ffmpeg) required, so it works on shared hosting.
 *
 * Supports WAV (RIFF/WAVE: PCM, IEEE float, WAVE_FORMAT_EXTENSIBLE) and FLAC
 * (STREAMINFO block). Anything else is rejected.
 */
class AudioInspector
{
    public const MIN_SAMPLE_RATE = 44100;

    public const MIN_BIT_DEPTH = 16;

    /**
     * @return array{format: string, mime: string, sample_rate: int, bit_depth: int, channels: int, duration: int}
     */
    public function inspect(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(__('The audio file could not be read.'));
        }

        try {
            $magic = (string) fread($handle, 4);

            $info = match ($magic) {
                'RIFF' => $this->wav($handle),
                'fLaC' => $this->flac($handle),
                default => throw new RuntimeException(__('Upload a WAV or FLAC master file.')),
            };
        } finally {
            fclose($handle);
        }

        if ($info['channels'] < 1 || $info['channels'] > 2) {
            throw new RuntimeException(__('The master must be mono or stereo.'));
        }
        if ($info['sample_rate'] < self::MIN_SAMPLE_RATE) {
            throw new RuntimeException(__('The sample rate must be at least 44.1 kHz (this file is :rate Hz).', ['rate' => $info['sample_rate']]));
        }
        if ($info['bit_depth'] < self::MIN_BIT_DEPTH) {
            throw new RuntimeException(__('The bit depth must be at least 16-bit (this file is :bits-bit).', ['bits' => $info['bit_depth']]));
        }
        if ($info['duration'] < 1) {
            throw new RuntimeException(__('The audio file contains no audio.'));
        }

        return $info;
    }

    /**
     * @param  resource  $handle
     * @return array{format: string, mime: string, sample_rate: int, bit_depth: int, channels: int, duration: int}
     */
    protected function wav($handle): array
    {
        fread($handle, 4); // RIFF size
        if (fread($handle, 4) !== 'WAVE') {
            throw new RuntimeException(__('This is not a valid WAV file.'));
        }

        $fmt = null;
        $dataSize = null;

        // Walk the chunks until both "fmt " and "data" are found.
        for ($i = 0; $i < 64 && ! feof($handle); $i++) {
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                break;
            }

            $id = substr($header, 0, 4);
            $size = unpack('V', substr($header, 4, 4))[1];

            if ($id === 'fmt ') {
                $body = (string) fread($handle, $size);
                if (strlen($body) < 16) {
                    break;
                }
                $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr($body, 0, 16));
            } elseif ($id === 'data') {
                $dataSize = $size;
                break;
            } else {
                fseek($handle, $size + ($size % 2), SEEK_CUR); // chunks are word-aligned

                continue;
            }

            if ($size % 2) {
                fseek($handle, 1, SEEK_CUR);
            }
        }

        if (! $fmt || $dataSize === null) {
            throw new RuntimeException(__('This WAV file is incomplete or damaged.'));
        }
        if (! in_array($fmt['format'], [1, 3, 0xFFFE], true)) {
            throw new RuntimeException(__('Only uncompressed (PCM) WAV files are accepted.'));
        }
        if ($fmt['byterate'] < 1) {
            throw new RuntimeException(__('This WAV file is incomplete or damaged.'));
        }

        return [
            'format' => 'wav',
            'mime' => 'audio/wav',
            'sample_rate' => (int) $fmt['rate'],
            'bit_depth' => (int) $fmt['bits'],
            'channels' => (int) $fmt['channels'],
            'duration' => (int) round($dataSize / $fmt['byterate']),
        ];
    }

    /**
     * @param  resource  $handle
     * @return array{format: string, mime: string, sample_rate: int, bit_depth: int, channels: int, duration: int}
     */
    protected function flac($handle): array
    {
        $blockHeader = (string) fread($handle, 4);
        if (strlen($blockHeader) < 4 || (ord($blockHeader[0]) & 0x7F) !== 0) {
            throw new RuntimeException(__('This FLAC file is incomplete or damaged.'));
        }

        $info = (string) fread($handle, 34);
        if (strlen($info) < 18) {
            throw new RuntimeException(__('This FLAC file is incomplete or damaged.'));
        }

        // Bytes 10–17: sample rate (20 bits), channels-1 (3), bits-1 (5), total samples (36).
        $b = array_map('ord', str_split(substr($info, 10, 8)));
        $sampleRate = ($b[0] << 12) | ($b[1] << 4) | ($b[2] >> 4);
        $channels = (($b[2] >> 1) & 0x07) + 1;
        $bits = ((($b[2] & 0x01) << 4) | ($b[3] >> 4)) + 1;
        $totalSamples = (($b[3] & 0x0F) << 32) | ($b[4] << 24) | ($b[5] << 16) | ($b[6] << 8) | $b[7];

        if ($sampleRate < 1) {
            throw new RuntimeException(__('This FLAC file is incomplete or damaged.'));
        }

        return [
            'format' => 'flac',
            'mime' => 'audio/flac',
            'sample_rate' => $sampleRate,
            'bit_depth' => $bits,
            'channels' => $channels,
            'duration' => (int) round($totalSamples / $sampleRate),
        ];
    }
}
