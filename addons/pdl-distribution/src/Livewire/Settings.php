<?php

namespace LabelDesk\Addons\PdlDistribution\Livewire;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Release;
use App\Models\Track;
use App\Services\Activity\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\PdlDistribution\Services\Cosmos;
use LabelDesk\Addons\PdlDistribution\Services\CosmosClient;
use LabelDesk\Addons\PdlDistribution\Services\CosmosException;
use LabelDesk\Addons\PdlDistribution\Services\PdlSettings;
use Livewire\Component;

/**
 * Settings → PDL distribution: COSMOS connection (password stored encrypted,
 * never sent back to the browser), the PDL label and the mapping of
 * LabelDesk genres, languages and release types to COSMOS values.
 */
class Settings extends Component
{
    use EnforcesLicense;

    public bool $enabled = false;

    public string $base_url = '';

    public string $email = '';

    /** Write-only: empty keeps the stored password. */
    public string $password = '';

    public string $label_id = '';

    public string $label_name = '';

    public string $platforms = 'AllPlatformWithYouTube';

    public bool $allow_any_go_live_date = false;

    public string $album_type = 'Album';

    public string $album_category = 'Album';

    /** @var array<string, string> LabelDesk release type => COSMOS content type */
    public array $content_types = [];

    public string $default_mood = '';

    public int $crbt_start = 0;

    public string $date_format = 'd-m-Y';

    public string $publisher = '';

    /** @var array<string, string> genre id => "Genre|Sub-genre" */
    public array $genre_map = [];

    /** @var array<string, string> language code => COSMOS language */
    public array $language_map = [];

