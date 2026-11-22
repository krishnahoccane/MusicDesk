<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Release;
use App\Models\Track;
use App\Services\Activity\ActivityLogger;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\TrackService;
use App\Services\MediaStorage\MediaSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private catalog files (artwork, master audio) after authorization.
 * Audio responses support HTTP range requests so the browser player can seek.
 */
class CatalogFileController extends Controller
{
    public function artwork(Request $request, Release $release): Response
    {
        $this->authorize('view', $release);

        $path = $request->query('size') === 'full' ? $release->artwork_path : ($release->artwork_thumb_path ?? $release->artwork_path);
        $disk = Storage::disk(ReleaseService::DISK);
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function audio(Track $track): Response
    {
        $this->authorize('downloadMaster', $track);

        return $this->file($track, ResponseHeaderBag::DISPOSITION_INLINE);
    }

    public function download(Track $track, ActivityLogger $activity): Response
    {
        $this->authorize('downloadMaster', $track);

        $response = $this->file($track, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        $activity->log('track.master_downloaded', __('Downloaded the master of ":title"', ['title' => $track->displayTitle()]), $track);

        return $response;
    }

    protected function file(Track $track, string $disposition): Response
    {
        $disk = Storage::disk(TrackService::DISK);
        abort_unless((bool) $track->audio_path, 404);

        $extension = pathinfo($track->audio_path, PATHINFO_EXTENSION);
        $artists = $track->artistLine();
        $name = Str::limit(trim(($artists !== '' ? $artists.' - ' : '').$track->displayTitle()), 150, '');
        $safe = trim(preg_replace('/[\x00-\x1F\x7F"<>:|?*\/\\\\]+/u', '_', $name) ?? 'track', ' .') ?: 'track';

        if (! $disk->exists($track->audio_path)) {
            // Removed from the server after upload: stream it from the cloud drive.
            return $this->fromCloud($track, $disposition, $safe.'.'.$extension, Str::ascii($safe).'.'.$extension);
        }

        $response = new BinaryFileResponse($disk->path($track->audio_path), 200, [
            'Content-Type' => $track->audio_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], false, null, false, true);

        $response->setContentDisposition($disposition, $safe.'.'.$extension, Str::ascii($safe).'.'.$extension);

        return $response;
    }

    /**
     * Proxies the cloud copy (with Range support for the player). The drive's
     * URL and token are never exposed to the browser.
     */
    protected function fromCloud(Track $track, string $disposition, string $filename, string $fallback): StreamedResponse
    {
        $remote = app(MediaSyncService::class)->remoteCopy('audio', $track->release_id, $track->id, $track->audio_path);
        abort_unless($remote !== null, 404);
        [$drive, $record] = $remote;

        $range = request()->header('Range');
        $range = is_string($range) && preg_match('/^bytes=\d*-\d*$/', $range) ? $range : null;
        $upstream = $drive->download((string) $record->remote_id, $range);
        abort_unless(in_array($upstream->status(), [200, 206], true), 502, __('The file could not be fetched from :drive.', ['drive' => $drive->label()]));

        $headers = array_filter([
            'Content-Type' => $track->audio_mime ?: 'application/octet-stream',
            'Content-Length' => $upstream->header('Content-Length') ?: null,
            'Content-Range' => $upstream->header('Content-Range') ?: null,
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, $fallback),
        ]);

        $body = $upstream->toPsrResponse()->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 256);
                flush();
            }
        }, $upstream->status(), $headers);
    }
}
