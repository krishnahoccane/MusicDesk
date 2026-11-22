<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Release;
use App\Models\Track;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use App\Support\CatalogDefaults;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → Catalogue: label defaults for new releases, genres and languages.
 */
class Catalog extends Component
{
    use EnforcesLicense;

    public string $label_name = '';

    public string $c_line_format = CatalogDefaults::DEFAULT_LINE;

    public string $p_line_format = CatalogDefaults::DEFAULT_LINE;

    public string $default_language = 'en';

    public string $newGenre = '';

    public string $newLanguageCode = '';

    public string $newLanguageName = '';

    public ?int $editingGenre = null;

    public string $editingGenreName = '';

    public function mount(SettingsService $settings): void
    {
        $this->label_name = (string) $settings->get('catalog.label_name', '');
        $this->c_line_format = (string) $settings->get('catalog.c_line_format', CatalogDefaults::DEFAULT_LINE);
        $this->p_line_format = (string) $settings->get('catalog.p_line_format', CatalogDefaults::DEFAULT_LINE);
        $this->default_language = CatalogDefaults::languageCode();
    }

    public function saveDefaults(SettingsService $settings, ActivityLogger $activity): void
    {
        $this->guard();

        $data = $this->validate([
            'label_name' => ['nullable', 'string', 'max:150'],
            'c_line_format' => ['required', 'string', 'max:200'],
            'p_line_format' => ['required', 'string', 'max:200'],
            'default_language' => ['required', Rule::exists('languages', 'code')],
        ], [], ['c_line_format' => __('© line'), 'p_line_format' => __('℗ line')]);

        $settings->setMany([
            'catalog.label_name' => trim((string) $data['label_name']),
            'catalog.c_line_format' => trim($data['c_line_format']),
            'catalog.p_line_format' => trim($data['p_line_format']),
            'catalog.default_language' => $data['default_language'],
        ]);
        $activity->log('settings.updated', __('Updated catalogue defaults'));

        $this->dispatch('toast', type: 'success', message: __('Catalogue defaults saved. They apply to new releases.'));
    }

    public function addGenre(ActivityLogger $activity): void
    {
        $this->guard();
        $this->newGenre = trim($this->newGenre);
        $this->validate(['newGenre' => ['required', 'string', 'max:80', Rule::unique('genres', 'name')]], [], ['newGenre' => __('genre')]);

        $genre = Genre::query()->create(['name' => $this->newGenre, 'is_active' => true]);
        $activity->log('catalog.genre_added', __('Added genre :name', ['name' => $genre->name]), $genre);
        $this->reset('newGenre');
    }

    public function editGenre(int $id): void
    {
        $genre = Genre::query()->find($id) ?? abort(404);
        $this->editingGenre = $genre->id;
        $this->editingGenreName = $genre->name;
        $this->resetErrorBag();
    }

    public function renameGenre(): void
    {
        $this->guard();
        $genre = Genre::query()->find($this->editingGenre) ?? abort(404);
        $this->editingGenreName = trim($this->editingGenreName);
        $this->validate(['editingGenreName' => ['required', 'string', 'max:80', Rule::unique('genres', 'name')->ignore($genre->id)]], [], ['editingGenreName' => __('genre')]);

        $genre->update(['name' => $this->editingGenreName]);
        $this->reset('editingGenre', 'editingGenreName');
    }

    public function toggleGenre(int $id): void
    {
        $this->guard();
        $genre = Genre::query()->find($id) ?? abort(404);
        $genre->update(['is_active' => ! $genre->is_active]);
    }

    public function deleteGenre(int $id, ActivityLogger $activity): void
    {
        $this->guard();
        $genre = Genre::query()->find($id) ?? abort(404);

        if (Release::withTrashed()->where('genre_id', $genre->id)->exists()) {
            $this->dispatch('toast', type: 'error', message: __('":name" is used by releases. Hide it instead so it is no longer offered.', ['name' => $genre->name]));

            return;
        }

        $genre->delete();
        $activity->log('catalog.genre_deleted', __('Deleted genre :name', ['name' => $genre->name]));
    }

    public function addLanguage(ActivityLogger $activity): void
    {
        $this->guard();
        $this->newLanguageCode = strtolower(trim($this->newLanguageCode));
        $this->newLanguageName = trim($this->newLanguageName);

        $this->validate([
            'newLanguageCode' => ['required', 'regex:/^[a-z]{2,3}$/', Rule::unique('languages', 'code')],
            'newLanguageName' => ['required', 'string', 'max:80'],
        ], ['newLanguageCode.regex' => __('Use the 2-letter ISO 639-1 code (or 3 letters for ISO 639-2), e.g. "hi".')],
            ['newLanguageCode' => __('code'), 'newLanguageName' => __('name')]);

        $language = Language::query()->create(['code' => $this->newLanguageCode, 'name' => $this->newLanguageName]);
        $activity->log('catalog.language_added', __('Added language :name', ['name' => $language->name]), $language);
        $this->reset('newLanguageCode', 'newLanguageName');
    }

    public function deleteLanguage(int $id, ActivityLogger $activity): void
    {
        $this->guard();
        $language = Language::query()->find($id) ?? abort(404);

        $used = Release::withTrashed()->where('language_id', $language->id)->exists() || Track::query()->where('language_id', $language->id)->exists();
        if ($used || $language->code === CatalogDefaults::languageCode()) {
            $this->dispatch('toast', type: 'error', message: __('":name" is in use or is the default language, so it cannot be deleted.', ['name' => $language->name]));

            return;
        }

        $language->delete();
        $activity->log('catalog.language_deleted', __('Deleted language :name', ['name' => $language->name]));
    }

    protected function guard(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    public function render(): View
    {
        return view('livewire.settings.catalog', [
            'genres' => Genre::query()->withCount('releases')->orderBy('name')->get(),
            'languages' => Language::query()->orderBy('name')->get(),
            'preview' => [
                'c' => strtr($this->c_line_format ?: CatalogDefaults::DEFAULT_LINE, ['{year}' => (string) now()->year, '{label}' => trim($this->label_name) ?: config('app.name')]),
                'p' => strtr($this->p_line_format ?: CatalogDefaults::DEFAULT_LINE, ['{year}' => (string) now()->year, '{label}' => trim($this->label_name) ?: config('app.name')]),
            ],
        ])->title(__('Catalogue settings'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Catalogue')],
        ]]);
    }
}
