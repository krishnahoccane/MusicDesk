<?php

namespace Tests\Unit;

use App\Services\Media\AudioInspector;
use App\Support\Identifiers;
use RuntimeException;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

class CatalogUnitTest extends TestCase
{
    use CreatesCatalog;

    protected function tearDown(): void
    {
        $this->cleanUploads();
        parent::tearDown();
    }

    public function test_isrc_normalisation_and_validation(): void
    {
        $this->assertSame('GBAYE2600001', Identifiers::normalizeIsrc(' gb-aye-26-00001 '));
        $this->assertTrue(Identifiers::isValidIsrc('GBAYE2600001'));
        $this->assertTrue(Identifiers::isValidIsrc('USRC17607839'));
        $this->assertFalse(Identifiers::isValidIsrc('GBAYE260001'));   // too short
        $this->assertFalse(Identifiers::isValidIsrc('1BAYE2600001'));  // country must be letters
        $this->assertFalse(Identifiers::isValidIsrc('GBAYEXX00001'));  // year must be digits
        $this->assertNull(Identifiers::normalizeIsrc('  '));
    }

    public function test_upc_checksum(): void
    {
        $this->assertTrue(Identifiers::isValidUpc('036000291452'));   // UPC-A
        $this->assertTrue(Identifiers::isValidUpc('4006381333931'));  // EAN-13
        $this->assertFalse(Identifiers::isValidUpc('036000291453'));
        $this->assertFalse(Identifiers::isValidUpc('12345'));
        $this->assertSame('036000291452', Identifiers::normalizeUpc('0 36000 29145 2'));
    }

    protected function inspect(string $bytes): array
    {
        $path = storage_path('framework/testing/upload-'.uniqid('', true));
        file_put_contents($path, $bytes);
        $this->tempUploads[] = $path;

        return app(AudioInspector::class)->inspect($path);
    }

    public function test_wav_and_flac_headers_are_read(): void
    {
        $wav = $this->inspect($this->wavBytes(3, 48000, 24, 2));
        $this->assertSame(['format' => 'wav', 'mime' => 'audio/wav', 'sample_rate' => 48000, 'bit_depth' => 24, 'channels' => 2, 'duration' => 3], $wav);

        $flac = $this->inspect($this->flacBytes(245, 96000, 24, 2));
        $this->assertSame(['format' => 'flac', 'mime' => 'audio/flac', 'sample_rate' => 96000, 'bit_depth' => 24, 'channels' => 2, 'duration' => 245], $flac);
    }

    public function test_low_quality_and_non_audio_files_are_rejected(): void
    {
        foreach ([
            $this->wavBytes(1, 22050, 16, 2),          // sample rate too low
            $this->wavBytes(1, 44100, 8, 2),           // bit depth too low
            $this->wavBytes(1, 44100, 16, 6),          // 5.1 not accepted
            'ID3'.str_repeat("\0", 200),                // MP3
            '%PDF-1.4 not audio',
        ] as $bytes) {
            try {
                $this->inspect($bytes);
                $this->fail('Expected the file to be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
