<?php

namespace App\Services\BulkUpload;

use App\Enums\ArtistRole;
use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Enums\ReleaseType;
use App\Exceptions\CatalogException;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Language;
use App\Models\User;
use App\Services\ArtistService;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\TrackService;
use App\Support\ArtistAccess;
use App\Support\CatalogDefaults;
use App\Support\Identifiers;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk upload of releases with their tracks: one row per track. Rows with
 * the same UPC (or, without a UPC, the same title, version and artists)
 * form one release. Releases are created as drafts; artwork and audio are
 * added afterwards on each release.
 */
class ReleaseBulkImporter
{
    public static function columns(): array
    {
        return [
            'release_title' => [__('Release title'), true, 'Glass City'],
            'release_version' => [__('Release version'), false, ''],
            'release_type' => [__('Release type'), false, 'ep'],
            'release_artists' => [__('Release artists'), true, 'Nova Lights'],
            'featuring_artists' => [__('Featuring artists'), false, ''],
            'upc' => ['UPC', false, ''],
            'catalog_number' => [__('Catalogue number'), false, 'SR-001'],
            'release_date' => [__('Release date'), false, now()->addMonth()->format('Y-m-d')],
            'original_release_date' => [__('Original release date'), false, ''],
            'genre' => [__('Genre'), false, 'Pop'],
            'language' => [__('Language'), false, 'en'],
            'label_name' => [__('Label name'), false, ''],
            'c_line' => [__('C line'), false, ''],
            'p_line' => [__('P line'), false, ''],
            'disc' => [__('Disc'), false, '1'],
            'track_title' => [__('Track title'), true, 'Glass City'],
            'track_version' => [__('Track version'), false, 'Radio Edit'],
            'track_artists' => [__('Track artists'), false, ''],
            'isrc' => ['ISRC', false, ''],
            'duration' => [__('Duration'), false, '3:25'],
            'explicit' => [__('Explicit'), false, 'no'],
            'track_language' => [__('Track language'), false, ''],
        ];
    }

    public static function help(): array
    {
        return [
            __('One row per track. Rows with the same UPC (or the same release title, version and artists) become one release, in the order of the rows.'),
            __('Separate several artists with ";". Track artists default to the release artists.'),
            __('Release type: :list. Dates as YYYY-MM-DD. Duration as 3:25 or seconds.', ['list' => implode(', ', array_map(fn ($c) => $c->value, ReleaseType::cases()))]),
            __('Genre and language must exist in Settings → Catalogue (language by name or ISO code).'),
            __('Releases are created as drafts. Add artwork and audio on each release, then submit it for review.'),
        ];
    }

    /** @var array<string, int>|null name (lower case) => id */
    protected ?array $artistIndex = null;

    public function __construct(
        protected ReleaseService $releases,
        protected TrackService $tracks,
        protected ArtistService $artists,
    ) {}