    public function mount(PdlSettings $settings): void
    {
        $this->authorizeManage();
        foreach (['enabled', 'base_url', 'email', 'label_id', 'label_name', 'platforms', 'allow_any_go_live_date', 'album_type', 'album_category', 'content_types', 'default_mood', 'crbt_start', 'date_format', 'publisher', 'genre_map', 'language_map'] as $key) {
            $value = $settings->get($key);
            $this->{$key} = match (true) {
                is_bool($this->{$key}) => (bool) $value,
                is_int($this->{$key}) => (int) $value,
                is_array($this->{$key}) => array_map('strval', (array) $value),
                default => (string) $value,
            };
        }
        $this->content_types += PdlSettings::DEFAULTS['content_types'];
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('pdl.manage'), 403);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'base_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'email' => ['nullable', 'required_if:enabled,true', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'label_id' => ['nullable', 'required_if:enabled,true', 'regex:/^[a-f0-9]{24}$/i'],
            'label_name' => ['nullable', 'string', 'max:150'],
            'platforms' => ['required', Rule::in(array_keys(Cosmos::PLATFORMS))],
            'allow_any_go_live_date' => ['boolean'],
            'album_type' => ['required', Rule::in(Cosmos::ALBUM_TYPES)],
            'album_category' => ['required', 'string', 'max:60'],
            'content_types' => ['array'],
            'content_types.*' => ['required', Rule::in(Cosmos::CONTENT_TYPES)],
            'default_mood' => ['nullable', Rule::in(Cosmos::MOODS)],
            'crbt_start' => ['required', 'integer', 'min:0', 'max:600'],
            'date_format' => ['required', Rule::in(array_keys(PdlSettings::DATE_FORMATS))],
            'publisher' => ['nullable', 'string', 'max:150'],
            'genre_map' => ['array'],
            'genre_map.*' => ['nullable', Rule::in(Cosmos::genreOptions())],
            'language_map' => ['array'],
            'language_map.*' => ['nullable', Rule::in(Cosmos::LANGUAGES)],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'base_url' => __('API base URL'), 'email' => __('account email'), 'label_id' => __('PDL label id'),
            'content_types.*' => __('content type'), 'genre_map.*' => __('PDL genre'), 'language_map.*' => __('PDL language'),
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return ['label_id.regex' => __('The PDL label id is the 24-character id of your label in COSMOS (letters a–f and digits).')];
    }

    public function save(PdlSettings $settings, CosmosClient $client, ActivityLogger $activity): void
    {
        $this->authorizeManage();
        $this->ensureLicenseAllowsWrites();
        $this->base_url = rtrim(trim($this->base_url), '/');
        $this->email = trim($this->email);
        $this->label_id = strtolower(trim($this->label_id));
        $this->validate();

        if ($this->enabled && $this->password === '' && $settings->password() === null) {
            $this->addError('password', __('Enter the password of the PDL account.'));

            return;
        }

        $client->forgetToken(); // before the email or URL changes: forget the old account's token
        $settings->save([
            'enabled' => $this->enabled,
            'base_url' => $this->base_url,
            'email' => $this->email,
            'label_id' => $this->label_id,
            'label_name' => trim($this->label_name),
            'platforms' => $this->platforms,
            'allow_any_go_live_date' => $this->allow_any_go_live_date,
            'album_type' => $this->album_type,
            'album_category' => trim($this->album_category),
            'content_types' => $this->content_types,
            'default_mood' => $this->default_mood,
            'crbt_start' => $this->crbt_start,
            'date_format' => $this->date_format,
            'publisher' => trim($this->publisher),
            'genre_map' => array_filter($this->genre_map, fn ($v) => $v !== ''),
            'language_map' => array_filter($this->language_map, fn ($v) => $v !== ''),
        ]);
        if ($this->password !== '') {
            $settings->setPassword($this->password);
        }
        $this->password = '';

        $activity->log('settings.updated', __('Updated PDL distribution settings'));
        $this->dispatch('toast', type: 'success', message: __('Saved.'));
    }

    public function testConnection(PdlSettings $settings, CosmosClient $client): void
    {
        $this->authorizeManage();
        $this->validateOnly('base_url');
        $this->validateOnly('email');
        $password = $this->password !== '' ? $this->password : (string) $settings->password();
        if (trim($this->email) === '' || $password === '') {
            $this->dispatch('toast', type: 'danger', message: __('Enter the account email and password first.'));

            return;
        }

        try {
            $client->testSignIn(rtrim(trim($this->base_url), '/'), trim($this->email), $password);
            $this->dispatch('toast', type: 'success', message: __('Signed in to PDL as :email.', ['email' => trim($this->email)]));
        } catch (CosmosException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }
    }

    public function forgetPassword(PdlSettings $settings, CosmosClient $client, ActivityLogger $activity): void
    {
        $this->authorizeManage();
        $this->ensureLicenseAllowsWrites();
        $client->forgetToken();
        $settings->save(['enabled' => false]);
        $settings->setPassword('');
        $this->enabled = false;
        $activity->log('settings.updated', __('Removed the PDL password'));
        $this->dispatch('toast', type: 'success', message: __('Password removed and PDL distribution disabled.'));
    }

    public function render(PdlSettings $settings): View
    {
        $usedLanguageIds = Release::query()->withoutGlobalScopes()->whereNotNull('language_id')->distinct()->pluck('language_id')
            ->merge(Track::query()->withoutGlobalScopes()->whereNotNull('language_id')->distinct()->pluck('language_id'));
        $languages = Language::query()
            ->where(fn ($q) => $q->whereIn('id', $usedLanguageIds)->orWhereIn('code', array_keys($this->language_map)))
            ->orderBy('name')->get(['id', 'code', 'name']);

        return view('pdl-distribution::settings', [
            'hasPassword' => $settings->password() !== null,
            'genres' => Genre::query()->where('is_active', true)->orWhereIn('id', array_keys($this->genre_map))->orderBy('name')->get(['id', 'name']),
            'languages' => $languages,
            'auto' => fn (Language $l) => PdlSettings::autoLanguage($l->code, $l->name),
            'cosmosGenres' => Cosmos::GENRES,
            'insecure' => str_starts_with(strtolower($this->base_url), 'http://'),
        ])->title(__('PDL distribution'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('PDL distribution')],
        ]]);
    }
}
