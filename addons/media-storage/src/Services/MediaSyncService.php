<?php

namespace LabelDesk\Addons\MediaStorage\Services;

use App\Models\Release;
use App\Models\Track;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\TrackService;
use App\Support\Identifiers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LabelDesk\Addons\MediaStorage\Drivers\CloudDrive;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageFile;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageFolder;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageTarget;
use Throwable;

/**
 * Copies master audio and original artwork to every enabled destination:
 *
 *   <chosen folder>/<Release title>_<UPC>/<ISRC>.<ext>   master audio
 *   <chosen folder>/<Release title>_<UPC>/<UPC>.<ext>    original artwork
 *
 * Files are uploaded byte for byte and verified. Renamed titles or codes
 * rename the remote folder and files. A server copy is only removed when
 * that is enabled, after the delay, once EVERY enabled destination holds a
 * verified copy — each is checked again just before the deletion.
 */
class MediaSyncService
{
    public const MAX_ATTEMPTS = 6;

    public function __construct(protected MediaStorageManager $manager) {}

    // ------------------------------------------------------------ naming

    public static function folderName(Release $release): string
    {
        return self::safe(trim($release->title).'_'.(Identifiers::normalizeUpc($release->upc) ?? ''));
    }

    public static function audioName(Track $track): string
    {
        return strtoupper((string) Identifiers::normalizeIsrc($track->isrc)).'.'.strtolower(pathinfo((string) $track->audio_path, PATHINFO_EXTENSION));
    }

