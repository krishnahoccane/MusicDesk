<?php

namespace LabelDesk\Addons\MediaStorage\Http;

use App\Models\Track;
use LabelDesk\Addons\MediaStorage\Services\MediaSyncService;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File source for core's track play / download (PluginRegistry::fileSource()):
 * when a master was removed from the server, proxy the copy from a connected
 * destination, with Range support for the player. The drive's URL and token
 * are never exposed to the browser.
 */
class CloudStream
{
    public function __construct(protected MediaSyncService $sync) {}

    public function __invoke(Track $track, string $disposition, string $filename, string $fallback): ?StreamedResponse
    {
        $remote = $this->sync->remoteCopy('audio', $track->release_id, $track->id, $track->audio_path);
        if ($remote === null) {
            return null;
        }
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
