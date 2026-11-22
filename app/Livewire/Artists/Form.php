<?php

namespace App\Livewire\Artists;

use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Services\ArtistService;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Create or edit an artist profile (including the photo).
 */
class Form extends Component
{
    use EnforcesLicense, WithFileUploads;

    public ?Artist $artist = null;

    public string $name = '';

    public string $legal_name = '';

    public string $type = 'solo';

    public string $status = 'active';

    public string $country = '';

    public string $primary_genre = '';

    public string $email = '';

    public string $phone = '';

    public string $bio = '';

    public string $notes = '';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public bool $remove_photo = false;

    public function mount(?Artist $artist = null): void
    {
        $this->ensureFeature('artists');

        if ($artist?->exists) {
            $this->authorize('update', $artist);
            $this->artist = $artist;
            $this->fill([
                'name' => $artist->name,
                'legal_name' => (string) $artist->legal_name,
                'type' => $artist->type->value,
                'status' => $artist->status->value,
                'country' => (string) $artist->country,
                'primary_genre' => (string) $artist->primary_genre,
                'email' => (string) $artist->email,
                'phone' => (string) $artist->phone,
                'bio' => (string) $artist->bio,
                'notes' => (string) $artist->notes,
            ]);
        } else {
            $this->authorize('create', Artist::class);
            $this->artist = null;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'type' => ['required', Rule::enum(ArtistType::class)],
            'status' => ['required', Rule::enum(ArtistStatus::class)],
            'country' => ['nullable', Rule::in(Countries::codes())],
            'primary_genre' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s\/]*$/'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('labeldesk.uploads.image_max_kb', 8192), 'dimensions:min_width=100,min_height=100,max_width=8000,max_height=8000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['legal_name' => __('legal name'), 'primary_genre' => __('genre')];
    }

    public function messages(): array
    {
        return ['phone.regex' => __('Use digits, spaces and + ( ) - . / only.')];
    }

    public function updatedPhoto(): void
    {
        $this->validateOnly('photo');
        $this->remove_photo = false;
    }

    public function save(ArtistService $artists)
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $this->artist ? $this->authorize('update', $this->artist) : $this->authorize('create', Artist::class);

        $data = $this->validate();
        unset($data['photo']);
        $actor = auth()->user();

        $artist = $this->artist
            ? $artists->update($actor, $this->artist, $data)
            : $artists->create($actor, $data);

        try {
            if ($this->photo) {
                $artists->setPhoto($actor, $artist, $this->photo);
            } elseif ($this->remove_photo) {
                $artists->removePhoto($actor, $artist);
            }
        } catch (RuntimeException $e) {
            // The profile itself is saved; report the photo problem.
            session()->flash('warning', __('Saved, but the photo could not be used: :reason', ['reason' => $e->getMessage()]));

            return $this->redirectRoute('artists.show', $artist);
        }

        session()->flash('success', $this->artist ? __('Changes to :name have been saved.', ['name' => $artist->name]) : __(':name has been added.', ['name' => $artist->name]));

        return $this->redirectRoute('artists.show', $artist);
    }

    public function render(): View
    {
        $editing = $this->artist !== null;
        $title = $editing ? __('Edit :name', ['name' => $this->artist->name]) : __('Add artist');

        $crumbs = [['label' => __('Catalog')], ['label' => __('Artists'), 'url' => route('artists.index')]];
        if ($editing) {
            $crumbs[] = ['label' => $this->artist->name, 'url' => route('artists.show', $this->artist)];
        }
        $crumbs[] = ['label' => $editing ? __('Edit') : __('Add artist')];

        return view('livewire.artists.form', [
            'editing' => $editing,
            'types' => ArtistType::options(),
            'statuses' => ArtistStatus::options(),
            'countries' => Countries::options(),
            'imageMaxMb' => round(config('labeldesk.uploads.image_max_kb', 8192) / 1024),
        ])->title($title)->layoutData(['breadcrumbs' => $crumbs]);
    }
}