    public static function artworkName(Release $release): string
    {
        $path = (string) ($release->artwork_original_path ?: $release->artwork_path);

        return (string) Identifiers::normalizeUpc($release->upc).'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /** Characters not accepted by Windows / OneDrive / Drive / Dropbox / FTP servers are replaced. */
    public static function safe(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"*:<>?\/\\\\|#%]+/u', '-', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        return mb_substr(trim($name, " .\t"), 0, 200) ?: 'release';
    }

    // ------------------------------------------------------------ queueing

    public function queueTrack(Track $track): void
    {
        if (! $track->audio_path) {
            return;
        }
        foreach ($this->manager->activeTargets() as $target) {
            $this->queue($target->id, 'audio', $track->release_id, $track->id);
        }
    }

    public function queueRelease(Release $release, bool $withTracks = false): void
    {
        $targets = $this->manager->activeTargets();
        if ($targets->isEmpty()) {
            return;
        }

        $trackIds = $withTracks ? $release->tracks()->withoutGlobalScopes()->whereNotNull('audio_path')->pluck('id') : collect();
        foreach ($targets as $target) {
            if ($release->artwork_original_path || $release->artwork_path) {
                $this->queue($target->id, 'artwork', $release->id, null);
            }
            foreach ($trackIds as $trackId) {
                $this->queue($target->id, 'audio', $release->id, $trackId);
            }
        }
    }

    /** Every release with artwork or audio, for every enabled destination (or just one). */
    public function queueAll(?MediaStorageTarget $only = null): int
    {
        $targets = $this->manager->activeTargets();
        if ($only) {
            $targets = $targets->where('id', $only->id)->values();
        }
        if ($targets->isEmpty()) {
            return 0;
        }

        $count = 0;
        Release::query()->withoutGlobalScopes()->whereNull('deleted_at')->with(['tracks' => fn ($q) => $q->withoutGlobalScopes()])
            ->chunkById(100, function ($releases) use (&$count, $targets) {
                foreach ($releases as $release) {
                    foreach ($targets as $target) {
                        if ($release->artwork_original_path || $release->artwork_path) {
                            $this->queue($target->id, 'artwork', $release->id, null);
                            $count++;
                        }
                        foreach ($release->tracks->whereNotNull('audio_path') as $track) {
                            $this->queue($target->id, 'audio', $release->id, $track->id);
                            $count++;
                        }
                    }
                }
            });

        return $count;
    }

    protected function queue(int $targetId, string $kind, int $releaseId, ?int $trackId): void
    {
        $record = MediaStorageFile::query()->firstOrNew(['target_id' => $targetId, 'kind' => $kind, 'release_id' => $releaseId, 'track_id' => $trackId]);
        $record->fill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null])->save();
    }

    // ------------------------------------------------------------ running

    /**
     * @return array{processed: int, uploaded: int, failed: int, removed: int}
     */
    public function run(int $budgetSeconds = 240): array
    {
        $stats = ['processed' => 0, 'uploaded' => 0, 'failed' => 0, 'removed' => 0];
        $until = microtime(true) + $budgetSeconds;
        $drives = $this->manager->activeTargets()->mapWithKeys(fn ($t) => [$t->id => $this->manager->driver($t)]);

        if ($drives->isNotEmpty()) {
            $due = MediaStorageFile::query()
                ->whereIn('target_id', $drives->keys())
                ->where(fn ($q) => $q->whereIn('status', ['pending', 'waiting'])
                    ->orWhere(fn ($f) => $f->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)))
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')->limit(500)->get();

            foreach ($due as $record) {
                if (microtime(true) > $until) {
                    break;
                }
                $stats['processed']++;
                $status = $this->process($record, $drives[$record->target_id]);
                $status === 'uploaded' && $stats['uploaded']++;
                $status === 'failed' && $stats['failed']++;
            }
        }

        $stats['removed'] = $this->pruneLocal($until);

        return $stats;
    }

    public function process(MediaStorageFile $record, CloudDrive $drive): string
    {
        $release = Release::query()->withoutGlobalScopes()->find($record->release_id);
        $track = $record->track_id ? Track::query()->withoutGlobalScopes()->find($record->track_id) : null;

        if (! $release || ($record->kind === 'audio' && ! $track)) {
            $record->delete();

            return 'deleted';
        }

        $localPath = $record->kind === 'audio' ? $track->audio_path : ($release->artwork_original_path ?: $release->artwork_path);
        $disk = Storage::disk($record->kind === 'audio' ? TrackService::DISK : ReleaseService::DISK);

        $missing = array_filter([
            Identifiers::normalizeUpc($release->upc) ? null : __('a UPC on the release'),
            $record->kind === 'audio' && ! Identifiers::normalizeIsrc($track->isrc) ? __('an ISRC on the track') : null,
            $localPath ? null : __('the file'),
        ]);
        if ($missing) {
            $record->fill(['status' => 'waiting', 'last_error' => __('Waiting for :items.', ['items' => implode(', ', $missing)]), 'next_attempt_at' => now()->addMinutes(30)])->save();

            return 'waiting';
        }

        try {
            $folderId = $this->ensureFolder($release, $drive);
            $record->refresh();
            $name = $record->kind === 'audio' ? self::audioName($track) : self::artworkName($release);

            if ($record->remote_id && $record->local_path === $localPath) {
                // Same content already there: only the name or folder may have changed.
                $remoteId = $record->remote_id;
                if ($record->remote_name !== $name || $record->remote_folder_id !== $folderId) {
                    $remoteId = $drive->update($record->remote_id, $name, $folderId, $record->remote_folder_id);
                }
                $record->fill(['status' => 'uploaded', 'remote_id' => $remoteId, 'remote_name' => $name, 'remote_folder_id' => $folderId, 'last_error' => null, 'attempts' => 0, 'next_attempt_at' => null])->save();

                return 'uploaded';
            }

            if (! $disk->exists($localPath)) {
                throw new MediaStorageException(__('The file is not on the server any more, so it cannot be uploaded.'));
            }

            $absolute = $disk->path($localPath);
            $mime = $record->kind === 'audio' ? ($track->audio_mime ?: 'application/octet-stream') : (mime_content_type($absolute) ?: 'application/octet-stream');

            $remote = $drive->upload($absolute, $name, $folderId, $mime);

            if (! $drive->verify($absolute, $remote)) {
                rescue(fn () => $drive->trash($remote['id']), report: false);
                throw new MediaStorageException(__('The uploaded copy does not match the original (size or checksum). It was removed and will be uploaded again.'));
            }

            if ($record->remote_id && $record->remote_id !== $remote['id']) {
                rescue(fn () => $drive->trash($record->remote_id), report: false); // replaced file
            }

            $now = now();
            $record->fill([
                'status' => 'uploaded',
                'local_path' => $localPath,
                'remote_id' => $remote['id'],
                'remote_folder_id' => $folderId,
                'remote_name' => $name,
                'size' => (int) filesize($absolute),
                'md5' => md5_file($absolute) ?: null,
                'uploaded_at' => $now,
                'delete_local_at' => $this->manager->localDeleteAt($now),
                'local_deleted_at' => null,
                'attempts' => 0,
                'next_attempt_at' => null,
                'last_error' => null,
            ])->save();

            return 'uploaded';
        } catch (Throwable $e) {
            $attempts = $record->attempts + 1;
            $record->fill([
                'status' => 'failed',
                'attempts' => $attempts,
                'next_attempt_at' => now()->addMinutes(min(360, 5 * 2 ** ($attempts - 1))),
                'last_error' => mb_substr($e instanceof MediaStorageException ? $e->getMessage() : class_basename($e).': '.$e->getMessage(), 0, 500),
            ])->save();

            if (! $e instanceof MediaStorageException) {
                Log::warning('Media storage upload failed', ['file' => $record->id, 'target' => $record->target_id, 'error' => $e->getMessage()]);
            }

            return 'failed';
        }
    }

    protected function ensureFolder(Release $release, CloudDrive $drive): string
    {
        $target = $drive->target();
        $root = (string) $drive->folderId();
        $name = self::folderName($release);
        $folder = MediaStorageFolder::query()->where('target_id', $target->id)->where('release_id', $release->id)->first();

        if ($folder && $folder->remote_parent_id === $root) {
            if ($folder->name !== $name) {
                $old = $folder->remote_id;
                $new = $drive->renameFolder($old, $name, $root);
                $folder->update(['name' => $name, 'remote_id' => $new]);

                // Path-addressed storage (FTP, Dropbox): files moved with the folder.
                if ($new !== $old) {
                    MediaStorageFile::query()->where('target_id', $target->id)->where('remote_folder_id', $old)->get()
                        ->each(fn (MediaStorageFile $f) => $f->update([
                            'remote_folder_id' => $new,
                            'remote_id' => str_starts_with((string) $f->remote_id, $old.'/') ? $new.substr((string) $f->remote_id, strlen($old)) : $f->remote_id,
                        ]));
                }

                return $new;
            }

            return $folder->remote_id;
        }

        $id = $drive->findOrCreateFolder($name, $root);
        MediaStorageFolder::query()->updateOrCreate(
            ['target_id' => $target->id, 'release_id' => $release->id],
            ['remote_id' => $id, 'remote_parent_id' => $root, 'name' => $name],
        );

        return $id;
    }

    /**
     * Remove server copies that are due — only when every enabled destination
     * has a verified copy of the current file.
     */
    public function pruneLocal(?float $until = null): int
    {
        $active = $this->manager->activeTargets()->keyBy('id');
        if ($active->isEmpty()) {
            return 0;
        }

        $removed = 0;
        $candidates = MediaStorageFile::query()
            ->where('status', 'uploaded')->whereNull('local_deleted_at')
            ->whereNotNull('delete_local_at')->where('delete_local_at', '<=', now())
            ->select('kind', 'release_id', 'track_id')->distinct()->limit(200)->get();

        foreach ($candidates as $file) {
            if ($until !== null && microtime(true) > $until) {
                break;
            }

            $current = $file->kind === 'audio'
                ? Track::query()->withoutGlobalScopes()->whereKey($file->track_id)->value('audio_path')
                : (fn ($r) => $r ? ($r->artwork_original_path ?: $r->artwork_path) : null)(Release::query()->withoutGlobalScopes()->whereKey($file->release_id)->first(['id', 'artwork_original_path', 'artwork_path']));
            if (! $current) {
                continue;
            }

            $records = MediaStorageFile::query()->where('kind', $file->kind)->where('release_id', $file->release_id)->where('track_id', $file->track_id)
                ->whereIn('target_id', $active->keys())->get()->keyBy('target_id');

            // Every enabled destination must hold the current file, and be due.
            $ready = $active->keys()->every(fn ($id) => isset($records[$id])
                && $records[$id]->status === 'uploaded' && $records[$id]->local_path === $current
                && $records[$id]->delete_local_at !== null && $records[$id]->delete_local_at->lte(now()));
            if (! $ready) {
                continue;
            }

            $confirmed = true;
            foreach ($records as $targetId => $record) {
                try {
                    $meta = $this->manager->driver($active[$targetId])->metadata((string) $record->remote_id);
                } catch (Throwable) {
                    $confirmed = false; // try again next run

                    break;
                }
                if (! $meta || $meta['size'] !== (int) $record->size) {
                    $record->fill(['status' => 'pending', 'delete_local_at' => null, 'last_error' => __('The copy in :name was missing or changed; uploading again.', ['name' => $active[$targetId]->name])])->save();
                    $confirmed = false;
                }
            }
            if (! $confirmed) {
                continue;
            }

            $disk = Storage::disk($file->kind === 'audio' ? TrackService::DISK : ReleaseService::DISK);
            if ($disk->exists($current)) {
                $disk->delete($current);
            }
            MediaStorageFile::query()->where('kind', $file->kind)->where('release_id', $file->release_id)->where('track_id', $file->track_id)
                ->where('local_path', $current)->update(['local_deleted_at' => now()]);
            $removed++;
        }

        return $removed;
    }

    public function rescheduleLocalDeletion(): void
    {
        MediaStorageFile::query()->where('status', 'uploaded')->whereNull('local_deleted_at')->whereNotNull('uploaded_at')
            ->each(fn (MediaStorageFile $r) => $r->update(['delete_local_at' => $this->manager->localDeleteAt($r->uploaded_at)]));
    }

    /**
     * A copy of a file that is no longer on the server, from any connected destination.
     *
     * @return array{0: CloudDrive, 1: MediaStorageFile}|null
     */
    public function remoteCopy(string $kind, int $releaseId, ?int $trackId, ?string $localPath): ?array
    {
        $records = MediaStorageFile::query()->with('target')
            ->where('kind', $kind)->where('release_id', $releaseId)->where('track_id', $trackId)
            ->where('status', 'uploaded')->where('local_path', $localPath)->whereNotNull('remote_id')
            ->orderByDesc('uploaded_at')->get();

        foreach ($records as $record) {
            if ($record->target && array_key_exists($record->target->driver, MediaStorageManager::DRIVERS)) {
                $drive = $this->manager->driver($record->target);
                if ($drive->isConnected()) {
                    return [$drive, $record];
                }
            }
        }

        return null;
    }

    /** Files whose only copies are in this destination (their server copy was removed). */
    public function onlyIn(MediaStorageTarget $target): int
    {
        return MediaStorageFile::query()->where('target_id', $target->id)->whereNotNull('local_deleted_at')->get()
            ->filter(fn (MediaStorageFile $f) => ! MediaStorageFile::query()->where('id', '!=', $f->id)
                ->where('kind', $f->kind)->where('release_id', $f->release_id)->where('track_id', $f->track_id)
                ->where('local_path', $f->local_path)->where('status', 'uploaded')->exists())
            ->count();
    }
}
