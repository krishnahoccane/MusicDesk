<?php

namespace App\Livewire\Concerns;

use App\Enums\ArtistRole;
use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Models\Artist;
use App\Services\ArtistService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Editable list of credited artists (artist + role, ordered) for release and
 * track forms. Rendered by livewire/catalog/partials/artist-credits.
 */
trait ManagesArtistCredits
{
    /** @var list<array{artist_id: int|string, role: string}> */
    public array $artistRows = [];

    public function addArtistRow(): void
    {
        $this->artistRows[] = ['artist_id' => '', 'role' => $this->artistRows === [] ? ArtistRole::Primary->value : ArtistRole::Featuring->value];
    }

    public function removeArtistRow(int $index): void
    {
        unset($this->artistRows[$index]);
        $this->artistRows = array_values($this->artistRows);
    }

    /** Quick "New artist" form in the Artists card (the release or track being edited stays open). */
    public bool $showNewArtist = false;

    public string $newArtistName = '';

    public string $newArtistType = 'solo';

    public function createArtist(ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->authorize('create', Artist::class);

        $this->validate([
            'newArtistName' => ['required', 'string', 'max:150'],
            'newArtistType' => ['required', Rule::enum(ArtistType::class)],
        ], [], ['newArtistName' => __('artist name'), 'newArtistType' => __('type')]);

        $artist = $artists->create(auth()->user(), [
            'name' => trim($this->newArtistName),
            'type' => $this->newArtistType,
            'status' => ArtistStatus::Active->value,
        ]);

        // Fill the first empty row, or add one.
        $placed = false;
        foreach ($this->artistRows as $i => $row) {
            if ($row['artist_id'] === '' || $row['artist_id'] === null) {
                $this->artistRows[$i]['artist_id'] = $artist->id;
                $placed = true;
                break;
            }
        }
        if (! $placed) {
            $this->artistRows[] = ['artist_id' => $artist->id, 'role' => $this->artistRows === [] ? ArtistRole::Primary->value : ArtistRole::Featuring->value];
        }

        $this->reset('newArtistName', 'newArtistType', 'showNewArtist');
        $this->dispatch('toast', type: 'success', message: __(':name was added to your roster.', ['name' => $artist->name]));
    }

    /** Draft saves keep only the rows that name an artist. */
    protected function filledArtistRows(): array
    {
        return array_values(array_filter($this->artistRows, fn ($r) => ($r['artist_id'] ?? '') !== '' && $r['artist_id'] !== null));
    }

    public function moveArtistRow(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);
        if (isset($this->artistRows[$index], $this->artistRows[$target])) {
            [$this->artistRows[$index], $this->artistRows[$target]] = [$this->artistRows[$target], $this->artistRows[$index]];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function artistRowRules(): array
    {
        return [
            'artistRows' => ['required', 'array', 'min:1', 'max:20'],
            'artistRows.*.artist_id' => ['required', 'integer', 'distinct', Rule::exists('artists', 'id')->whereNull('deleted_at')],
            'artistRows.*.role' => ['required', Rule::enum(ArtistRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function artistRowMessages(): array
    {
        return [
            'artistRows.required' => __('Add at least one artist.'),
            'artistRows.*.artist_id.required' => __('Choose an artist.'),
            'artistRows.*.artist_id.distinct' => __('This artist is listed twice.'),
            'artistRows.*.artist_id.exists' => __('Choose an artist from the list.'),
        ];
    }

    /**
     * @return list<array{artist_id: int, role: string}>
     */
    protected function artistRowsForSave(): array
    {
        return array_map(fn ($r) => ['artist_id' => (int) $r['artist_id'], 'role' => (string) $r['role']], $this->artistRows);
    }

    /**
     * @return Collection<int, string>
     */
    protected function artistOptions(): Collection
    {
        $selected = collect($this->artistRows)->pluck('artist_id')->filter()->map(fn ($id) => (int) $id)->all();

        // Active roster plus anyone already selected (even if inactive).
        return Artist::query()
            ->where(fn ($q) => $q->where('status', '!=', 'inactive')->orWhereIn('id', $selected))
            ->orderBy('name')
            ->pluck('name', 'id');
    }
}
