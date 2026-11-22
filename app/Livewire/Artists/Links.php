<?php

namespace App\Livewire\Artists;

use App\Enums\LinkPlatform;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Models\ArtistLink;
use App\Services\ArtistService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Links extends Component
{
    use EnforcesLicense;

    #[Locked]
    public Artist $artist;

    #[Locked]
    public ?int $editingId = null;

    public string $platform = 'spotify';

    public string $url = '';

    public function mount(Artist $artist): void
    {
        $this->authorize('view', $artist);
        $this->artist = $artist;
    }

    public function edit(int $id): void
    {
        $this->authorize('update', $this->artist);
        $link = $this->find($id);
        $this->editingId = $link->id;
        $this->platform = $link->platform->value;
        $this->url = $link->url;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'platform', 'url');
        $this->resetValidation();
    }

    public function save(ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $this->authorize('update', $this->artist);

        $this->url = trim($this->url);
        $this->validate([
            'platform' => ['required', Rule::enum(LinkPlatform::class)],
            'url' => [
                'required', 'string', 'max:500', 'url:http,https',
                function (string $attribute, mixed $value, Closure $fail) {
                    $platform = LinkPlatform::tryFrom($this->platform);
                    if ($platform && ! $platform->accepts((string) $value)) {
                        $fail(__('This does not look like a :platform address.', ['platform' => $platform->label()]));
                    }
                },
            ],
        ], ['url.url' => __('Enter a full web address starting with https://')]);

        $link = $this->editingId ? $this->find($this->editingId) : null;
        $artists->saveLink(auth()->user(), $this->artist, $link, $this->platform, $this->url);

        $this->dispatch('toast', message: $link ? __('Link updated.') : __('Link added.'), type: 'success');

        $this->dispatch('artist-updated');
        $this->cancel();
    }

    public function delete(int $id, ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $this->authorize('update', $this->artist);

        $artists->deleteLink(auth()->user(), $this->artist, $this->find($id));
        $this->dispatch('toast', message: __('Link removed.'), type: 'success');
        $this->dispatch('artist-updated');
        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    protected function find(int $id): ArtistLink
    {
        return ArtistLink::query()->where('artist_id', $this->artist->id)->find($id) ?? abort(404);
    }

    public function render(): View
    {
        return view('livewire.artists.links', [
            'links' => $this->artist->links()->get(),
            'platforms' => LinkPlatform::options(),
            'canEdit' => auth()->user()->can('update', $this->artist),
        ]);
    }
}
