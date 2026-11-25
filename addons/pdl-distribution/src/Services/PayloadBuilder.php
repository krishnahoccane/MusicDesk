<?php

namespace LabelDesk\Addons\PdlDistribution\Services;

use App\Enums\ArtistRole;
use App\Enums\ContributorRole;
use App\Enums\LinkPlatform;
use App\Enums\ReleaseStatus;
use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Release;
use App\Models\Track;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * LabelDesk release → COSMOS /album/add/meta request (version 2).
 *
 *   release title / version     name / album_category (version, else the default category)
 *   release type                album_subtype + data.content_type (Settings: content types)
 *   album type (Settings)       album_type + data.album_type (Album or Film)
 *   label                       Settings "PDL label name", else the release's label
 *   UPC / catalogue number      upc_id / catalog_number (PDL creates a UPC when empty)
 *   C-line / P-line             c_line / p_line (album and per song)
 *   release date                data.go_live_date; original release date → data.original_release_date_of_music and _of_movie (COSMOS requires both)
 *   genre                       Settings genre map → data.genre + data.sub_genre
 *   language (track, release)   Settings language map → data.language; zxx/instrumental → is_instrumental Yes
 *   explicit (track or release) data.parental_advisory "Explicit" / "Not Explicit"
 *   mood                        chosen when sending (default in Settings)
 *   CRBT                        data.crbt_cut_name (title, 64 chars), data.time_for_crbt_cut (Settings)
 *   release artists by role     album_main_artist / album_featured_artist / album_remixer_artist
 *   track artists by role       track_main_artist / track_featured_artist / track_remixer_artist (release artists when a track has none)
 *   singers                     main and featured track artists (none for instrumentals)
 *   credits                     composers (composer, songwriter), lyricists (lyricist, songwriter), producers; publisher credit → publisher
 *   artist links                Spotify / Apple Music ids from their profile URLs, Facebook / Instagram page URLs
 *   contributor IPI / PRO       ipi_number, is_iprs_member (PRO "IPRS")
 *   artwork / masters           inlay / media {filename, size, md5}
 */
class PayloadBuilder
{
    public const DISK = 'local';

    public function __construct(protected PdlSettings $settings) {}

