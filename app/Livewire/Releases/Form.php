<?php

namespace App\Livewire\Releases;

use App\Enums\ArtistRole;
use App\Enums\ReleaseType;
use App\Livewire\Concerns\EnforcesLicense;
use App\Livewire\Concerns\ManagesArtistCredits;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Release;
use App\Services\Catalog\ReleaseService;
use App\Support\CatalogDefaults;
use App\Support\Identifiers;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Create or edit a release's metadata, credited artists and artwork.
 */
class Form extends Component
{
    use EnforcesLicense, ManagesArtistCredits, WithFileUploads;

    public ?Release $release = null;

    public string $title = '';

    public string $version = '';

    public string $type = 'single';

    public string $release_date = '';

    public string $original_release_date = '';

    public string $genre_id = '';

    public string $language_id = '';

    public string $upc = '';

    public string $catalog_number = '';

    public string $label_name = '';

    public string $c_line = '';

    public string $p_line = '';

    public string $notes = '';

    /** @var TemporaryUploadedFile|null */
    public $artwork = null;

    public bool $remove_artwork = false;

    public function mount(?Release $release = null): void
    {
        $this->ensureFeature('releases');

        if ($release?->exists) {
            $this->authorize('update', $release);
            $this->release = $release;
            $this->fill([
                'title' => $release->title,
                'version' => (string) $release->version,
                'type' => $release->type->value,
                'release_date' => (string) $release->release_date?->format('Y-m-d'),
                'original_release_date' => (string) $release->original_release_date?->format('Y-m-d'),
                'genre_id' => (string) $release->genre_id,
                'language_id' => (string) $release->language_id,
                'upc' => (string) $release->upc,
                'catalog_number' => (string) $release->catalog_number,
                'label_name' => (string) $release->label_name,
                'c_line' => (string) $release->c_line,
                'p_line' => (string) $release->p_line,
                'notes' => (string) $release->notes,
            ]);
            $this->artistRows = $release->artists->map(fn ($a) => ['artist_id' => $a->id, 'role' => $a->pivot->role])->all();
        } else {
            $this->authorize('create', Release::class);
            $this->release = null;
            $this->label_name = CatalogDefaults::labelName();
            $this->c_line = CatalogDefaults::cLine();
            $this->p_line = CatalogDefaults::pLine();
            // Started from an artist profile: pre-select that artist.
            $artist = request()->integer('artist');
            $this->artistRows = [['artist_id' => $artist > 0 ? $artist : '', 'role' => ArtistRole::Primary->value]];
            $this->language_id = (string) Language::query()->where('code', CatalogDefaults::languageCode())->value('id');
        }
    }

    protected function rules(): array
    {
        $ignore = $this->release?->id;

        return [
            'title' => ['required', 'string', 'max:200'],
            'version' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::enum(ReleaseType::class)],
            'release_date' => ['nullable', 'date_format:Y-m-d'],
            'original_release_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:release_date'],
            'genre_id' => ['nullable', Rule::exists('genres', 'id')],
            'language_id' => ['nullable', Rule::exists('languages', 'id')],
            'upc' => ['nullable', 'string', 'max:20', function (string $a, mixed $v, Closure $fail) {
                $n = Identifiers::normalizeUpc((string) $v);
                if ($n !== null && ! Identifiers::isValidUpc($n)) {
                    $fail(__('Enter a valid 12- or 13-digit UPC/EAN (the check digit does not match).'));
                }
            }],
            'catalog_number' => ['nullable', 'string', 'max:50', Rule::unique('releases', 'catalog_number')->ignore($ignore)],
            'label_name' => ['nullable', 'string', 'max:150'],
            'c_line' => ['nullable', 'string', 'max:200'],
            'p_line' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'artwork' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:'.(int) config('labeldesk.catalog.artwork_max_kb', 30720)],
            ...$this->artistRowRules(),
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->artistRowMessages(),
            'original_release_date.before_or_equal' => __('The original release date cannot be after the release date.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'c_line' => __('© line'), 'p_line' => __('℗ line'), 'catalog_number' => __('catalogue number'),
            'genre_id' => __('genre'), 'language_id' => __('language'),
        ];
    }

    public function updatedArtwork(): void
    {
        $this->validateOnly('artwork');
        $this->remove_artwork = false;
    }

    public function save(ReleaseService $releases)
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        $this->release ? $this->authorize('update', $this->release) : $this->authorize('create', Release::class);

        $data = $this->validate();
        $data['upc'] = Identifiers::normalizeUpc($this->upc);

        $duplicate = $data['upc'] !== null && Release::withTrashed()->where('upc', $data['upc'])
            ->when($this->release, fn ($q) => $q->whereKeyNot($this->release->id))->exists();
        if ($duplicate) {
            $this->addError('upc', __('This UPC is already used by another release.'));

            return null;
        }

        unset($data['artwork'], $data['artistRows']);
        $actor = auth()->user();

        try {
            $release = $this->release
                ? $releases->update($actor, $this->release, $data, $this->artistRowsForSave())
                : $releases->create($actor, $data, $this->artistRowsForSave());
        } catch (RuntimeException $e) {
            $this->addError('form', $e->getMessage());

            return null;
        }

        try {
            if ($this->artwork) {
                $releases->setArtwork($actor, $release, $this->artwork);
            } elseif ($this->remove_artwork) {
                $releases->removeArtwork($actor, $release);
            }
        } catch (RuntimeException $e) {
            session()->flash('warning', __('Saved, but the artwork was not accepted: :reason', ['reason' => $e->getMessage()]));

            return $this->redirectRoute('releases.show', $release);
        }

        session()->flash('success', $this->release ? __('Changes saved.') : __(':title has been created. Add its tracks next.', ['title' => $release->displayTitle()]));

        return $this->redirectRoute('releases.show', $this->release ? $release : ['release' => $release, 'tab' => 'tracks']);
    }

    public function render(): View
    {
        $editing = $this->release !== null;
        $title = $editing ? __('Edit :title', ['title' => $this->release->title]) : __('New release');

        $crumbs = [['label' => __('Catalog')], ['label' => __('Releases'), 'url' => route('releases.index')]];
        if ($editing) {
            $crumbs[] = ['label' => $this->release->displayTitle(), 'url' => route('releases.show', $this->release)];
        }
        $crumbs[] = ['label' => $editing ? __('Edit') : __('New release')];

        return view('livewire.releases.form', [
            'editing' => $editing,
            'types' => ReleaseType::options(),
            'genres' => Genre::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'languages' => Language::query()->orderBy('name')->pluck('name', 'id'),
            'artistOptions' => $this->artistOptions(),
            'artistRoles' => ArtistRole::options(),
            'minPx' => (int) config('labeldesk.catalog.artwork_min_px', 3000),
            'maxMb' => (int) round(config('labeldesk.catalog.artwork_max_kb', 30720) / 1024),
        ])->title($title)->layoutData(['breadcrumbs' => $crumbs]);
    }
}
