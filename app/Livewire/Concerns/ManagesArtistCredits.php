<?php

namespace App\Livewire\Concerns;

use App\Enums\ArtistRole;
use App\Models\Artist;
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
