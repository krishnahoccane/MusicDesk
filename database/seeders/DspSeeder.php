<?php

namespace Database\Seeders;

use App\Models\Dsp;
use Illuminate\Database\Seeder;

/**
 * Essential reference data: major stores and streaming services. Idempotent;
 * existing rows (including ones an administrator edited or deactivated) are
 * never changed.
 */
class DspSeeder extends Seeder
{
    /** name => [website, lead time in days] */
    public const DSPS = [
        'Spotify' => ['https://www.spotify.com', 14],
        'Apple Music' => ['https://www.apple.com/apple-music/', 14],
        'iTunes Store' => ['https://www.apple.com/itunes/', 14],
        'YouTube Music' => ['https://music.youtube.com', 14],
        'Amazon Music' => ['https://music.amazon.com', 14],
        'Deezer' => ['https://www.deezer.com', 10],
        'TIDAL' => ['https://tidal.com', 10],
        'SoundCloud' => ['https://soundcloud.com', 7],
        'Pandora' => ['https://www.pandora.com', 14],
        'iHeartRadio' => ['https://www.iheart.com', 10],
        'Qobuz' => ['https://www.qobuz.com', 10],
        'Napster' => ['https://www.napster.com', 10],
        'Audiomack' => ['https://audiomack.com', 7],
        'Anghami' => ['https://www.anghami.com', 10],
        'Boomplay' => ['https://www.boomplay.com', 10],
        'JioSaavn' => ['https://www.jiosaavn.com', 10],
        'Gaana' => ['https://gaana.com', 10],
        'Wynk Music' => ['https://wynk.in', 10],
        'TikTok' => ['https://www.tiktok.com', 7],
        'Instagram & Facebook (Meta)' => ['https://www.facebook.com/sound/collection', 7],
        'Shazam' => ['https://www.shazam.com', 7],
        'Beatport' => ['https://www.beatport.com', 14],
        'KKBOX' => ['https://www.kkbox.com', 10],
        'NetEase Cloud Music' => ['https://music.163.com', 14],
        'Tencent Music (QQ Music, Kugou, Kuwo)' => ['https://y.qq.com', 14],
        'LINE Music' => ['https://music.line.me', 14],
        'Yandex Music' => ['https://music.yandex.com', 10],
    ];

    /**
     * Seeds the starter store list once; afterwards the label manages it.
     */
    public function run(): void
    {
        if (Dsp::query()->exists()) {
            return;
        }

        foreach (self::DSPS as $name => [$website, $lead]) {
            Dsp::query()->firstOrCreate(['name' => $name], ['website' => $website, 'lead_time_days' => $lead]);
        }
    }
}
