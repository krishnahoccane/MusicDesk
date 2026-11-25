<?php

namespace App\Livewire\Tracks;

use App\Enums\ArtistRole;
use App\Enums\ContributorRole;
use App\Enums\ReleaseType;
use App\Livewire\Concerns\EnforcesLicense;
use App\Livewire\Concerns\ManagesArtistCredits;
use App\Models\Contributor;
use App\Models\Language;
use App\Models\Mood;
use App\Models\Release;
use App\Models\Track;
use App\Services\Catalog\ContributorService;
use App\Services\Catalog\TrackService;
use App\Services\Media\AudioInspector;
use App\Support\Identifiers;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Create, edit or view a track: metadata, artists, contributor credits and
 * the master audio file. Read-only when the release is locked or the user
 * cannot edit tracks.
 */
class Form extends Component
{
    use EnforcesLicense, ManagesArtistCredits, WithFileUploads;

    #[Locked]
    public Release $release;

    public ?Track $track = null;

    public string $title = '';

    public string $version = '';

    public string $isrc = '';

    public int|string $disc_number = 1;

    public bool $explicit = false;

    public string $language_id = '';

    public string $mood_id = '';

    /** Caller ring-back tone (CRBT): name (max 64) and start of the 30-second cut, as m:ss. */
    public string $crbt_name = '';

    public string $crbt_start = '';

    /** What the uploaded (not yet saved) file contains, for the preview. */
    #[Locked]
    public ?array $audioInfo = null;

    /** @var list<array{contributor_id: int|string, role: string, share: string}> */
    public array $creditRows = [];

    /** @var TemporaryUploadedFile|null */
    public $audio = null;

    public string $newContributor = '';

    public function mount(?Release $release = null, ?Track $track = null): void
    {
        $this->ensureFeature('tracks');

        if ($track?->exists) {
            $this->authorize('view', $track);
            $this->track = $track;
            $this->release = $track->release;
            $this->fill([
                'title' => $track->title,
                'version' => (string) $track->version,
                'isrc' => (string) $track->formattedIsrc(),
                'disc_number' => $track->disc_number,
                'explicit' => $track->explicit,
                'language_id' => (string) $track->language_id,
                'mood_id' => (string) $track->mood_id,
                'crbt_name' => (string) $track->crbt_name,
                'crbt_start' => $track->formattedCrbtStart(),
            ]);
            $this->artistRows = $track->artists->map(fn ($a) => ['artist_id' => $a->id, 'role' => $a->pivot->role])->all();
            $this->creditRows = $track->credits->map(fn ($c) => [
                'contributor_id' => $c->contributor_id,
                'role' => $c->role->value,
                'share' => $c->share === null ? '' : rtrim(rtrim((string) $c->share, '0'), '.'),
            ])->all();
        } else {
            abort_unless($release?->exists, 404);
            $this->authorize('create', [Track::class, $release]);
            $this->release = $release;
            $this->track = null;
            $this->language_id = (string) $release->language_id;
            if ($this->isSingle()) {
                $this->title = $release->title; // a single's track carries the release title
            }
            // New tracks start with the release's artists.
            $this->artistRows = $release->artists->map(fn ($a) => ['artist_id' => $a->id, 'role' => $a->pivot->role])->all()
                ?: [['artist_id' => '', 'role' => ArtistRole::Primary->value]];
            // Distributors (PDL among them) need a composer and a lyricist for every track.
            $this->creditRows = [
                ['contributor_id' => '', 'role' => ContributorRole::Composer->value, 'share' => ''],
                ['contributor_id' => '', 'role' => ContributorRole::Lyricist->value, 'share' => ''],
            ];
        }
    }

    public function isSingle(): bool
    {
        return $this->release->type === ReleaseType::Single;
    }

    /** Length known from the saved track or the uploaded file (seconds). */
    protected function knownDuration(): ?int
    {
        return $this->audioInfo['duration'] ?? ($this->track?->hasAudio() ? $this->track->duration_seconds : null);
    }

    /** Check the uploaded file right away, so its length and format show before saving. */
    public function updatedAudio(AudioInspector $inspector): void
    {
        $this->audioInfo = null;
        $this->validateOnly('audio');
        if (! $this->audio) {
            return;
        }

        try {
            $this->audioInfo = $inspector->inspect($this->audio->getRealPath());
        } catch (\Throwable $e) {
            $this->addError('audio', $e->getMessage());
        }
    }

