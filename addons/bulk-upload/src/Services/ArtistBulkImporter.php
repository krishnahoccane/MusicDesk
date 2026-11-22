<?php

namespace LabelDesk\Addons\BulkUpload\Services;

use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Enums\LinkPlatform;
use App\Models\User;
use App\Services\ArtistService;
use App\Support\Countries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Bulk upload of artist profiles: one row per artist. Existing artists (same
 * name) are skipped, never overwritten.
 */
class ArtistBulkImporter
{
    /** Template columns => [heading, required, example]. */
    public static function columns(): array
    {
        return [
            'name' => [__('Name'), true, 'Nova Lights'],
            'legal_name' => [__('Legal name'), false, 'Nova Lights LLP'],
            'type' => [__('Type'), false, 'band'],
            'status' => [__('Status'), false, 'active'],
            'country' => [__('Country'), false, 'IN'],
            'primary_genre' => [__('Primary genre'), false, 'Indie Pop'],
            'email' => [__('Email'), false, 'hello@example.com'],
            'phone' => [__('Phone'), false, '+91 98765 43210'],
            'bio' => [__('Bio'), false, 'Four-piece indie band from Pune.'],
            'spotify' => ['Spotify', false, 'https://open.spotify.com/artist/…'],
            'apple_music' => ['Apple Music', false, ''],
            'youtube' => ['YouTube', false, ''],
            'instagram' => ['Instagram', false, 'https://instagram.com/novalights'],
            'website' => [__('Website'), false, ''],
        ];
    }

    public static function help(): array
    {
        return [
            __('Type: :list (default solo).', ['list' => implode(', ', array_map(fn ($c) => $c->value, ArtistType::cases()))]),
            __('Status: :list (default active).', ['list' => implode(', ', array_map(fn ($c) => $c->value, ArtistStatus::cases()))]),
            __('Country: two-letter ISO code, e.g. IN, US, GB.'),
            __('Artists whose name already exists are skipped.'),
        ];
    }

    public function __construct(protected ArtistService $artists) {}

    /**
     * @param  list<array{row: int, data: array<string, string>}>  $rows
     * @return array{items: list<array{row: int, label: string, data: array<string, mixed>, links: array<string, string>}>, errors: array<int, list<string>>, skipped: array<int, string>}
     */
    public function analyse(array $rows): array
    {
        $items = [];
        $errors = [];
        $skipped = [];
        $seen = [];
        $existing = DB::table('artists')->whereNull('deleted_at')->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip();

        foreach ($rows as ['row' => $row, 'data' => $data]) {
            $name = trim($data['name']);
            $key = mb_strtolower($name);

            if ($name !== '' && (isset($existing[$key]) || isset($seen[$key]))) {
                $skipped[$row] = __('":name" already exists.', ['name' => $name]);

                continue;
            }

            $values = [
                'name' => $name,
                'legal_name' => $data['legal_name'] ?: null,
                'type' => strtolower($data['type']) ?: ArtistType::Solo->value,
                'status' => strtolower($data['status']) ?: ArtistStatus::Active->value,
                'country' => strtoupper($data['country']) ?: null,
                'primary_genre' => $data['primary_genre'] ?: null,
                'email' => $data['email'] ?: null,
                'phone' => $data['phone'] ?: null,
                'bio' => $data['bio'] ?: null,
            ];
            $links = array_filter([
                LinkPlatform::Spotify->value => $data['spotify'],
                LinkPlatform::AppleMusic->value => $data['apple_music'],
                LinkPlatform::YouTube->value => $data['youtube'],
                LinkPlatform::Instagram->value => $data['instagram'],
                LinkPlatform::Website->value => $data['website'],
            ]);

            $validator = Validator::make($values + ['links' => $links], [
                'name' => ['required', 'string', 'max:150'],
                'legal_name' => ['nullable', 'string', 'max:200'],
                'type' => ['required', 'in:'.implode(',', array_map(fn ($c) => $c->value, ArtistType::cases()))],
                'status' => ['required', 'in:'.implode(',', array_map(fn ($c) => $c->value, ArtistStatus::cases()))],
                'country' => ['nullable', 'in:'.implode(',', Countries::codes())],
                'primary_genre' => ['nullable', 'string', 'max:80'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s\/]*$/'],
                'bio' => ['nullable', 'string', 'max:5000'],
                'links.*' => ['url:https,http', 'max:500'],
            ], [], ['links.*' => __('link')]);

            if ($validator->fails()) {
                $errors[$row] = $validator->errors()->all();

                continue;
            }

            $seen[$key] = true;
            $items[] = ['row' => $row, 'label' => $name, 'data' => $values, 'links' => $links];
        }

        return ['items' => $items, 'errors' => $errors, 'skipped' => $skipped];
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
                    $artist = $this->artists->create($actor, $item['data']);
                    foreach ($item['links'] as $platform => $url) {
                        $this->artists->saveLink($actor, $artist, null, $platform, $url);
                    }
                });
                $created++;
            } catch (Throwable $e) {
                report($e);
                $failed[$item['row']] = $e->getMessage();
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }
}
