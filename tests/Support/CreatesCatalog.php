<?php

namespace Tests\Support;

use App\Enums\ContributorRole;
use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;

/**
 * Builders for catalog tests: real WAV/FLAC bytes and complete releases.
 */
trait CreatesCatalog
{
    /** @var list<string> */
    protected array $tempUploads = [];

    protected function wavBytes(int $seconds = 1, int $rate = 44100, int $bits = 16, int $channels = 2): string
    {
        $byteRate = $rate * $channels * intdiv($bits, 8);
        $dataSize = $byteRate * $seconds;
        $fmt = pack('vvVVvv', 1, $channels, $rate, $byteRate, $channels * intdiv($bits, 8), $bits);

        return 'RIFF'.pack('V', 4 + 8 + 16 + 8 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).$fmt
            .'LIST'.pack('V', 4).'INFO'           // an extra chunk the parser must skip
            .'data'.pack('V', $dataSize).str_repeat("\0", $dataSize);
    }

    protected function flacBytes(int $seconds = 200, int $rate = 48000, int $bits = 24, int $channels = 2): string
    {
        $samples = $rate * $seconds;
        $info = pack('n', 4096).pack('n', 4096).str_repeat("\0", 6);
        $info .= chr(($rate >> 12) & 0xFF)
            .chr(($rate >> 4) & 0xFF)
            .chr((($rate & 0x0F) << 4) | ((($channels - 1) & 0x07) << 1) | ((($bits - 1) >> 4) & 0x01))
            .chr(((($bits - 1) & 0x0F) << 4) | (($samples >> 32) & 0x0F))
            .pack('N', $samples & 0xFFFFFFFF);
        $info .= str_repeat("\0", 16); // MD5

        return 'fLaC'.chr(0x80).chr(0).chr(0).chr(34).$info.str_repeat("\0", 64);
    }

    protected function upload(string $name, string $bytes): File
    {
        $path = storage_path('framework/testing/upload-'.uniqid('', true));
        file_put_contents($path, $bytes);
        $this->tempUploads[] = $path;

        return new File($name, fopen($path, 'r'));
    }

    protected function cleanUploads(): void
    {
        foreach ($this->tempUploads as $path) {
            @unlink($path);
        }
        $this->tempUploads = [];
    }

    /**
     * A release that passes every checklist item (files on the fake disk).
     */
    protected function completeRelease(?Artist $artist = null, int $tracks = 2): Release
    {
        $artist ??= Artist::factory()->create();
        $writer = Contributor::query()->create(['name' => 'Wren Writer']);

        $release = Release::factory()->create([
            'genre_id' => Genre::query()->firstOrCreate(['name' => 'Pop'])->id,
            'language_id' => Language::query()->firstOrCreate(['code' => 'en'], ['name' => 'English'])->id,
        ]);
        $release->forceFill(['artwork_path' => 'releases/'.$release->id.'/art.jpg', 'artwork_thumb_path' => 'releases/'.$release->id.'/art-thumb.jpg'])->save();
        Storage::disk('local')->put($release->artwork_path, 'jpg');
        $release->artists()->sync([$artist->id => ['role' => 'primary', 'position' => 1]]);

        for ($i = 1; $i <= $tracks; $i++) {
            $track = $release->tracks()->make(['title' => "Track $i", 'position' => $i, 'disc_number' => 1]);
            $track->forceFill([
                'audio_path' => 'releases/'.$release->id.'/audio/t'.$i.'.wav',
                'audio_mime' => 'audio/wav', 'audio_size' => 10, 'audio_sample_rate' => 44100,
                'audio_bit_depth' => 16, 'audio_channels' => 2, 'duration_seconds' => 180,
            ])->save();
            Storage::disk('local')->put($track->audio_path, $this->wavBytes());
            $track->artists()->sync([$artist->id => ['role' => 'primary', 'position' => 1]]);
            $track->credits()->create(['contributor_id' => $writer->id, 'role' => ContributorRole::Songwriter->value, 'share' => '100']);
        }

        return $release->fresh();
    }

    protected function trackOf(Release $release, int $position = 1): Track
    {
        return $release->tracks()->where('position', $position)->firstOrFail();
    }
}
