<?php

namespace App\Services\Catalog;

use App\Enums\ArtistRole;
use App\Enums\ReleaseType;
use App\Exceptions\CatalogException;
use App\Models\Release;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Media\ImageProcessor;
use App\Support\ArtistAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Release records, credited artists and artwork. Content changes are only
 * allowed while the release is editable (draft or changes requested).
 */
class ReleaseService
{
    public const DISK = 'local';

    public function __construct(
        protected ActivityLogger $activity,
        protected ImageProcessor $images,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{artist_id: int, role: string}>  $artists
     */
    public function create(User $actor, array $data, array $artists, bool $draft = false): Release
    {
        $release = DB::transaction(function () use ($actor, $data, $artists, $draft) {
            $release = new Release;
            $release->fill($this->clean($data));
            $release->created_by = $actor->id;
            $release->save();

            $this->syncArtists($release, $artists, $draft);

            return $release;
        });

        $this->activity->log('release.created', __('Created release :title', ['title' => $release->displayTitle()]), $release);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{artist_id: int, role: string}>  $artists
     */
    public function update(User $actor, Release $release, array $data, array $artists, bool $draft = false): Release
    {
        $this->ensureEditable($release);

        $changes = DB::transaction(function () use ($release, $data, $artists, $draft) {
            $release->fill($this->clean($data));
            $dirty = array_keys($release->getDirty());
            $release->save();

            // A single's track carries the release title.
            if ($release->type === ReleaseType::Single && array_intersect(['title', 'type'], $dirty) !== []) {
                $release->tracks()->withoutGlobalScopes()->get()->each(fn ($t) => $t->forceFill(['title' => $release->title])->save());
            }

            $before = $release->artists()->pluck('artists.id')->all();
            $this->syncArtists($release, $artists, $draft);
            $after = $release->artists()->pluck('artists.id')->all();

            return array_filter([
                'fields' => $dirty ?: null,
                'artists' => $before !== $after ? 'changed' : null,
            ]);
        });

        if ($changes) {
            $this->activity->log('release.updated', __('Updated release :title', ['title' => $release->displayTitle()]), $release, $changes);
        }

        return $release;
    }

    /**
     * @param  list<array{artist_id: int, role: string}>  $artists
     */
    /** Drafts may be saved before a primary artist is chosen; submitting still needs one (checklist). */
    public function syncArtists(Release $release, array $artists, bool $draft = false): void
    {
        $rows = [];
        $position = 1;
        foreach ($artists as $row) {
            $id = (int) $row['artist_id'];
            if ($id > 0 && ! isset($rows[$id])) {
                $rows[$id] = ['role' => ArtistRole::from($row['role'])->value, 'position' => $position++];
            }
        }

        ArtistAccess::ensureOwned(array_keys($rows));

        if (! $draft && ! collect($rows)->contains(fn ($r) => $r['role'] === ArtistRole::Primary->value)) {
            throw new CatalogException(__('Add at least one primary artist.'));
        }

        $release->artists()->sync($rows);
    }

    public function setArtwork(User $actor, Release $release, UploadedFile $file): void
    {
        $this->ensureEditable($release);

        $minPx = (int) config('labeldesk.catalog.artwork_min_px', 3000);
        [$width, $height] = $this->images->dimensions($file);
        if ($width !== $height) {
            throw new CatalogException(__('Artwork must be square (this image is :w × :h px).', ['w' => $width, 'h' => $height]));
        }
        if ($width < $minPx) {
            throw new CatalogException(__('Artwork must be at least :px × :px px.', ['px' => $minPx]));
        }

        $base = 'releases/'.$release->id.'/artwork-'.Str::uuid();
        $full = $this->images->toJpeg($file, min($width, 4000), 92);
        $thumb = $this->images->toJpeg($file, 600, 85);

        $disk = Storage::disk(self::DISK);
        $disk->put($base.'.jpg', $full);
        $disk->put($base.'-thumb.jpg', $thumb);

        // Keep the file exactly as uploaded (for cloud storage and delivery);
        // the re-encoded copy above is what the app displays.
        $original = $base.'-original.'.$this->originalExtension($file);
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $disk->writeStream($original, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        $old = [$release->artwork_path, $release->artwork_thumb_path, $release->artwork_original_path];
        $release->forceFill(['artwork_path' => $base.'.jpg', 'artwork_thumb_path' => $base.'-thumb.jpg', 'artwork_original_path' => $original])->save();
        $disk->delete(array_filter($old));

        $this->activity->log('release.artwork_updated', __('Updated artwork of :title', ['title' => $release->displayTitle()]), $release, [
            'size' => $width.'x'.$height,
        ]);
    }

    /**
     * The original file's extension, from its real image type (the client's
     * spelling is kept for JPEG: .jpg or .jpeg).
     */
    protected function originalExtension(UploadedFile $file): string
    {
        $type = @getimagesize($file->getRealPath())[2] ?? null;
        $client = strtolower($file->getClientOriginalExtension());

        return match ($type) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => in_array($client, ['jpg', 'jpeg'], true) ? $client : 'jpg',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM => 'tif',
            default => 'jpg',
        };
    }

    public function removeArtwork(User $actor, Release $release): void
    {
        $this->ensureEditable($release);

        if (! $release->artwork_path) {
            return;
        }

        Storage::disk(self::DISK)->delete(array_filter([$release->artwork_path, $release->artwork_thumb_path, $release->artwork_original_path]));
        $release->forceFill(['artwork_path' => null, 'artwork_thumb_path' => null, 'artwork_original_path' => null])->save();

        $this->activity->log('release.artwork_updated', __('Removed artwork of :title', ['title' => $release->displayTitle()]), $release);
    }

    public function delete(User $actor, Release $release): void
    {
        $this->ensureEditable($release);

        // Soft delete keeps tracks, audio, history and credits for restore.
        $release->delete();
        $this->activity->log('release.deleted', __('Deleted release :title', ['title' => $release->displayTitle()]), $release);
    }

    public function restore(User $actor, Release $release): void
    {
        $release->restore();
        $this->activity->log('release.restored', __('Restored release :title', ['title' => $release->displayTitle()]), $release);
    }

    /**
     * Explicit flag follows the tracks: a release is explicit if any track is.
     */
    public function refreshExplicit(Release $release): void
    {
        $explicit = $release->tracks()->where('explicit', true)->exists();
        if ($release->explicit !== $explicit) {
            $release->forceFill(['explicit' => $explicit])->saveQuietly();
        }
    }

    public function ensureEditable(Release $release): void
    {
        if (! $release->isEditable()) {
            throw new CatalogException(__('This release is :status and cannot be changed. Reopen it as a draft first.', ['status' => strtolower($release->status->label())]));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clean(array $data): array
    {
        $out = [];
        foreach (['title', 'version', 'type', 'upc', 'catalog_number', 'label_name', 'c_line', 'p_line', 'release_date', 'original_release_date', 'genre_id', 'language_id', 'notes', 'label_id'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
            $out[$key] = $value === '' ? null : $value;
        }

        return $out;
    }
}
