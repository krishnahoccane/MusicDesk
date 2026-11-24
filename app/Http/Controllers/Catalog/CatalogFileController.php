<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Release;
use App\Models\Track;
use App\Services\Activity\ActivityLogger;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\TrackService;
use App\Plugins\PluginRegistry;
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
            // Not on the server any more (e.g. moved to cloud storage by an add-on): ask the add-ons.
            foreach (app(PluginRegistry::class)->fileSources as $source) {
                $response = ($source['resolver'])($track, $disposition, $safe.'.'.$extension, Str::ascii($safe).'.'.$extension);
                if ($response instanceof Response) {
                    return $response;
                }
            }
            abort(404, __('This file is not on the server. If it was moved to cloud storage, the Media storage add-on must be active to fetch it.'));
        }

        $response = new BinaryFileResponse($disk->path($track->audio_path), 200, [
            'Content-Type' => $track->audio_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], false, null, false, true);

        $response->setContentDisposition($disposition, $safe.'.'.$extension, Str::ascii($safe).'.'.$extension);

        return $response;
    }
}
