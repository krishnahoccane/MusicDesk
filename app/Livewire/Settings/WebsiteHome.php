<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use App\Services\Website\WebsiteContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → Website → Home page: the optional sections of the website's front page
 * (hero buttons, numbers, stores, services, how it works, testimonials, FAQ, closing call to action).
 * Every section stays hidden until it has content.
 */
class WebsiteHome extends Component
{
    use EnforcesLicense;

    /** @var array<string, mixed> */
    public array $home = [];

    /** Repeaters: list => [max rows, empty row] */
    protected const LISTS = [
        'stats' => [4, ['value' => '', 'label' => '']],
        'steps' => [6, ['title' => '', 'text' => '']],
        'testimonials' => [6, ['quote' => '', 'name' => '', 'role' => '']],
        'faq' => [20, ['q' => '', 'a' => '']],
    ];

    public function mount(WebsiteContent $site): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->home = $site->home();
    }

    protected function guard(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    public function addRow(string $list): void
    {
        abort_unless(isset(self::LISTS[$list]), 422);
        [$max, $empty] = self::LISTS[$list];
        $this->home[$list] = array_values((array) ($this->home[$list] ?? []));
        if (count($this->home[$list]) < $max) {
            $this->home[$list][] = $empty;
        }
    }

    public function removeRow(string $list, int $index): void
    {
        abort_unless(isset(self::LISTS[$list]), 422);
        unset($this->home[$list][$index]);
        $this->home[$list] = array_values((array) ($this->home[$list] ?? []));
    }

    public function moveRow(string $list, int $index, int $direction): void
    {
        abort_unless(isset(self::LISTS[$list]), 422);
        $target = $index + ($direction < 0 ? -1 : 1);
        if (isset($this->home[$list][$index], $this->home[$list][$target])) {
            [$this->home[$list][$index], $this->home[$list][$target]] = [$this->home[$list][$target], $this->home[$list][$index]];
        }
    }

    public function save(SettingsService $settings, ActivityLogger $activity): void
    {
        $this->guard();
        $link = ['nullable', 'string', 'max:255', 'regex:/^(https:\/\/|mailto:|\/|#)/i'];

        $this->validate([
            'home.eyebrow' => ['nullable', 'string', 'max:60'],
            'home.cta1_label' => ['nullable', 'string', 'max:40', 'required_with:home.cta1_url'],
            'home.cta1_url' => [...$link, 'required_with:home.cta1_label'],
            'home.cta2_label' => ['nullable', 'string', 'max:40', 'required_with:home.cta2_url'],
            'home.cta2_url' => [...$link, 'required_with:home.cta2_label'],
            'home.stats' => ['array', 'max:4'],
            'home.stats.*.value' => ['required', 'string', 'max:20'],
            'home.stats.*.label' => ['required', 'string', 'max:60'],
            'home.stores_title' => ['nullable', 'string', 'max:120'],
            'home.stores' => ['nullable', 'string', 'max:2000'],
            'home.show_services' => ['boolean'],
            'home.services_title' => ['nullable', 'string', 'max:120'],
            'home.steps_title' => ['nullable', 'string', 'max:120'],
            'home.steps' => ['array', 'max:6'],
            'home.steps.*.title' => ['required', 'string', 'max:80'],
            'home.steps.*.text' => ['nullable', 'string', 'max:400'],
            'home.testimonials_title' => ['nullable', 'string', 'max:120'],
            'home.testimonials' => ['array', 'max:6'],
            'home.testimonials.*.quote' => ['required', 'string', 'max:600'],
            'home.testimonials.*.name' => ['required', 'string', 'max:80'],
            'home.testimonials.*.role' => ['nullable', 'string', 'max:80'],
            'home.faq_title' => ['nullable', 'string', 'max:120'],
            'home.faq' => ['array', 'max:20'],
            'home.faq.*.q' => ['required', 'string', 'max:200'],
            'home.faq.*.a' => ['required', 'string', 'max:2000'],
            'home.cta_title' => ['nullable', 'string', 'max:120', 'required_with:home.cta_label'],
            'home.cta_text' => ['nullable', 'string', 'max:300'],
            'home.cta_label' => ['nullable', 'string', 'max:40', 'required_with:home.cta_url'],
            'home.cta_url' => [...$link, 'required_with:home.cta_label'],
        ], [
            'home.*.regex' => __('Use an https:// link, a mailto: address or a page address starting with /.'),
            'home.*.*.*.required' => __('Fill this in or remove the row.'),
        ]);

        $clean = [];
        foreach (WebsiteContent::HOME_DEFAULTS as $key => $default) {
            $value = $this->home[$key] ?? $default;
            $clean[$key] = match (true) {
                is_bool($default) => (bool) $value,
                is_array($default) => array_values(array_map(fn ($row) => array_map(fn ($v) => trim((string) $v), (array) $row), (array) $value)),
                default => trim((string) $value),
            };
        }
        $settings->set('website.home', $clean, 'json');
        $this->home = $clean;

        $activity->log('settings.updated', __('Updated the website home page'));
        $this->dispatch('toast', type: 'success', message: __('Home page saved.'));
    }

    public function render(WebsiteContent $site): View
    {
        return view('livewire.settings.website-home', [
            'servicesPage' => \App\Models\SitePage::query()->where('type', 'services')->first(),
            'enabled' => $site->enabled(),
        ])->title(__('Website home page'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Website'), 'url' => route('settings.website')], ['label' => __('Home page')],
        ]]);
    }
}
