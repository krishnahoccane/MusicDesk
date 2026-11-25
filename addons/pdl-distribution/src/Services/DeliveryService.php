<?php

namespace LabelDesk\Addons\PdlDistribution\Services;

use App\Models\Release;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use LabelDesk\Addons\PdlDistribution\Models\PdlDelivery;
use Throwable;

/**
 * Sends releases to PDL and follows them there.
 *
 *   queued ──add/meta──► uploading ──PUT presigned URLs──► verifying ──verify/meta──► submitting ──pdl/submit──► done
 *   (update) updating ──album/media/update (+ new artwork)──► submitting
 *
 * Each step is saved before the next starts, so an interrupted delivery
 * resumes where it stopped (uploads already done are not repeated).
 * Retryable failures (network, HTTP 5xx/429, COSMOS "System Error") wait
 * 1, 5, 15, 60, 180 minutes; after that, or on an error PDL reports about
 * the content, the delivery stops as "Needs attention" with PDL's message.
 * Only a successful pdl/submit answer marks it submitted; live / released
 * come only from PDL's album status.
 */
class DeliveryService
{
    public const BACKOFF_MINUTES = [1, 5, 15, 60, 180];

    public const SYNC_EVERY_MINUTES = 60;

    /** Live albums: checked once a day (store errors, takedowns). */
    public const SYNC_LIVE_EVERY_MINUTES = 1440;

    protected const WORK_STAGES = ['queued', 'metadata', 'uploading', 'verifying', 'submitting', 'updating'];

    public function __construct(
        protected PdlSettings $settings,
        protected CosmosClient $client,
        protected PayloadBuilder $builder,
        protected ActivityLogger $activity,
    ) {}

    // ------------------------------------------------------------ requests from the UI

    /**
     * Queue a release (first delivery, or an update of an album PDL has not
     * accepted yet / reported an issue on). Work starts after the response.
     *
     * @param  array{platforms: string, allow_any_go_live_date: bool, mood: string}  $options
     */
    public function queue(Release $release, User $user, array $options): PdlDelivery
    {
        if (! $this->settings->isEnabled()) {
            throw new DeliveryException(__('PDL distribution is not set up or is disabled (Settings → PDL distribution).'));
        }
        if (! array_key_exists($options['platforms'], Cosmos::PLATFORMS)) {
            throw new DeliveryException(__('Choose where PDL should release it.'));
        }

        $delivery = PdlDelivery::query()->where('release_id', $release->id)->first();
        if ($delivery?->isInProgress()) {
            throw new DeliveryException(__('This release is already being sent to PDL.'));
        }
        if ($delivery && $delivery->isSubmitted() && ! in_array($delivery->status, ['issue_reported', 'failed'], true)) {
            throw new DeliveryException(__('PDL has this release for review or release. The COSMOS API does not accept changes after submission (yet); contact PDL for changes.'));
        }

        $check = $this->builder->check($release, $options['mood']);
        if ($check['problems'] !== []) {
            throw new DeliveryException(__('Fix these first:'), $check['problems']);
        }

        $update = $delivery !== null && $delivery->pdl_album_id !== null;
        $delivery ??= new PdlDelivery(['release_id' => $release->id]);
        $delivery->fill([
            'action' => $update ? 'update' : 'new',
            'stage' => $update ? 'updating' : 'queued',
            'status' => 'queued',
            'platforms' => $options['platforms'],
            'allow_any_go_live_date' => (bool) $options['allow_any_go_live_date'],
            'mood' => $options['mood'],
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
            'requested_by' => $user->id,
            'queued_at' => now(),
        ]);
        if (! $update) {
            $delivery->ingest = null; // a new ingestion: new token and upload links
        }
        $delivery->save();

        $delivery->note($update ? __('Update queued by :user.', ['user' => $user->name]) : __('Queued by :user.', ['user' => $user->name]), 'info', $user->id);
        $this->activity->log('pdl.queued', ($update ? __('Queued an update of :title for PDL', ['title' => $release->displayTitle()]) : __('Queued :title for PDL', ['title' => $release->displayTitle()])), $release, [
            'platforms' => $options['platforms'],
        ], $user);

        $this->startAfterResponse($delivery->id);

        return $delivery;
    }

