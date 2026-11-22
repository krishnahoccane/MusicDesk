<?php

namespace LabelDesk\Addons\RestApi\Support;

use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Release;
use App\Models\Track;
use App\Models\TrackContributor;
use Carbon\CarbonInterface;

/**
 * JSON shapes of the v1 resources. Storage paths (photos, artwork, audio)
 * are never exposed; only whether a file exists.
 */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function artist(Artist $artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'legal_name' => $artist->legal_name,
            'type' => $artist->type?->value,
            'status' => $artist->status?->value,
            'country' => $artist->country,
            'primary_genre' => $artist->primary_genre,
            'email' => $artist->email,
            'phone' => $artist->phone,
            'bio' => $artist->bio,
            'notes' => $artist->notes,
            'has_photo' => $artist->photo_path !== null,
            'created_at' => self::time($artist->created_at),
            'updated_at' => self::time($artist->updated_at),
            'deleted_at' => self::time($artist->deleted_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function release(Release $release, ?array $availableActions = null): array
    {
        $data = [
            'id' => $release->id,
            'title' => $release->title,
            'version' => $release->version,
            'display_title' => $release->displayTitle(),
            'type' => $release->type?->value,
            'status' => $release->status?->value,
            'upc' => $release->upc,
            'catalog_number' => $release->catalog_number,
            'label_name' => $release->label_name,
            'c_line' => $release->c_line,
            'p_line' => $release->p_line,
            'release_date' => $release->release_date?->toDateString(),
            'original_release_date' => $release->original_release_date?->toDateString(),
            'genre' => $release->genre ? ['id' => $release->genre->id, 'name' => $release->genre->name] : null,
            'language' => $release->language ? ['id' => $release->language->id, 'code' => $release->language->code, 'name' => $release->language->name] : null,
            'explicit' => (bool) $release->explicit,
            'notes' => $release->notes,
            'artists' => self::credits($release->artists),
            'artist_line' => Release::formatArtistLine($release->artists),
            'track_count' => $release->tracks_count ?? $release->tracks()->count(),
            'has_artwork' => $release->artwork_path !== null,
            'submitted_at' => self::time($release->submitted_at),
            'approved_at' => self::time($release->approved_at),
            'created_at' => self::time($release->created_at),
            'updated_at' => self::time($release->updated_at),
            'deleted_at' => self::time($release->deleted_at),
        ];

        if ($availableActions !== null) {
            $data['workflow'] = ['available_actions' => $availableActions];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function track(Track $track): array
    {
        return [
            'id' => $track->id,
            'release_id' => $track->release_id,
            'title' => $track->title,
            'version' => $track->version,
            'display_title' => $track->displayTitle(),
            'isrc' => $track->isrc,
            'duration_seconds' => $track->duration_seconds,
            'explicit' => (bool) $track->explicit,
            'language' => $track->language ? ['id' => $track->language->id, 'code' => $track->language->code, 'name' => $track->language->name] : null,
            'disc_number' => (int) $track->disc_number,
            'position' => (int) $track->position,
            'has_audio' => $track->hasAudio(),
            'artists' => self::credits($track->artists),
            'artist_line' => Release::formatArtistLine($track->artists),
            'credits' => $track->credits->map(fn (TrackContributor $c) => [
                'contributor_id' => $c->contributor_id,
                'name' => $c->contributor?->name,
                'role' => $c->role?->value,
                'share' => $c->share !== null ? rtrim(rtrim((string) $c->share, '0'), '.') : null,
            ])->values()->all(),
            'created_at' => self::time($track->created_at),
            'updated_at' => self::time($track->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function contributor(Contributor $contributor): array
    {
        return [
            'id' => $contributor->id,
            'name' => $contributor->name,
            'legal_name' => $contributor->legal_name,
            'ipi_number' => $contributor->ipi_number,
            'pro' => $contributor->pro,
            'email' => $contributor->email,
            'notes' => $contributor->notes,
            'credit_count' => $contributor->credits_count ?? $contributor->credits()->count(),
            'created_at' => self::time($contributor->created_at),
            'updated_at' => self::time($contributor->updated_at),
        ];
    }

    /**
     * @param  iterable<Artist>  $artists  with pivot role/position
     * @return list<array{id: int, name: string, role: ?string, position: ?int}>
     */
    private static function credits(iterable $artists): array
    {
        $out = [];
        foreach ($artists as $artist) {
            $out[] = [
                'id' => $artist->id,
                'name' => $artist->name,
                'role' => $artist->pivot?->role,
                'position' => $artist->pivot?->position !== null ? (int) $artist->pivot->position : null,
            ];
        }

        return $out;
    }

    private static function time(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601ZuluString() : null;
    }
}