    protected function canEdit(): bool
    {
        return $this->track
            ? auth()->user()->can('update', $this->track)
            : auth()->user()->can('create', [Track::class, $this->release]);
    }

    public function addCreditRow(): void
    {
        $this->creditRows[] = ['contributor_id' => '', 'role' => ContributorRole::Producer->value, 'share' => ''];
    }

    public function removeCreditRow(int $index): void
    {
        unset($this->creditRows[$index]);
        $this->creditRows = array_values($this->creditRows);
    }

    public function createContributor(ContributorService $contributors): void
    {
        $this->ensureLicenseAllowsWrites();
        abort_unless(auth()->user()->can('contributors.manage'), 403);

        $this->validate(['newContributor' => ['required', 'string', 'max:150']], [], ['newContributor' => __('name')]);

        $contributor = $contributors->save(auth()->user(), null, ['name' => $this->newContributor]);

        // Fill the first empty row, or add one.
        foreach ($this->creditRows as $i => $row) {
            if ($row['contributor_id'] === '' || $row['contributor_id'] === null) {
                $this->creditRows[$i]['contributor_id'] = $contributor->id;
                $this->reset('newContributor');

                return;
            }
        }
        $this->creditRows[] = ['contributor_id' => $contributor->id, 'role' => ContributorRole::Songwriter->value, 'share' => ''];
        $this->reset('newContributor');
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'version' => ['nullable', 'string', 'max:100'],
            'isrc' => ['nullable', 'string', 'max:20', function (string $a, mixed $v, Closure $fail) {
                $n = Identifiers::normalizeIsrc((string) $v);
                if ($n !== null && ! Identifiers::isValidIsrc($n)) {
                    $fail(__('The ISRC must look like CC-XXX-YY-NNNNN (12 characters).'));
                }
            }],
            'disc_number' => ['required', 'integer', 'between:1,20'],
            'explicit' => ['boolean'],
            'language_id' => ['nullable', Rule::exists('languages', 'id')],
            'mood_id' => ['nullable', Rule::exists('moods', 'id')],
            'crbt_name' => ['nullable', 'string', 'max:64'],
            'crbt_start' => ['nullable', 'regex:/^\d{1,3}:[0-5]\d$/', function (string $a, mixed $v, Closure $fail) {
                $duration = $this->knownDuration();
                if ($v === '' || $v === null || $duration === null) {
                    return;
                }
                [$m, $s] = explode(':', (string) $v);
                if ((int) $m * 60 + (int) $s + min(30, $duration) > $duration) {
                    $fail(__('The 30-second ring-back tone must end before the track does (:length).', ['length' => sprintf('%d:%02d', intdiv($duration, 60), $duration % 60)]));
                }
            }],
            'creditRows' => ['array', 'max:50'],
            'creditRows.*.contributor_id' => ['required', 'integer', Rule::exists('contributors', 'id')],
            'creditRows.*.role' => ['required', Rule::enum(ContributorRole::class)],
            'creditRows.*.share' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'audio' => ['nullable', 'file', 'extensions:wav,flac', 'max:'.(int) config('labeldesk.catalog.audio_max_kb', 512000)],
            ...$this->artistRowRules(),
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->artistRowMessages(),
            'crbt_start.regex' => __('Enter the start as minutes:seconds, e.g. 0:45.'),
            'creditRows.*.contributor_id.required' => __('Choose a person or remove the row.'),
            'creditRows.*.share.regex' => __('Enter a percentage such as 50 or 33.33.'),
            'audio.extensions' => __('Upload a WAV or FLAC master file.'),
        ];
    }

    /** Save what is filled in and stay here: incomplete artists and credits are allowed (empty rows left out). */
    public function saveDraft(TrackService $tracks)
    {
        return $this->persist($tracks, draft: true);
    }

    public function save(TrackService $tracks)
    {
        return $this->persist($tracks, draft: false);
    }

    protected function persist(TrackService $tracks, bool $draft)
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('tracks');
        abort_unless($this->canEdit(), 403);

        if ($this->isSingle()) {
            $this->title = $this->release->title;
        }

        if ($draft) {
            $this->artistRows = $this->filledArtistRows();
            $this->creditRows = array_values(array_filter($this->creditRows, fn ($r) => ($r['contributor_id'] ?? '') !== '' && $r['contributor_id'] !== null));
            $rules = $this->rules();
            $rules['artistRows'] = ['array', 'max:20'];
            $this->validate($rules);
        } else {
            $this->validate();
            $roles = array_column($this->creditRows, 'role');
            $songwriter = in_array(ContributorRole::Songwriter->value, $roles, true);
            if (! $songwriter && (! in_array(ContributorRole::Composer->value, $roles, true) || ! in_array(ContributorRole::Lyricist->value, $roles, true))) {
                $this->addError('creditRows', __('Add a composer and a lyricist (a songwriter counts as both), or use Save as draft.'));

                return null;
            }
        }

        $crbtStart = null;
        if ($this->crbt_start !== '') {
            [$m, $s] = explode(':', $this->crbt_start);
            $crbtStart = (int) $m * 60 + (int) $s;
        }

        $credits = array_map(fn ($r) => [
            'contributor_id' => (int) $r['contributor_id'],
            'role' => (string) $r['role'],
            'share' => $r['share'] !== '' ? (string) $r['share'] : null,
        ], $this->creditRows);

        try {
            $track = $tracks->save(auth()->user(), $this->release, $this->track, [
                'title' => $this->title,
                'version' => $this->version,
                'isrc' => $this->isrc,
                'explicit' => $this->explicit,
                'language_id' => $this->language_id,
                'mood_id' => $this->mood_id,
                'crbt_name' => $this->crbt_name,
                'crbt_start_seconds' => $crbtStart,
                'is_draft' => $draft,
                'disc_number' => (int) $this->disc_number,
            ], $this->artistRowsForSave(), $credits);
        } catch (RuntimeException $e) {
            $this->addError('form', $e->getMessage());

            return null;
        }

        if ($this->audio) {
            try {
                $tracks->setAudio(auth()->user(), $track, $this->audio);
            } catch (RuntimeException $e) {
                // Metadata is saved; keep the user here to fix the audio.
                $this->track = $track->fresh();
                $this->reset('audio');
                $this->addError('audio', $e->getMessage());
                $this->dispatch('toast', message: __('Track details saved, but the audio file was not accepted.'), type: 'warning');

                return null;
            }
        }

        if ($draft) {
            if (! $this->track) {
                session()->flash('success', __('Draft saved. Continue whenever you are ready.'));

                return $this->redirectRoute('tracks.edit', $track);
            }
            $this->track = $track->fresh();
            $this->reset('audio');
            $this->audioInfo = null;
            $this->dispatch('toast', type: 'success', message: __('Draft saved.'));

            return null;
        }

        session()->flash('success', $this->track ? __('Track saved.') : __('":title" has been added.', ['title' => $track->displayTitle()]));

        return $this->redirectRoute('releases.show', ['release' => $this->release, 'tab' => 'tracks']);
    }

    public function render(): View
    {
        $editing = $this->track !== null;
        $canEdit = $this->canEdit();
        $title = $editing ? $this->track->displayTitle() : __('Add track');

        $selected = collect($this->creditRows)->pluck('contributor_id')->filter()->all();

        return view('livewire.tracks.form', [
            'editing' => $editing,
            'canEdit' => $canEdit,
            'disabled' => ! $canEdit,
            'canPlay' => $editing && $this->track->hasAudio() && auth()->user()->can('downloadMaster', $this->track),
            'languages' => Language::query()->orderBy('name')->pluck('name', 'id'),
            'moods' => Mood::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', (int) $this->mood_id))->orderBy('sort')->orderBy('name')->pluck('name', 'id'),
            'single' => $this->isSingle(),
            'knownDuration' => $this->knownDuration(),
            'artistOptions' => $this->artistOptions(),
            'artistRoles' => ArtistRole::options(),
            'contributors' => Contributor::query()->orderBy('name')->pluck('name', 'id'),
            'contributorRoles' => ContributorRole::options(),
            'shareRoles' => collect(ContributorRole::cases())->filter->hasShare()->map->value->values()->all(),
            'maxMb' => (int) round(config('labeldesk.catalog.audio_max_kb', 512000) / 1024),
            'phpLimitMb' => (int) floor(min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')) / 1048576),
        ])->title($title)->layoutData(['breadcrumbs' => [
            ['label' => __('Catalog')],
            ['label' => __('Releases'), 'url' => route('releases.index')],
            ['label' => $this->release->displayTitle(), 'url' => route('releases.show', ['release' => $this->release, 'tab' => 'tracks'])],
            ['label' => $title],
        ]]);
    }

    protected static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '-1' || $value === '0') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