    /**
     * Problems that stop a delivery (empty when it can be sent) and warnings.
     *
     * @return array{problems: list<string>, warnings: list<string>}
     */
    public function check(Release $release, ?string $mood): array
    {
        $release = $this->load($release);
        $problems = [];
        $warnings = [];

        if (! in_array($release->status, [ReleaseStatus::Approved, ReleaseStatus::Ready], true)) {
            $problems[] = __('The release must be approved or ready for delivery (it is ":status").', ['status' => $release->status->label()]);
        }
        if ($release->trashed()) {
            $problems[] = __('The release has been deleted.');
        }
        if (! $release->release_date) {
            $problems[] = __('Set a release date (go-live date at PDL).');
        } elseif ($release->release_date->startOfDay()->greaterThan(now()->startOfDay()->addDays(Cosmos::MAX_GO_LIVE_DAYS_AHEAD))) {
            $problems[] = __('PDL accepts a release at most :days days before its go-live date (:date). Send it on or after :from.', [
                'days' => Cosmos::MAX_GO_LIVE_DAYS_AHEAD,
                'date' => $release->release_date->toDateString(),
                'from' => $release->release_date->copy()->subDays(Cosmos::MAX_GO_LIVE_DAYS_AHEAD)->toDateString(),
            ]);
        }
        if ($this->labelName($release) === '') {
            $problems[] = __('Set the PDL label name in Settings → PDL distribution, or a label on the release.');
        }
        if ($release->upc && ! preg_match('/^\d{12,13}$/', $release->upc)) {
            $problems[] = __('PDL accepts 12 or 13 digit UPC/EAN codes; this release has :upc.', ['upc' => $release->upc]);
        } elseif (! $release->upc) {
            $warnings[] = __('No UPC: PDL will create one.');
        }
        if ($this->settings->get('album_type') === 'Film') {
            $problems[] = __('Album type "Film" needs a film banner, star cast and director, which LabelDesk does not record. Use album type "Album" in Settings → PDL distribution.');
        }
        if ($this->settings->genreFor($release->genre_id) === null) {
            $problems[] = $release->genre
                ? __('Map the genre ":genre" to a PDL genre and sub-genre in Settings → PDL distribution.', ['genre' => $release->genre->name])
                : __('Set a genre on the release.');
        }
        if (! in_array($mood, Cosmos::MOODS, true)) {
            $problems[] = __('Choose a mood (PDL requires one).');
        }
        if ($release->artists->where('pivot.role', ArtistRole::Primary->value)->isEmpty()) {
            $problems[] = __('Add a primary artist to the release.');
        }

        [, $artworkProblem] = $this->artworkFile($release, false);
        if ($artworkProblem) {
            $problems[] = $artworkProblem;
        }

        if ($release->tracks->isEmpty()) {
            $problems[] = __('The release has no tracks.');
        }
        foreach ($release->tracks as $track) {
            $name = $track->displayTitle();
            $isrc = $this->isrc($track);
            if (! preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc)) {
                $problems[] = __('":track" needs a valid ISRC.', ['track' => $name]);
            }
            $language = $this->language($release, $track);
            if ($language === null) {
                $lang = $track->language ?? $release->language;
                $problems[] = $lang
                    ? __('Map the language ":language" to a PDL language in Settings → PDL distribution.', ['language' => $lang->name])
                    : __('Set a language on ":track" or on the release.', ['track' => $name]);
            }
            $instrumental = $language === 'instrumental';
            if (! $instrumental) {
                if ($this->credited($track, [ContributorRole::Composer, ContributorRole::Songwriter])->isEmpty()) {
                    $problems[] = __('":track" needs a composer (or songwriter) credit.', ['track' => $name]);
                }
                if ($this->credited($track, [ContributorRole::Lyricist, ContributorRole::Songwriter])->isEmpty()) {
                    $problems[] = __('":track" needs a lyricist (or songwriter) credit.', ['track' => $name]);
                }
            }
            [, $audioProblem, $audioWarning] = $this->audioFile($track, false);
            if ($audioProblem) {
                $problems[] = $audioProblem;
            }
            if ($audioWarning) {
                $warnings[] = $audioWarning;
            }
        }

