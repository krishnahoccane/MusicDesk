<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\ArtistDocument;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private artist documents. Files are stored under a random name on the
 * non-public `local` disk; the original name is kept only as metadata and is
 * sanitised before being used in a download header.
 */
class DocumentService
{
    public const DISK = 'local';

    /** Extension => accepted MIME types (detected from content, not the browser). */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    public function __construct(protected ActivityLogger $activity) {}

    public static function maxKilobytes(): int
    {
        return (int) config('labeldesk.uploads.document_max_kb', 20480);
    }

    public static function extensions(): string
    {
        return implode(',', array_keys(self::ALLOWED));
    }

    public function store(User $actor, Artist $artist, UploadedFile $file, string $title, string $category, ?int $contractId = null): ArtistDocument
    {
        $extension = Str::lower($file->getClientOriginalExtension());
        $mime = self::detectMime($file);

        if (! isset(self::ALLOWED[$extension]) || ! in_array($mime, self::ALLOWED[$extension], true)) {
            throw new RuntimeException(__('This file type is not allowed.'));
        }

        if ($contractId !== null && ! $artist->contracts()->whereKey($contractId)->exists()) {
            throw new RuntimeException(__('The selected contract does not belong to this artist.'));
        }

        $path = $file->storeAs('artists/'.$artist->id.'/documents', Str::uuid().'.'.$extension, ['disk' => self::DISK]);
        if ($path === false) {
            throw new RuntimeException(__('The file could not be saved. Check that the storage folder is writable.'));
        }

        try {
            $document = DB::transaction(function () use ($artist, $file, $title, $category, $contractId, $path, $mime, $actor) {
                $document = $artist->documents()->make([
                    'title' => trim($title),
                    'category' => $category,
                    'artist_contract_id' => $contractId,
                ]);
                $document->forceFill([
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => self::safeName($file->getClientOriginalName()),
                    'mime_type' => $mime,
                    'size' => (int) $file->getSize(),
                    'uploaded_by' => $actor->id,
                ])->save();

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }

        $this->activity->log('document.uploaded', __('Uploaded ":title" for :artist', ['title' => $document->title, 'artist' => $artist->name]), $document, [
            'file' => $document->original_name, 'size' => $document->size,
        ]);

        return $document;
    }

    public function delete(User $actor, Artist $artist, ArtistDocument $document): void
    {
        abort_unless($document->artist_id === $artist->id, 404);

        $document->delete();
        Storage::disk($document->disk)->delete($document->path);

        $this->activity->log('document.deleted', __('Deleted ":title" from :artist', ['title' => $document->title, 'artist' => $artist->name]), null, [
            'file' => $document->original_name,
        ]);
    }

    public function download(ArtistDocument $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404, __('The file is missing from storage.'));

        return $disk->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * MIME type sniffed from the file's first bytes — never trusted from the
     * browser or inferred from the file name.
     */
    public static function detectMime(UploadedFile $file): string
    {
        // Livewire's temporary uploads expose readStream(); plain uploads a path.
        $stream = method_exists($file, 'readStream') ? $file->readStream() : @fopen($file->getRealPath(), 'rb');
        $head = is_resource($stream) ? (string) fread($stream, 8192) : '';
        if (is_resource($stream)) {
            fclose($stream);
        }

        // Office Open XML / ODF files are ZIP containers, which is why
        // application/zip is accepted for those extensions in ALLOWED.
        return $head === '' ? 'application/x-empty' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
    }

    /**
     * Keeps a readable original name without path parts or control characters.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"<>:|?*\/\\\\]+/u', '_', $name) ?? 'document';
        $name = trim($name, " .\t");

        return Str::limit($name !== '' ? $name : 'document', 180, '');
    }
}
