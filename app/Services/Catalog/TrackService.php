<?php

namespace App\Services\Catalog;

use App\Enums\ArtistRole;
use App\Enums\ContributorRole;
use App\Enums\ReleaseType;
use App\Exceptions\CatalogException;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Media\AudioInspector;
use App\Support\Identifiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tracks, their artist credits, contributor credits and master audio.
 */
class TrackService
{
    public const DISK = 'local';

    public function __construct(
        protected ActivityLogger $activity,
        protected AudioInspector $audio,
        protected ReleaseService $releases,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{artist_id: int, role: string}>  $artists
     * @param  list<array{contributor_id: int, role: string, share: ?string}>  $credits
     */
    public function save(User $actor, Release $release, ?Track $track, array $data, array $artists, array $credits): Track
    {
        $this->releases->ensureEditable($release);
        $creating = $track === null;
        abort_if(! $creating && $track->release_id !== $release->id, 404);

        $isrc = Identifiers::normalizeIsrc($data['isrc'] ?? null);
        if ($isrc !== null) {
            $this->ensureIsrcAvailable($release, $track, $isrc);
        }

        $track = DB::transaction(function () use ($actor, $release, $track, $data, $isrc, $artists, $credits) {
            $track ??= $release->tracks()->make();

            $track->fill([
                'title' => trim($data['title']),
                'version' => filled($data['version'] ?? null) ? trim($data['version']) : null,
                'isrc' => $isrc,
                'explicit' => (bool) ($data['explicit'] ?? false),
                'language_id' => $data['language_id'] ?: null,
                'disc_number' => max(1, (int) ($data['disc_number'] ?? 1)),
            ]);

            if (! $track->exists) {
                $track->created_by = $actor->id;
                $track->position = (int) $release->tracks()->where('disc_number', $track->disc_number)->max('position') + 1;
            }

            // Duration comes from the audio file when there is one.
            if (! $track->audio_path && array_key_exists('duration_seconds', $data)) {
                $track->duration_seconds = $data['duration_seconds'] !== null && $data['duration_seconds'] !== '' ? (int) $data['duration_seconds'] : null;
            }

            $track->save();
            $this->syncArtists($track, $artists);
            $this->syncCredits($track, $credits);

            return $track;
        });

        $this->releases->refreshExplicit($release);

        $this->activity->log(
            $creating ? 'track.created' : 'track.updated',
            $creating
                ? __('Added track ":title" to :release', ['title' => $track->displayTitle(), 'release' => $release->displayTitle()])
                : __('Updated track ":title" on :release', ['title' => $track->displayTitle(), 'release' => $release->displayTitle()]),
            $track,
        );

        return $track;
    }

    /**
     * @param  list<array{artist_id: int, role: string}>  $artists
     */
    public function syncArtists(Track $track, array $artists): void
    {
        $rows = [];
        $position = 1;
        foreach ($artists as $row) {
            $id = (int) $row['artist_id'];
            if ($id > 0 && ! isset($rows[$id])) {
                $rows[$id] = ['role' => ArtistRole::from($row['role'])->value, 'position' => $position++];
            }
        }

        if (! collect($rows)->contains(fn ($r) => $r['role'] === ArtistRole::Primary->value)) {
            throw new CatalogException(__('Add at least one primary artist to the track.'));
        }

        $track->artists()->sync($rows);
    }

    /**
     * Replaces the track's contributor credits. Shares of a royalty-bearing
     * role may not add up to more than 100%.
     *
     * @param  list<array{contributor_id: int, role: string, share: ?string}>  $credits
     */
    public function syncCredits(Track $track, array $credits): void
    {
        $rows = [];
        $totals = [];

        foreach ($credits as $credit) {
            $contributorId = (int) $credit['contributor_id'];
            $role = ContributorRole::from($credit['role']);
            if ($contributorId < 1) {
                continue;
            }

            $key = $contributorId.'|'.$role->value;
            if (isset($rows[$key])) {
                throw new CatalogException(__('The same person is credited twice as :role.', ['role' => strtolower($role->label())]));
            }

            $share = $role->hasShare() && filled($credit['share'] ?? null) ? (string) $credit['share'] : null;
            if ($share !== null) {
                $totals[$role->value] = bcadd($totals[$role->value] ?? '0', $share, 4);
            }

            $rows[$key] = ['contributor_id' => $contributorId, 'role' => $role->value, 'share' => $share];
        }

        foreach ($totals as $role => $total) {
            if (bccomp($total, '100', 4) === 1) {
                throw new CatalogException(__(':role shares add up to :total%, which is more than 100%.', [
                    'role' => ContributorRole::from($role)->label(),
                    'total' => rtrim(rtrim($total, '0'), '.'),
                ]));
            }
        }

        $track->credits()->delete();
        foreach ($rows as $row) {
            $track->credits()->create($row);
        }
    }

    /**
     * ISRCs identify one recording. They may repeat only when a compilation
     * re-uses a recording from another release.
     */
    protected function ensureIsrcAvailable(Release $release, ?Track $track, string $isrc): void
    {
        if (! Identifiers::isValidIsrc($isrc)) {
            throw new CatalogException(__('The ISRC must look like CC-XXX-YY-NNNNN (12 characters).'));
        }

        $conflict = Track::query()
            ->with('release:id,title,type,deleted_at')
            ->where('isrc', $isrc)
            ->when($track, fn ($q) => $q->whereKeyNot($track->id))
            ->get()
            ->first(fn (Track $other) => $other->release_id === $release->id
                || ($release->type !== ReleaseType::Compilation && $other->release?->type !== ReleaseType::Compilation));

        if ($conflict) {
            throw new CatalogException(__('ISRC :isrc is already used by ":track" on :release.', [
                'isrc' => $isrc,
                'track' => $conflict->displayTitle(),
                'release' => $conflict->release?->displayTitle() ?? '—',
            ]));
        }
    }

    public function setAudio(User $actor, Track $track, UploadedFile $file): void
    {
        $release = $track->release;
        $this->releases->ensureEditable($release);

        $info = $this->audio->inspect($file->getRealPath());

        $path = 'releases/'.$release->id.'/audio/'.Str::uuid().'.'.$info['format'];
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            Storage::disk(self::DISK)->writeStream($path, $stream) || throw new CatalogException(__('The file could not be saved. Check that the storage folder is writable.'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $old = $track->audio_path;
        $track->forceFill([
            'audio_path' => $path,
            'audio_original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
            'audio_mime' => $info['mime'],
            'audio_size' => (int) $file->getSize(),
            'audio_sample_rate' => $info['sample_rate'],
            'audio_bit_depth' => $info['bit_depth'],
            'audio_channels' => $info['channels'],
            'duration_seconds' => $info['duration'],
        ])->save();

        if ($old && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        $this->activity->log('track.audio_uploaded', __('Uploaded master audio for ":title"', ['title' => $track->displayTitle()]), $track, [
            'format' => $info['format'], 'sample_rate' => $info['sample_rate'], 'bit_depth' => $info['bit_depth'], 'duration' => $info['duration'],
        ]);
    }

    public function delete(User $actor, Track $track): void
    {
        $release = $track->release;
        $this->releases->ensureEditable($release);

        DB::transaction(function () use ($track) {
            $track->delete();
            $this->renumber($track->release_id, $track->disc_number);
        });

        if ($track->audio_path) {
            Storage::disk(self::DISK)->delete($track->audio_path);
        }

        $this->releases->refreshExplicit($release);
        $this->activity->log('track.deleted', __('Deleted track ":title" from :release', ['title' => $track->displayTitle(), 'release' => $release->displayTitle()]), $release);
    }

    /**
     * Moves a track one place up (-1) or down (+1) within its disc.
     */
    public function move(User $actor, Track $track, int $direction): void
    {
        $this->releases->ensureEditable($track->release);

        DB::transaction(function () use ($track, $direction) {
            $this->renumber($track->release_id, $track->disc_number);
            $track->refresh();

            $neighbour = Track::query()
                ->where('release_id', $track->release_id)
                ->where('disc_number', $track->disc_number)
                ->where('position', $track->position + ($direction < 0 ? -1 : 1))
                ->first();

            if ($neighbour) {
                [$a, $b] = [$track->position, $neighbour->position];
                $track->forceFill(['position' => $b])->save();
                $neighbour->forceFill(['position' => $a])->save();
            }
        });
    }

    /**
     * Makes positions on a disc consecutive (1, 2, 3 …).
     */
    protected function renumber(int $releaseId, int $disc): void
    {
        $ids = Track::query()->where('release_id', $releaseId)->where('disc_number', $disc)
            ->orderBy('position')->orderBy('id')->pluck('id');

        foreach ($ids as $i => $id) {
            Track::query()->whereKey($id)->update(['position' => $i + 1]);
        }
    }
}