        return ['problems' => array_values(array_unique($problems)), 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * The request body and the files to upload. Call check() first.
     *
     * @return array{body: array<string, mixed>, files: list<array{key: string, kind: string, isrc: ?string, path: string, filename: string, size: int, md5: string}>}
     */
    public function build(Release $release, string $mood, bool $isUpdate = false): array
    {
        $release = $this->load($release);
        $files = [];

        [$artwork] = $this->artworkFile($release);
        $files[] = $artwork + ['key' => 'inlay', 'kind' => 'artwork', 'isrc' => null];

        $labelName = $this->labelName($release);
        $contentType = $this->contentType($release);
        $albumType = (string) $this->settings->get('album_type');
        $explicitRelease = (bool) $release->explicit;
        $releaseArtists = $release->artists;

        $songs = [];
        foreach ($release->tracks->values() as $index => $track) {
            [$audio] = $this->audioFile($track);
            $isrc = $this->isrc($track);
            $files[] = $audio + ['key' => 'song:'.$isrc, 'kind' => 'audio', 'isrc' => $isrc];

            $language = (string) $this->language($release, $track);
            $instrumental = $language === 'instrumental';
            $explicit = $explicitRelease || $track->explicit;
            [$genre, $subGenre] = $this->settings->genreFor($release->genre_id) ?? ['', ''];
            $trackArtists = $track->artists->isNotEmpty() ? $track->artists : $releaseArtists;

            $main = $this->artistsWithRole($trackArtists, ArtistRole::Primary);
            $featured = $this->artistsWithRole($trackArtists, ArtistRole::Featuring);
            $remixers = $this->artistsWithRole($trackArtists, ArtistRole::Remixer);

            $songs[] = [
                'isrc' => $isrc,
                'track_no' => $index + 1,
                'data' => [
                    'crbt_cut_name' => Str::limit($track->title, 64, ''),
                    'song_name' => $track->title,
                    'album_name' => $release->title,
                    'language' => $this->languageLabel($language),
                    'album_type' => $albumType,
                    'content_type' => $contentType,
                    'genre' => $genre,
                    'sub_genre' => $subGenre,
                    'mood' => $mood,
                    'description' => '',
                    'isrc' => $isrc,
                    'label' => $labelName,
                    'publisher' => $this->publisher($release, $labelName),
                    'track_duration' => $this->duration($track->duration_seconds),
                    'time_for_crbt_cut' => $this->duration((int) $this->settings->get('crbt_start')),
                    'original_release_date_of_movie' => $this->date($release->original_release_date ?? $release->release_date), // required by COSMOS even for non-film albums
                    'original_release_date_of_music' => $this->date($release->original_release_date ?? $release->release_date),
                    'go_live_date' => $this->date($release->release_date),
                    'c_line' => (string) $release->c_line,
                    'p_line' => (string) $release->p_line,
                    'parental_advisory (EXPLICIT ETC)' => $explicit ? 'Explicit' : 'Not Explicit',
                    'parental_advisory' => $explicit ? 'Explicit' : 'Not Explicit',
                    'is_instrumental' => $instrumental ? 'Yes' : 'No',
                    'upc_id' => (string) $release->upc,
                ],
                'is_instrumental' => $instrumental ? 'Yes' : 'No',
                'explicit' => $explicit,
                'upc_id' => (string) $release->upc,
                'track_main_artist' => $main,
                'track_featured_artist' => $featured,
                'track_remixer_artist' => $remixers,
                'singers' => $instrumental ? [] : array_merge($main, $featured),
                'composers' => $this->contributors($track, [ContributorRole::Composer, ContributorRole::Songwriter]),
                'lyricists' => $this->contributors($track, [ContributorRole::Lyricist, ContributorRole::Songwriter]),
                'producers' => $this->contributors($track, [ContributorRole::Producer]),
                'media' => ['id' => '', 'size' => $audio['size'], 'md5' => $audio['md5'], 'filename' => $audio['filename']],
            ];
        }

        $album = [
            'is_update' => $isUpdate,
            'name' => $release->title,
            'album_category' => $release->version ?: (string) $this->settings->get('album_category'),
            'album_type' => $albumType,
            'album_subtype' => $contentType,
            'label' => $labelName,
            'c_line' => (string) $release->c_line,
            'p_line' => (string) $release->p_line,
            'upc_id' => (string) $release->upc,
            'catalog_number' => (string) $release->catalog_number,
            'songs' => $songs,
            'inlay' => ['id' => '', 'size' => $artwork['size'], 'md5' => $artwork['md5'], 'filename' => $artwork['filename']],
            'album_main_artist' => $this->artistsWithRole($releaseArtists, ArtistRole::Primary),
            'album_featured_artist' => $this->artistsWithRole($releaseArtists, ArtistRole::Featuring),
            'album_remixer_artist' => $this->artistsWithRole($releaseArtists, ArtistRole::Remixer),
        ];

        return ['body' => ['version' => '2', 'albums' => [$album]], 'files' => $files];
    }

    /**
     * Apply the current LabelDesk metadata to an album as COSMOS returned it
     * (for PUT /album/media/update, which needs the complete album). Songs
     * are matched by ISRC; COSMOS ids and fields LabelDesk does not know are kept.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    /** Album fields sent to PUT /album/media/update (as in PDL's Postman example; file objects are left out). */
    public const UPDATE_ALBUM_KEYS = [
        'id', 'version', 'name', 'catalog_number', 'upc', 'nue_meta_upc', 'category', 'date_of_release', 'go_live_date', 'go_live_time',
        'date_of_expiry', 'album_type', 'album_subtype', 'c_line', 'p_line', 'publisher', 'album_main_artist', 'album_featured_artist',
        'album_remixer', 'album_priority',
    ];

    public const UPDATE_SONG_KEYS = [
        'id', 'track_order', 'title', 'isrc', 'go_live_date', 'dolby_isrc', 'language', 'genre', 'sub_genre', 'additional_info', 'mood',
        'starcast', 'lyricists', 'composers', 'singers', 'directors', 'producers', 'sound_recording_rights', 'musical_literary_works',
        'iprs_ownership', 'iprs_member', 'iprs_member_1', 'explicit', 'crbt_cuts', 'track_main_artist', 'track_featured_artist',
        'track_remixer', 'is_instrumental',
    ];

    /**
     * The complete album for PUT /album/media/update: the album as COSMOS
     * returned it, limited to the fields of PDL's update example (the stored
     * artwork / media objects are not sent back: COSMOS re-checks them and
     * answers "MD5 mismatch"), with the current LabelDesk metadata applied.
     * Songs are matched by ISRC; COSMOS ids are kept.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    public function mergeForUpdate(array $remote, Release $release, string $mood): array
    {
        $built = $this->build($release, $mood, true)['body']['albums'][0];
        $iso = fn (?CarbonInterface $d) => $d ? $d->copy()->startOfDay()->format('Y-m-d\T00:00:00.000\Z') : null;
        $goLive = $iso($release->release_date);

        $album = array_intersect_key($remote, array_flip(self::UPDATE_ALBUM_KEYS));
        $album['version'] = '2';
        foreach (['name', 'album_type', 'album_subtype', 'c_line', 'p_line', 'catalog_number', 'album_main_artist', 'album_featured_artist'] as $key) {
            $album[$key] = $built[$key];
        }
        $album['album_remixer'] = $built['album_remixer_artist'];
        $album['category'] = $built['album_category'];
        $album['go_live_date'] = $goLive;
        $album['date_of_release'] = $iso($release->original_release_date ?? $release->release_date);

        $byIsrc = collect($built['songs'])->keyBy('isrc');
        $album['songs'] = array_values(array_map(function ($song) use ($byIsrc, $goLive) {
            if (! is_array($song)) {
                return $song;
            }
            $kept = array_intersect_key($song, array_flip(self::UPDATE_SONG_KEYS));
            if (isset($kept['crbt_cuts']) && is_array($kept['crbt_cuts'])) {
                $kept['crbt_cuts'] = array_map(fn ($cut) => is_array($cut) ? array_diff_key($cut, ['crbt_cut_media' => 1, 'generated_path' => 1]) : $cut, $kept['crbt_cuts']);
            }
            $mine = $byIsrc[$song['isrc'] ?? ''] ?? null;
            if ($mine === null) {
                return $kept;
            }
            $d = $mine['data'];

            return array_merge($kept, [
                'title' => $d['song_name'], 'language' => $d['language'], 'genre' => $d['genre'], 'sub_genre' => $d['sub_genre'],
                'mood' => $d['mood'], 'explicit' => $mine['explicit'], 'is_instrumental' => $mine['is_instrumental'] === 'Yes',
                'go_live_date' => $goLive,
                'track_main_artist' => $mine['track_main_artist'], 'track_featured_artist' => $mine['track_featured_artist'],
                'track_remixer' => $mine['track_remixer_artist'], 'singers' => $mine['singers'], 'composers' => $mine['composers'],
                'lyricists' => $mine['lyricists'], 'producers' => $mine['producers'],
            ]);
        }, (array) ($remote['songs'] ?? [])));

        return $album;
    }

    // ------------------------------------------------------------ files

    /** @return array{0: array{path: string, filename: string, size: int, md5: string}|null, 1: ?string} */
    public function artworkFile(Release $release, bool $hash = true): array
    {
        $disk = Storage::disk(self::DISK);
        $candidates = array_filter([$release->artwork_original_path ?? null, $release->artwork_path]);
        foreach ($candidates as $relative) {
            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png'], true) || ! $disk->exists($relative)) {
                continue; // PDL accepts JPG or PNG; the app's own copy is always JPG
            }
            $path = $disk->path($relative);
            $info = @getimagesize($path);
            if (! $info) {
                return [null, __('The artwork file could not be read.')];
            }
            [$w, $h] = $info;
            if ($w !== $h || $w < Cosmos::ARTWORK_MIN_PX || $w > Cosmos::ARTWORK_MAX_PX) {
                if ($relative !== end($candidates)) {
                    continue;
                }

                return [null, __('PDL needs square artwork between :min and :max px (this is :w × :h px).', ['min' => Cosmos::ARTWORK_MIN_PX, 'max' => Cosmos::ARTWORK_MAX_PX, 'w' => $w, 'h' => $h])];
            }
            if (($info[2] ?? null) === IMAGETYPE_JPEG && ($info['channels'] ?? 3) === 4) {
                return [null, __('PDL needs RGB artwork; this JPEG is CMYK.')];
            }
            $name = ($release->upc ?: 'release-'.$release->id).'.'.($ext === 'jpeg' ? 'jpg' : $ext);

            return [['path' => $path, 'filename' => $name, 'size' => (int) filesize($path), 'md5' => $hash ? (string) md5_file($path) : ''], null];
        }

        return [null, $release->artwork_path
            ? __('The artwork file is not on this server.')
            : __('Add artwork to the release.')];
    }

