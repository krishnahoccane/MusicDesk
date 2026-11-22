<?php

namespace App\Livewire\Artists;

use App\Enums\ContactRole;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Models\ArtistContact;
use App\Services\ArtistService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Contacts extends Component
{
    use EnforcesLicense;

    #[Locked]
    public Artist $artist;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $role = 'manager';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public bool $is_primary = false;

    public function mount(Artist $artist): void
    {
        $this->authorize('view', $artist);
        $this->artist = $artist;
    }

    public function create(): void
    {
        $this->authorize('update', $this->artist);
        $this->resetForm();
        $this->is_primary = ! $this->artist->contacts()->exists();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('update', $this->artist);
        $contact = $this->find($id);

        $this->resetForm();
        $this->editingId = $contact->id;
        $this->fill([
            'name' => $contact->name,
            'role' => $contact->role->value,
            'email' => (string) $contact->email,
            'phone' => (string) $contact->phone,
            'notes' => (string) $contact->notes,
            'is_primary' => $contact->is_primary,
        ]);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $this->authorize('update', $this->artist);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'role' => ['required', Rule::enum(ContactRole::class)],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s\/]*$/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_primary' => ['boolean'],
        ], [
            'email.required_without' => __('Enter an email address or a phone number.'),
            'phone.regex' => __('Use digits, spaces and + ( ) - . / only.'),
        ]);

        $contact = $this->editingId ? $this->find($this->editingId) : null;
        $artists->saveContact(auth()->user(), $this->artist, $contact, $data);

        $this->dispatch('toast', message: $contact ? __('Contact updated.') : __('Contact added.'), type: 'success');

        $this->dispatch('artist-updated');
        $this->resetForm();
    }

    public function delete(int $id, ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $this->authorize('update', $this->artist);

        $artists->deleteContact(auth()->user(), $this->artist, $this->find($id));
        $this->dispatch('toast', message: __('Contact removed.'), type: 'success');
        $this->dispatch('artist-updated');

        if ($this->editingId === $id) {
            $this->resetForm();
        }
    }

    protected function find(int $id): ArtistContact
    {
        return ArtistContact::query()->where('artist_id', $this->artist->id)->find($id) ?? abort(404);
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'role', 'email', 'phone', 'notes', 'is_primary');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.artists.contacts', [
            'contacts' => $this->artist->contacts()->get(),
            'roles' => ContactRole::options(),
            'canEdit' => auth()->user()->can('update', $this->artist),
        ]);
    }
}