    /**
     * @param  list<array{row: int, data: array<string, string>}>  $rows
     * @return array{items: list<array<string, mixed>>, errors: array<int, list<string>>, skipped: array<int, string>, missing: list<string>}
     */
    public function analyse(array $rows, bool $createMissingArtists = false): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $d = $row['data'];
            $upc = Identifiers::normalizeUpc($d['upc']);
            $key = $upc !== null ? 'upc:'.$upc : 'title:'.mb_strtolower(trim($d['release_title']).'|'.trim($d['release_version']).'|'.trim($d['release_artists']));
            $groups[$key][] = $row;
        }

        $items = [];
        $errors = [];
        $missing = [];
        $isrcs = [];
        $restricted = ArtistAccess::restricted();
        $genres = Genre::query()->where('is_active', true)->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
        $languages = Language::query()->get(['id', 'code', 'name'])
            ->flatMap(fn ($l) => [strtolower($l->code) => $l->id, mb_strtolower($l->name) => $l->id]);

        foreach ($groups as $groupRows) {
            $first = $groupRows[0]['data'];
            $firstRow = $groupRows[0]['row'];
            $releaseErrors = [];

            $type = strtolower($first['release_type']) ?: (count($groupRows) === 1 ? ReleaseType::Single->value : ReleaseType::Album->value);
            if (ReleaseType::tryFrom($type) === null) {
                $releaseErrors[] = __('Unknown release type ":type".', ['type' => $first['release_type']]);
            }

            $upc = Identifiers::normalizeUpc($first['upc']);
            if ($upc !== null && ! Identifiers::isValidUpc($upc)) {
                $releaseErrors[] = __('UPC :upc is not valid (check digit).', ['upc' => $upc]);
            } elseif ($upc !== null && DB::table('releases')->where('upc', $upc)->exists()) {
                $releaseErrors[] = __('UPC :upc is already used in the catalogue.', ['upc' => $upc]);
            }

            $catalog = trim($first['catalog_number']);
            if ($catalog !== '' && DB::table('releases')->where('catalog_number', $catalog)->exists()) {
                $releaseErrors[] = __('Catalogue number :no is already used.', ['no' => $catalog]);
            }

            foreach (['release_date', 'original_release_date'] as $field) {
                if ($first[$field] !== '' && ! $this->validDate($first[$field])) {
                    $releaseErrors[] = __(':field must be a date like 2026-12-31.', ['field' => self::columns()[$field][0]]);
                }
            }

            $genreId = null;
            if ($first['genre'] !== '') {
                $genreId = $genres[mb_strtolower($first['genre'])] ?? null;
                if ($genreId === null) {
                    $releaseErrors[] = __('Unknown genre ":genre".', ['genre' => $first['genre']]);
                }
            }
            $languageId = $this->language($first['language'], $languages, $releaseErrors) ?? $languages[CatalogDefaults::languageCode()] ?? null;

            $releaseArtists = $this->artistRows($first['release_artists'], $first['featuring_artists'], $createMissingArtists, $restricted, $missing, $releaseErrors);
            if (trim($first['release_title']) === '') {
                $releaseErrors[] = __('Release title is required.');
            }

            $tracks = [];
            foreach ($groupRows as ['row' => $row, 'data' => $d]) {
                $trackErrors = [];
                if (trim($d['track_title']) === '') {
                    $trackErrors[] = __('Track title is required.');
                }

                $isrc = Identifiers::normalizeIsrc($d['isrc']);
                if ($isrc !== null) {
                    if (! Identifiers::isValidIsrc($isrc)) {
                        $trackErrors[] = __('ISRC :isrc is not valid (CC-XXX-YY-NNNNN).', ['isrc' => $isrc]);
                    } elseif (isset($isrcs[$isrc]) || ($type !== ReleaseType::Compilation->value && DB::table('tracks')->where('isrc', $isrc)->exists())) {
                        $trackErrors[] = __('ISRC :isrc is already used.', ['isrc' => $isrc]);
                    }
                    $isrcs[$isrc] = true;
                }

                $duration = BulkSheet::duration($d['duration']);
                if ($duration === -1) {
                    $trackErrors[] = __('Duration ":value" should look like 3:25.', ['value' => $d['duration']]);
                }

                $trackArtists = trim($d['track_artists']) !== ''
                    ? $this->artistRows($d['track_artists'], '', $createMissingArtists, $restricted, $missing, $trackErrors)
                    : $releaseArtists;

                $trackLanguage = $this->language($d['track_language'], $languages, $trackErrors) ?? $languageId;

                if ($trackErrors) {
                    $errors[$row] = $trackErrors;
                }

                $tracks[] = [
                    'row' => $row,
                    'data' => [
                        'title' => trim($d['track_title']),
                        'version' => trim($d['track_version']) ?: null,
                        'isrc' => $isrc,
                        'explicit' => BulkSheet::bool($d['explicit']),
                        'language_id' => $trackLanguage,
                        'disc_number' => max(1, (int) ($d['disc'] ?: 1)),
                        'duration_seconds' => $duration !== null && $duration >= 0 ? $duration : null,
                    ],
                    'artists' => $trackArtists,
                ];
            }

            if ($releaseErrors) {
                $errors[$firstRow] = array_merge($releaseErrors, $errors[$firstRow] ?? []);
            }

            // A release is imported only when it and all of its tracks are valid.
            $valid = ! collect($groupRows)->contains(fn ($r) => isset($errors[$r['row']]));
            if (! $valid) {
                continue;
            }

            $items[] = [
                'row' => $firstRow,
                'label' => trim($first['release_title']).($first['release_version'] !== '' ? ' ('.$first['release_version'].')' : '')
                    .' · '.trans_choice(':count track|:count tracks', count($tracks)),
                'data' => [
                    'title' => trim($first['release_title']),
                    'version' => trim($first['release_version']) ?: null,
                    'type' => $type,
                    'upc' => $upc,
                    'catalog_number' => $catalog ?: null,
                    'release_date' => $first['release_date'] ?: null,
                    'original_release_date' => $first['original_release_date'] ?: null,
                    'genre_id' => $genreId,
                    'language_id' => $languageId,
                    'label_name' => trim($first['label_name']) ?: CatalogDefaults::labelName(),
                    'c_line' => trim($first['c_line']) ?: CatalogDefaults::cLine(),
                    'p_line' => trim($first['p_line']) ?: CatalogDefaults::pLine(),
                ],
                'artists' => $releaseArtists,
                'tracks' => $tracks,
            ];
        }

        return ['items' => $items, 'errors' => $errors, 'skipped' => [], 'missing' => array_values(array_unique($missing))];
    }

    /**
     * @return array{created: int, failed: array<int, string>}
     */
    public function import(User $actor, array $analysis): array
    {
        $created = 0;
        $failed = [];

        foreach ($analysis['items'] as $item) {
            try {
                DB::transaction(function () use ($actor, $item) {
                    $artists = $this->resolve($actor, $item['artists']);
                    $release = $this->releases->create($actor, $item['data'], $artists);
                    foreach ($item['tracks'] as $track) {
                        $this->tracks->save($actor, $release, null, $track['data'], $this->resolve($actor, $track['artists']), []);
                    }
                });
                $created++;
            } catch (Throwable $e) {
                if (! $e instanceof CatalogException) {
                    report($e);
                }
                $failed[$item['row']] = $e->getMessage();
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>  $errors
     * @return list<array{name: string, id: ?int, role: string}>
     */
    protected function artistRows(string $primary, string $featuring, bool $create, bool $restricted, array &$missing, array &$errors): array
    {
        $rows = [];
        foreach ([[$primary, ArtistRole::Primary], [$featuring, ArtistRole::Featuring]] as [$names, $role]) {
            foreach (BulkSheet::names($names) as $name) {
                $id = $this->artistIndex()[mb_strtolower($name)] ?? null;
                if ($id === null) {
                    if ($restricted) {
                        $errors[] = __('":name" is not one of your artist profiles.', ['name' => $name]);

                        continue;
                    }
                    if (! $create) {
                        $errors[] = __('Artist ":name" does not exist. Add the artist first, or tick "Create missing artists".', ['name' => $name]);

                        continue;
                    }
                    $missing[] = $name;
                }
                $rows[] = ['name' => $name, 'id' => $id, 'role' => $role->value];
            }
        }

        if (! collect($rows)->contains('role', ArtistRole::Primary->value)) {
            $errors[] = __('At least one primary artist is required.');
        }

        return $rows;
    }

    /**
     * @return list<array{artist_id: int, role: string}>
     */
    protected function resolve(User $actor, array $rows): array
    {
        return array_map(function ($row) use ($actor) {
            $id = $row['id'] ?? $this->artistIndex()[mb_strtolower($row['name'])] ?? null;
            if ($id === null) {
                $artist = $this->artists->create($actor, ['name' => $row['name'], 'type' => ArtistType::Solo->value, 'status' => ArtistStatus::Active->value]);
                $this->artistIndex[mb_strtolower($row['name'])] = $id = $artist->id;
            }

            return ['artist_id' => $id, 'role' => $row['role']];
        }, $rows);
    }

    /**
     * Artists visible to the current user (artist accounts: only their own).
     *
     * @return array<string, int>
     */
    protected function artistIndex(): array
    {
        return $this->artistIndex ??= Artist::query()->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => (int) $id])->all();
    }

    protected function language(string $value, $languages, array &$errors): ?int
    {
        if (trim($value) === '') {
            return null;
        }
        $id = $languages[mb_strtolower(trim($value))] ?? null;
        if ($id === null) {
            $errors[] = __('Unknown language ":language".', ['language' => $value]);
        }

        return $id;
    }

    protected function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }
}