    /** @return array{0: array{path: string, filename: string, size: int, md5: string}|null, 1: ?string, 2: ?string} [file, problem, warning] */
    public function audioFile(Track $track, bool $hash = true): array
    {
        $name = $track->displayTitle();
        if (! $track->audio_path) {
            return [null, __('Upload the master audio of ":track".', ['track' => $name]), null];
        }
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($track->audio_path)) {
            return [null, __('The master of ":track" is not on this server (it may have been moved to cloud storage). Upload it again to send it to PDL.', ['track' => $name]), null];
        }

        $ext = strtolower(pathinfo($track->audio_original_name ?: $track->audio_path, PATHINFO_EXTENSION));
        $mime = (string) $track->audio_mime;
        $isWav = in_array($ext, ['wav', 'wave'], true) || str_contains($mime, 'wav');
        $isMp3 = $ext === 'mp3' || $mime === 'audio/mpeg';
        $warning = null;
        if (! $isWav && ! $isMp3) {
            return [null, __('PDL accepts WAV masters; ":track" is :type.', ['track' => $name, 'type' => strtoupper($ext ?: $mime)]), null];
        }
        if ($isMp3) {
            $warning = __('":track" is an MP3: PDL still accepts MP3 but will stop soon. Prefer WAV.', ['track' => $name]);
        } elseif ($track->audio_sample_rate && $track->audio_bit_depth) {
            $depths = Cosmos::AUDIO_SPECS[(int) $track->audio_sample_rate] ?? null;
            if ($depths === null || ! in_array((int) $track->audio_bit_depth, $depths, true)) {
                return [null, __('":track" is :rate Hz / :bits bit, which PDL does not accept (44.1 kHz 16-bit or better, see the guide).', ['track' => $name, 'rate' => $track->audio_sample_rate, 'bits' => $track->audio_bit_depth]), null];
            }
        }

