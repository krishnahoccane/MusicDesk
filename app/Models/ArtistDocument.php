<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private file belonging to an artist (optionally to one of their
 * contracts). Files are stored on a non-public disk and only reach the
 * browser through the authorised download route.
 */
class ArtistDocument extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'category', 'artist_contract_id'];

    /** Storage location is never exposed. */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'size' => 'integer',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class)->withTrashed();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ArtistContract::class, 'artist_contract_id')->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function humanSize(): string
    {
        return Format::bytes($this->size);
    }

    public function icon(): string
    {
        return match (true) {
            str_starts_with($this->mime_type, 'image/') => 'file-image',
            str_contains($this->mime_type, 'sheet') || str_contains($this->mime_type, 'excel') || $this->mime_type === 'text/csv' => 'file-spreadsheet',
            default => 'file-text',
        };
    }
}
