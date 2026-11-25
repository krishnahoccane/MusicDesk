<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use App\Services\Website\WebsiteContent;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → Website: switch the label's public website on or off and manage its content.
 * Colours, fonts and logos come from Settings → Appearance.
 */
class Website extends Component
{
    use EnforcesLicense;

    public bool $enabled = false;

    public string $hero_title = '';

    public string $hero_text = '';

    public bool $hero_use_login_image = false;

    public string $featured_release_id = '';

    public string $about_title = '';

    public string $about_text = '';

    public bool $show_releases = true;

    public bool $show_upcoming = true;

    public bool $show_artists = true;

    public bool $show_about = true;

    public bool $show_contact = true;

    public bool $show_login_link = true;

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $contact_address = '';

    public string $meta_description = '';

    /** @var array<string, string> */
    public array $socials = [];

    public function mount(WebsiteContent $site): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $settings = $site->settings();
        foreach (array_keys(WebsiteContent::DEFAULTS) as $key) {
            if ($key === 'socials') {
                continue;
            }
            $value = $settings[$key];
            $this->{$key} = is_bool(WebsiteContent::DEFAULTS[$key]) ? (bool) $value : (string) ($value ?? '');
        }
        foreach (array_keys(WebsiteContent::SOCIALS) as $network) {
            $this->socials[$network] = (string) ($settings['socials'][$network] ?? '');
        }
    }

    protected function guard(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    public function save(SettingsService $settings, ActivityLogger $activity, WebsiteContent $site): void
    {
        $this->guard();

        $data = $this->validate([
            'enabled' => ['boolean'],
            'hero_title' => ['nullable', 'string', 'max:120'],
            'hero_text' => ['nullable', 'string', 'max:300'],
            'hero_use_login_image' => ['boolean'],
            'featured_release_id' => ['nullable', 'integer', Rule::in($site->releases(true)->pluck('id')->all())],
            'about_title' => ['nullable', 'string', 'max:120'],
            'about_text' => ['nullable', 'string', 'max:5000'],
            'show_releases' => ['boolean'], 'show_upcoming' => ['boolean'], 'show_artists' => ['boolean'],
            'show_about' => ['boolean'], 'show_contact' => ['boolean'], 'show_login_link' => ['boolean'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_address' => ['nullable', 'string', 'max:300'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'socials' => ['array'],
            'socials.*' => ['nullable', 'url:https', 'max:255'],
        ], [
            'socials.*.url' => __('Enter the full https:// address of the page.'),
            'featured_release_id.in' => __('Choose a release that is shown on the website.'),
        ], [
            'featured_release_id' => __('featured release'), 'contact_email' => __('e-mail'), 'meta_description' => __('search engine description'),
        ]);

        $values = [];
        foreach (array_keys(WebsiteContent::DEFAULTS) as $key) {
            if ($key === 'socials') {
                continue;
            }
            $value = $data[$key] ?? WebsiteContent::DEFAULTS[$key];
            $values['website.'.$key] = is_bool(WebsiteContent::DEFAULTS[$key]) ? (bool) $value
                : ($key === 'featured_release_id' ? ($value !== '' && $value !== null ? (int) $value : null) : trim((string) $value));
        }
        $values['website.socials'] = array_filter(array_map(fn ($u) => trim((string) $u), array_intersect_key($data['socials'] ?? [], WebsiteContent::SOCIALS)));
        $settings->setMany($values);

        $activity->log('settings.updated', $this->enabled ? __('Updated the website (on)') : __('Updated the website (off)'));
        $this->dispatch('toast', type: 'success', message: $this->enabled
            ? __('Website saved. It is live at :url', ['url' => url('/')])
            : __('Website saved. It is off: :url opens the sign-in page.', ['url' => url('/')]));
    }

    public function render(WebsiteContent $site): View
    {
        return view('livewire.settings.website', [
            'releases' => $site->releases(true)->orderByDesc('release_date')->get(['id', 'title', 'version', 'release_date']),
            'publicReleases' => $site->releases()->count(),
            'publicArtists' => $site->artists()->count(),
            'networks' => WebsiteContent::SOCIALS,
            'loginImage' => Branding::url('login_image'),
            'homeUrl' => url('/'),
        ])->title(__('Website settings'))->layoutData(['breadcrumbs' => [['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Website')]]]);
    }
}