        $path = $disk->path($track->audio_path);

        return [[
            'path' => $path,
            'filename' => $this->isrc($track).'.'.($isWav ? 'wav' : 'mp3'),
            'size' => (int) filesize($path),
            'md5' => $hash ? (string) md5_file($path) : '',
        ], null, $warning];
    }

    // ------------------------------------------------------------ mapping helpers

    protected function load(Release $release): Release
    {
        return $release->loadMissing([
            'genre', 'language', 'label', 'artists.links',
            'tracks.language', 'tracks.artists.links', 'tracks.credits.contributor',
        ]);
    }

    public function labelName(Release $release): string
    {
        $setting = trim((string) $this->settings->get('label_name'));

        return $setting !== '' ? $setting : trim((string) ($release->label?->name ?? $release->label_name));
    }

    protected function contentType(Release $release): string
    {
        $map = (array) $this->settings->get('content_types');
        $value = $map[$release->type->value] ?? PdlSettings::DEFAULTS['content_types'][$release->type->value] ?? 'Album';

        return in_array($value, Cosmos::CONTENT_TYPES, true) ? $value : 'Album';
    }

    public function isrc(Track $track): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $track->isrc));
    }

    public function language(Release $release, Track $track): ?string
    {
        $language = $track->language ?? $release->language;

        return $language ? $this->settings->languageFor($language->code, $language->name) : null;
    }

    /** COSMOS examples send languages capitalised ("Bengali"); the code for no linguistic content stays "zxx". */
    protected function languageLabel(string $language): string
    {
        return $language === 'zxx' ? $language : Str::ucfirst($language);
    }

    protected function publisher(Release $release, string $labelName): string
    {
        foreach ($release->tracks as $track) {
            $credit = $track->credits->first(fn ($c) => $c->role === ContributorRole::Publisher && $c->contributor);
            if ($credit) {
                return $credit->contributor->name;
            }
        }
        $setting = trim((string) $this->settings->get('publisher'));

        return $setting !== '' ? $setting : $labelName;
    }

    protected function duration(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    protected function date(?CarbonInterface $date): string
    {
        return $date ? $date->format((string) $this->settings->get('date_format')) : '';
    }

    /**
     * @param  Collection<int, Artist>  $artists
     * @return list<array<string, mixed>>
     */
    protected function artistsWithRole(Collection $artists, ArtistRole $role): array
    {
        return $artists->filter(fn (Artist $a) => ($a->pivot?->role ?? null) === $role->value)
            ->map(fn (Artist $a) => $this->artist($a))->values()->all();
    }

    /** @return array<string, mixed> */
    public function artist(Artist $artist): array
    {
        $links = $artist->relationLoaded('links') ? $artist->links : $artist->links()->get();
        $url = fn (LinkPlatform $p) => (string) ($links->first(fn ($l) => $l->platform === $p)?->url ?? '');

        return [
            'id' => '',
            'name' => $artist->name,
            'apple_id' => self::appleId($url(LinkPlatform::AppleMusic)),
            'meta_id' => '',
            'facebook_artist_page_url' => $url(LinkPlatform::Facebook),
            'insta_artist_page_url' => $url(LinkPlatform::Instagram),
            'spotify_id' => self::spotifyId($url(LinkPlatform::Spotify)),
            'locale' => '',
            'is_iprs_member' => false,
            'ipi_number' => '',
        ];
    }

    /**
     * @param  list<ContributorRole>  $roles
     * @return Collection<int, Contributor>
     */
    protected function credited(Track $track, array $roles): Collection
    {
        return $track->credits->filter(fn ($c) => in_array($c->role, $roles, true) && $c->contributor)
            ->map(fn ($c) => $c->contributor)->unique('id')->values();
    }

    /**
     * @param  list<ContributorRole>  $roles
     * @return list<array<string, mixed>>
     */
    protected function contributors(Track $track, array $roles): array
    {
        return $this->credited($track, $roles)->map(fn (Contributor $c) => [
            'id' => '',
            'name' => $c->name,
            'apple_id' => '',
            'meta_id' => '',
            'facebook_artist_page_url' => '',
            'insta_artist_page_url' => '',
            'spotify_id' => '',
            'locale' => '',
            'is_iprs_member' => (bool) preg_match('/\bIPRS\b/i', (string) $c->pro),
            'ipi_number' => (string) $c->ipi_number,
        ])->all();
    }

    public static function spotifyId(string $url): string
    {
        return preg_match('#spotify\.com/(?:intl-[a-z-]+/)?artist/([A-Za-z0-9]{22})#', $url, $m) ? $m[1] : '';
    }

    public static function appleId(string $url): string
    {
        return preg_match('#apple\.com/(?:[a-z]{2}/)?artist/(?:[^/?]+/)?(?:id)?(\d{4,})#', $url, $m) ? $m[1] : '';
    }
}
