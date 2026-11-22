<?php

namespace App\Http\Controllers\Artists;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\ArtistDocument;
use App\Services\Activity\ActivityLogger;
use App\Services\ArtistService;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private artist files. Nothing under storage/app/private is ever
 * reachable directly; every request passes authentication, permission and
 * license middleware plus the checks below.
 */
class ArtistFileController extends Controller
{
    public function photo(Artist $artist): Response
    {
        $this->authorize('view', $artist);

        $disk = Storage::disk(ArtistService::DISK);
        abort_unless($artist->photo_path && $disk->exists($artist->photo_path), 404);

        return $disk->response($artist->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function document(Artist $artist, ArtistDocument $document, DocumentService $documents, ActivityLogger $activity): StreamedResponse
    {
        $this->authorize('view', $artist);
        abort_unless($document->artist_id === $artist->id, 404);

        $response = $documents->download($document);

        $activity->log('document.downloaded', __('Downloaded ":title" of :artist', ['title' => $document->title, 'artist' => $artist->name]), $document);

        return $response;
    }
}