    /** Resume a delivery that stopped ("Needs attention") at the step where it stopped. */
    public function retry(PdlDelivery $delivery, User $user): void
    {
        if ($delivery->status !== 'failed') {
            return;
        }
        if (! $this->settings->isEnabled()) {
            throw new DeliveryException(__('PDL distribution is not set up or is disabled (Settings → PDL distribution).'));
        }
        if ($delivery->stage === 'done') {
            // Failed after submission (e.g. an update): go back to the update step.
            $delivery->stage = $delivery->pdl_album_id ? 'updating' : 'queued';
        }
        $delivery->fill(['status' => $delivery->pdl_album_id ? 'ingested' : 'sending', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null])->save();
        $delivery->note(__('Retry requested by :user.', ['user' => $user->name]), 'info', $user->id);
        $this->activity->log('pdl.retried', __('Retried the PDL delivery of :title', ['title' => $delivery->release?->displayTitle()]), $delivery->release, [], $user);

        $this->startAfterResponse($delivery->id);
    }

    protected function startAfterResponse(int $id): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        app()->terminating(function () use ($id) {
            @ignore_user_abort(true);
            @set_time_limit(600);
            rescue(fn () => $this->process($id, 540), null, false);
        });
    }

    // ------------------------------------------------------------ background work

    /**
     * Advance due deliveries, then sync the status of sent albums.
     *
     * @return array{processed: int, synced: int}
     */
    public function run(int $seconds = 240): array
    {
        $stats = ['processed' => 0, 'synced' => 0];
        if (! $this->settings->isEnabled()) {
            return $stats;
        }
        $deadline = microtime(true) + $seconds;

        $due = PdlDelivery::query()
            ->whereIn('stage', self::WORK_STAGES)->where('status', '!=', 'failed')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(20)->pluck('id');
        foreach ($due as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $this->process((int) $id, (int) max(1, $deadline - microtime(true)));
            $stats['processed']++;
        }

        $toSync = PdlDelivery::query()
            ->where('stage', 'done')->whereNotNull('pdl_album_id')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('status', PdlDelivery::SYNCED)->where(fn ($t) => $t->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subMinutes(self::SYNC_EVERY_MINUTES))))
                ->orWhere(fn ($w) => $w->where('status', 'live')->where('last_synced_at', '<=', now()->subMinutes(self::SYNC_LIVE_EVERY_MINUTES))))
            ->orderBy('last_synced_at')->limit(10)->get();
        foreach ($toSync as $delivery) {
            if (microtime(true) >= $deadline) {
                break;
            }
            rescue(fn () => $this->syncStatus($delivery), null, false);
            $stats['synced']++;
        }

        return $stats;
    }

    /** Run the steps of one delivery until it is done, waiting, failed or out of time. */
    public function process(int $id, int $seconds = 240): void
    {
        $lock = Cache::lock('pdl:delivery:'.$id, max(60, $seconds + 60));
        if (! $lock->get()) {
            return; // another run is on it
        }

        try {
            $delivery = PdlDelivery::query()->find($id);
            if (! $delivery || ! in_array($delivery->stage, self::WORK_STAGES, true) || $delivery->status === 'failed') {
                return;
            }
            $deadline = microtime(true) + $seconds;

            for ($i = 0; $i < 8 && in_array($delivery->stage, self::WORK_STAGES, true); $i++) {
                $finished = match ($delivery->stage) {
                    'queued', 'metadata' => $this->stepMetadata($delivery),
                    'uploading' => $this->stepUpload($delivery, $deadline),
                    'verifying' => $this->stepVerify($delivery),
                    'submitting' => $this->stepSubmit($delivery),
                    'updating' => $this->stepUpdate($delivery),
                };
                if (! $finished) {
                    return; // out of time: continues on the next run
                }
                $delivery->forceFill(['attempts' => 0, 'next_attempt_at' => null, 'last_error' => null])->save();
            }
        } catch (Throwable $e) {
            if (isset($delivery) && $delivery instanceof PdlDelivery) {
                $this->failed($delivery, $e);
            }
        } finally {
            $lock->release();
        }
    }

    protected function stepMetadata(PdlDelivery $delivery): bool
    {
        $release = $this->releaseOf($delivery);
        $check = $this->builder->check($release, $delivery->mood);
        if ($check['problems'] !== []) {
            throw new CosmosException(__('Fix these first:').' '.implode(' ', $check['problems']));
        }

        $delivery->forceFill(['stage' => 'metadata', 'status' => 'sending'])->save();
        $built = $this->builder->build($release, (string) $delivery->mood);
        $data = $this->client->addMeta($built['body']);

        $token = $data['token'] ?? null;
        $signed = data_get($data, 'signed_albums.0');
        if (! is_string($token) || $token === '' || ! is_array($signed)) {
            throw new CosmosException(__('PDL accepted the metadata but did not return an ingestion token and upload links.'));
        }

        $songUrls = [];
        foreach ((array) ($signed['songs'] ?? []) as $song) {
            $isrc = strtoupper((string) ($song['isrc'] ?? data_get($song, 'data.isrc', '')));
            $url = data_get($song, 'media.signed_url');
            if ($isrc !== '' && is_string($url)) {
                $songUrls[$isrc] = $url;
            }
        }

        $uploads = [];
        foreach ($built['files'] as $file) {
            $url = $file['kind'] === 'artwork' ? data_get($signed, 'inlay.signed_url') : ($songUrls[$file['isrc']] ?? null);
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                throw new CosmosException($file['kind'] === 'artwork'
                    ? __('PDL did not return an upload link for the artwork.')
                    : __('PDL did not return an upload link for ISRC :isrc.', ['isrc' => $file['isrc']]));
            }
            $uploads[$file['key']] = ['url' => $url, 'path' => $file['path'], 'filename' => $file['filename'], 'size' => $file['size'], 'md5' => $file['md5'], 'done' => false];
        }

        $delivery->forceFill([
            'ingest' => ['token' => $token, 'uploads' => $uploads],
            'payload' => json_encode($built['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'stage' => 'uploading',
        ])->save();
        $delivery->note(__('PDL accepted the metadata. Uploading :count files.', ['count' => count($uploads)]), 'success');
        foreach ((array) ($signed['warnings'] ?? []) as $warning) {
            if (is_string($warning) && $warning !== '') {
                $delivery->note(__('PDL warning: :message', ['message' => $warning]), 'warning');
            }
        }

        return true;
    }

    protected function stepUpload(PdlDelivery $delivery, float $deadline): bool
    {
        $ingest = (array) $delivery->ingest;
        foreach ((array) ($ingest['uploads'] ?? []) as $key => $file) {
            if ($file['done'] ?? false) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            clearstatcache(true, $file['path']);
            if (! is_file($file['path'])) {
                throw new CosmosException(__('The file :name is no longer on this server. Send the release again.', ['name' => $file['filename']]));
            }
            if (filesize($file['path']) !== (int) $file['size']) {
                throw new CosmosException(__('The file :name changed after its details were sent to PDL. Send the release again.', ['name' => $file['filename']]));
            }

            $this->client->upload($file['url'], $file['path'], (int) $file['size'], (string) $file['md5']);

            $ingest['uploads'][$key]['done'] = true;
            $delivery->forceFill(['ingest' => $ingest])->save();
            $delivery->note(__('Uploaded :name (:size).', ['name' => $file['filename'], 'size' => Number::fileSize((int) $file['size'], 1)]));
        }

        $delivery->forceFill(['stage' => 'verifying'])->save();

        return true;
    }

    protected function stepVerify(PdlDelivery $delivery): bool
    {
        $token = (string) data_get($delivery->ingest, 'token', '');
        if ($token === '') {
            throw new CosmosException(__('The ingestion token is missing. Send the release again.'));
        }

        $data = $this->client->verify($token, (string) $this->settings->get('label_id'));
        $albumId = CosmosClient::albumIdIn($data) ?? $this->findAlbumId($delivery);
        if ($albumId === null) {
            throw new CosmosException(__('PDL verified the upload but its album id could not be found yet. It will be looked up again.'), true);
        }

        $delivery->forceFill(['pdl_album_id' => $albumId, 'status' => 'ingested', 'stage' => 'submitting'])->save();
        $delivery->note(__('PDL verified the files and created album :id.', ['id' => $albumId]), 'success');

        return true;
    }

    protected function stepSubmit(PdlDelivery $delivery): bool
    {
        $data = $this->client->submit((string) $delivery->pdl_album_id, $delivery->platforms, $delivery->allow_any_go_live_date);

        $upc = $data['upc'] ?? $data['nue_meta_upc'] ?? data_get($data, 'album.upc') ?? $data['value'] ?? null;
        $delivery->forceFill([
            'stage' => 'done',
            'status' => 'submitted',
            'submitted_at' => now(),
            'issue' => null,
            'pdl_upc' => is_scalar($upc) && preg_match('/^\d{12,14}$/', (string) $upc) ? (string) $upc : $delivery->pdl_upc,
            'last_synced_at' => now(), // first status check an hour later (or "Check status")
        ])->save();

        $platforms = __(Cosmos::PLATFORMS[$delivery->platforms] ?? $delivery->platforms);
        $delivery->note(__('Submitted to PDL for release (:platforms). PDL will review it.', ['platforms' => $platforms]), 'success');
        $this->activity->log('pdl.submitted', __('Submitted :title to PDL', ['title' => $delivery->release?->displayTitle()]), $delivery->release, [
            'pdl_album_id' => $delivery->pdl_album_id, 'platforms' => $delivery->platforms,
        ], $delivery->requester);

        return true;
    }

    /** Update an album PDL holds but has not accepted (or reported an issue on), then (re)submit. */
    protected function stepUpdate(PdlDelivery $delivery): bool
    {
        $release = $this->releaseOf($delivery);
        $check = $this->builder->check($release, $delivery->mood);
        if ($check['problems'] !== []) {
            throw new CosmosException(__('Fix these first:').' '.implode(' ', $check['problems']));
        }
        $delivery->forceFill(['status' => 'sending'])->save();

        $isrc = $this->firstIsrc($delivery, $release);
        $remote = $isrc ? $this->client->album((string) $delivery->pdl_album_id, $isrc) : null;
        if ($remote === null) {
            throw new CosmosException(__('Album :id was not found at PDL.', ['id' => $delivery->pdl_album_id]));
        }

        $built = $this->builder->build($release, (string) $delivery->mood, true);
        $sent = json_decode((string) $delivery->payload, true) ?: [];

        // New artwork: PDL overwrites the stored file through a fresh presigned URL.
        $artwork = collect($built['files'])->firstWhere('kind', 'artwork');
        if ($artwork && $artwork['md5'] !== data_get($sent, 'albums.0.inlay.md5')) {
            $url = $this->client->replacementUrl((string) $delivery->pdl_album_id, 'Artwork', (int) $artwork['size']);
            $this->client->upload($url, $artwork['path'], (int) $artwork['size'], $artwork['md5']);
            $delivery->note(__('Uploaded new artwork.'), 'success');
        }

        // Replacing masters: the guide does not name the media type for audio.
        $oldAudio = collect((array) data_get($sent, 'albums.0.songs', []))->mapWithKeys(fn ($s) => [$s['isrc'] ?? '' => data_get($s, 'media.md5')]);
        foreach ($built['files'] as $file) {
            if ($file['kind'] === 'audio' && $oldAudio->has($file['isrc']) && $oldAudio[$file['isrc']] !== $file['md5']) {
                $delivery->note(__('The master of :isrc changed, but the COSMOS API has no documented way to replace audio. Ask PDL to replace it.', ['isrc' => $file['isrc']]), 'warning');
            }
        }

        $this->client->updateAlbum($this->builder->mergeForUpdate($remote, $release, (string) $delivery->mood));

        $sentAudio = collect((array) data_get($sent, 'albums.0.songs', []))->keyBy('isrc');
        $body = $built['body'];
        foreach ($body['albums'][0]['songs'] as $i => $song) {
            if ($sentAudio->has($song['isrc'])) {
                $body['albums'][0]['songs'][$i]['media'] = data_get($sentAudio[$song['isrc']], 'media', $song['media']); // what PDL still holds
            }
        }

        $delivery->forceFill([
            'payload' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'stage' => 'submitting',
            'status' => 'ingested',
        ])->save();
        $delivery->note(__('PDL accepted the updated metadata.'), 'success');

        return true;
    }

    protected function failed(PdlDelivery $delivery, Throwable $e): void
    {
        $message = CosmosClient::safe($e);
        $retryable = ! $e instanceof CosmosException || $e->retryable;
        $attempts = $delivery->attempts + 1;

        if ($retryable && $attempts <= count(self::BACKOFF_MINUTES)) {
            $next = now()->addMinutes(self::BACKOFF_MINUTES[$attempts - 1]);
            $delivery->forceFill(['attempts' => $attempts, 'next_attempt_at' => $next, 'last_error' => $message])->save();
            $delivery->note(__(':message Next try at :time.', ['message' => $message, 'time' => $next->format('H:i')]), 'warning');

            return;
        }

        $delivery->forceFill(['attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => $message, 'status' => 'failed'])->save();
        $delivery->note($message, 'danger');
        $this->activity->log('pdl.failed', __('PDL delivery of :title stopped: :message', ['title' => $delivery->release?->displayTitle(), 'message' => $message]), $delivery->release, [], $delivery->requester);
    }

    // ------------------------------------------------------------ status

    /** Read the album's review and release status from PDL. */
    public function syncStatus(PdlDelivery $delivery): void
    {
        $release = $this->releaseOf($delivery);
        $isrc = $this->firstIsrc($delivery, $release);
        if (! $delivery->pdl_album_id || ! $isrc) {
            return;
        }

        $album = $this->client->album($delivery->pdl_album_id, $isrc);
        if ($album === null) {
            $delivery->forceFill(['last_synced_at' => now()])->save();
            $delivery->note(__('Album :id was not found in PDL\'s album list.', ['id' => $delivery->pdl_album_id]), 'warning');

            return;
        }

        $flag = fn (string $key) => filter_var($album[$key] ?? false, FILTER_VALIDATE_BOOL);
        $submitted = $flag('submitted');
        $verified = $flag('verified');
        $issueFlag = $flag('reportissue_flag');
        $releases = collect((array) ($album['releases'] ?? []))->filter(fn ($r) => is_array($r))->map(fn (array $r) => [
            'platform_id' => (string) ($r['platform_id'] ?? ''),
            'release_status' => (int) ($r['release_status'] ?? 0),
            'live_link' => (string) ($r['live_link'] ?? ''),
            'release_error' => (string) ($r['release_error'] ?? ''),
        ])->values();
        $codes = $releases->pluck('release_status');

        $status = match (true) {
            $codes->intersect([2, 5, 8])->isNotEmpty() => 'live',
            $codes->contains(9) => 'release_error',
            $codes->isNotEmpty() && $codes->diff([3, 4, 6])->isEmpty() && $codes->intersect([3, 4, 6])->isNotEmpty() => 'taken_down',
            $codes->intersect([1, 7])->isNotEmpty() => 'released',
            $verified => 'approved',
            $issueFlag && ! $submitted => 'issue_reported',
            $issueFlag && $submitted => 'in_review',
            $submitted => 'submitted',
            default => $delivery->isSubmitted() ? 'submitted' : 'ingested',
        };

        $issue = $delivery->issue;
        if ($status === 'issue_reported') {
            $issues = $this->client->reportedIssues($delivery->pdl_album_id);
            $issue = $issues !== [] ? implode("\n\n", $issues) : __('PDL reported an issue without details. Check the album in COSMOS.');
        } elseif ($status === 'release_error') {
            $issue = $releases->where('release_status', 9)->pluck('release_error')->filter()->unique()->implode("\n") ?: null;
        } elseif (in_array($status, ['approved', 'released', 'live'], true)) {
            $issue = null;
        }

        $upc = $album['upc'] ?? $album['nue_meta_upc'] ?? null;
        $previous = $delivery->status;
        $delivery->forceFill([
            'status' => $status,
            'issue' => $issue,
            'pdl_state' => ['submitted' => $submitted, 'verified' => $verified, 'reportissue_flag' => $issueFlag, 'releases' => $releases->all()],
            'pdl_upc' => is_scalar($upc) && preg_match('/^\d{12,14}$/', (string) $upc) ? (string) $upc : $delivery->pdl_upc,
            'live_at' => $status === 'live' ? ($delivery->live_at ?? now()) : $delivery->live_at,
            'last_synced_at' => now(),
        ])->save();

        if ($previous !== $status) {
            $label = $delivery->statusLabel();
            $delivery->note(__('PDL status: :status.', ['status' => $label]).($status === 'issue_reported' && $issue ? ' '.$issue : ''), match ($status) {
                'live', 'approved', 'released' => 'success', 'issue_reported', 'release_error' => 'danger', default => 'info',
            });
            $this->activity->log('pdl.status_changed', __('PDL status of :title: :status', ['title' => $release->displayTitle(), 'status' => $label]), $release, [
                'from' => $previous, 'to' => $status,
            ]);
        }
    }

    // ------------------------------------------------------------ helpers

    protected function releaseOf(PdlDelivery $delivery): Release
    {
        $release = Release::query()->withoutGlobalScopes()->withTrashed()->find($delivery->release_id);

        return $release ?? throw new CosmosException(__('The release no longer exists.'));
    }

    protected function firstIsrc(PdlDelivery $delivery, Release $release): ?string
    {
        $sent = json_decode((string) $delivery->payload, true);
        $isrc = data_get($sent, 'albums.0.songs.0.isrc');
        if (is_string($isrc) && $isrc !== '') {
            return $isrc;
        }
        $track = $release->tracks()->withoutGlobalScopes()->whereNotNull('isrc')->first();

        return $track ? $this->builder->isrc($track) : null;
    }

    protected function findAlbumId(PdlDelivery $delivery): ?string
    {
        $release = $this->releaseOf($delivery);
        $isrc = $this->firstIsrc($delivery, $release);
        if (! $isrc) {
            return null;
        }
        $name = $release->title;
        foreach ($this->client->albumsByIsrc($isrc) as $album) {
            $id = (string) ($album['id'] ?? $album['_id'] ?? '');
            if ($id !== '' && (! isset($album['name']) || $album['name'] === $name)) {
                return $id;
            }
        }

        return null;
    }
}
