<?php

namespace App\Livewire\Artists;

use App\Exceptions\CatalogException;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\ArtistContract;
use App\Models\ArtistDocument;
use App\Services\ArtistService;
use App\Services\License\LicenseService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Artist profile with tabs: overview, contacts, links, contracts, documents
 * and activity. Each editable tab is its own child component.
 */
class Show extends Component
{
    use EnforcesLicense;

    public Artist $artist;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public function mount(Artist $artist): void
    {
        $this->authorize('view', $artist);
        $this->artist = $artist;

        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'overview';
        }
    }

    /**
     * @return list<string>
     */
    protected function tabs(): array
    {
        $user = auth()->user();
        $documentsLicensed = app(LicenseService::class)->canUse('documents');

        return array_values(array_filter([
            'overview',
            app(LicenseService::class)->canUse('releases') && $user->can('releases.view') ? 'releases' : null,
            'contacts',
            'links',
            $documentsLicensed && $user->can('contracts.view') ? 'contracts' : null,
            $documentsLicensed && $user->can('documents.view') ? 'documents' : null,
            app(LicenseService::class)->canUse('royalties') && $user->can('payments.view') ? 'earnings' : null,
            $user->can('activity.view') ? 'activity' : null,
        ]));
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'overview';
    }

    #[On('artist-updated')]
    public function refreshArtist(): void
    {
        $this->artist->refresh();
    }

    public function delete(ArtistService $artists)
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $this->authorize('delete', $this->artist);

        try {
            $artists->delete(auth()->user(), $this->artist);
        } catch (CatalogException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return null;
        }
        session()->flash('success', __(':name has been deleted. You can restore it from the Deleted filter.', ['name' => $this->artist->name]));

        return $this->redirectRoute('artists.index');
    }

    public function restore(ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $this->authorize('restore', $this->artist);

        $artists->restore(auth()->user(), $this->artist);
        $this->artist->refresh();
        $this->dispatch('toast', message: __(':name has been restored.', ['name' => $this->artist->name]), type: 'success');
    }

    public function render(): View
    {
        $artist = $this->artist;
        $artist->loadCount(['contacts', 'links', 'contracts', 'documents', 'releases']);

        $data = [
            'tabs' => $this->tabs(),
            'counts' => [
                'contacts' => $artist->contacts_count,
                'links' => $artist->links_count,
                'contracts' => $artist->contracts_count,
                'documents' => $artist->documents_count,
                'releases' => $artist->releases_count,
            ],
        ];

        if ($this->tab === 'overview') {
            $data['primaryContact'] = $artist->contacts()->first();
            $data['links'] = $artist->links()->get();
            $data['activeContracts'] = in_array('contracts', $data['tabs'], true)
                ? ArtistContract::query()->whereBelongsTo($artist)->currentlyActive()->orderBy('end_date')->get()
                : collect();
            $data['recentDocuments'] = in_array('documents', $data['tabs'], true)
                ? ArtistDocument::query()->whereBelongsTo($artist)->latest('id')->limit(5)->get()
                : collect();
        }

        if ($this->tab === 'releases') {
            $data['releases'] = $artist->releases()
                ->with('artists:artists.id,artists.name')
                ->withCount('tracks')
                ->orderByRaw('release_date is null')
                ->orderByDesc('release_date')
                ->get();
        }

        if ($this->tab === 'activity') {
            $contractIds = ArtistContract::withTrashed()->whereBelongsTo($artist)->pluck('id');
            $documentIds = ArtistDocument::query()->whereBelongsTo($artist)->pluck('id');

            $data['activity'] = ActivityLog::query()
                ->with('user:id,name,deleted_at')
                ->where(fn ($q) => $q
                    ->where(fn ($s) => $s->where('subject_type', $artist->getMorphClass())->where('subject_id', $artist->id))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new ArtistContract)->getMorphClass())->whereIn('subject_id', $contractIds))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new ArtistDocument)->getMorphClass())->whereIn('subject_id', $documentIds)))
                ->latest('id')
                ->limit(50)
                ->get();
        }

        return view('livewire.artists.show', $data)
            ->title($artist->name)
            ->layoutData(['breadcrumbs' => [
                ['label' => __('Catalog')],
                ['label' => __('Artists'), 'url' => route('artists.index')],
                ['label' => $artist->name],
            ]]);
    }
}
