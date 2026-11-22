<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsApplier;
use App\Services\SettingsService;
use App\Support\Appearance as AppearanceSupport;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → Appearance: colour theme, allowed colour modes, default mode
 * and sidebar style for everyone in this installation.
 */
class Appearance extends Component
{
    use EnforcesLicense;

    public string $theme = 'aurora';

    public string $modes = 'both';

    public string $default_mode = 'system';

    public string $sidebar = 'light';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $this->theme = AppearanceSupport::theme();
        $this->modes = AppearanceSupport::modes();
        $this->default_mode = AppearanceSupport::defaultMode()->value;
        $this->sidebar = AppearanceSupport::sidebar();
    }

    public function save(SettingsService $settings, SettingsApplier $applier, ActivityLogger $activity)
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();

        $data = $this->validate([
            'theme' => ['required', Rule::in(array_keys(AppearanceSupport::THEMES))],
            'modes' => ['required', Rule::in(AppearanceSupport::MODES)],
            'default_mode' => ['required', Rule::in(['system', 'light', 'dark'])],
            'sidebar' => ['required', Rule::in(AppearanceSupport::SIDEBARS)],
        ]);

        $before = [
            'theme' => AppearanceSupport::theme(), 'modes' => AppearanceSupport::modes(),
            'default_mode' => AppearanceSupport::defaultMode()->value, 'sidebar' => AppearanceSupport::sidebar(),
        ];

        $settings->setMany([
            'appearance.theme' => $data['theme'],
            'appearance.modes' => $data['modes'],
            'appearance.default_mode' => $data['default_mode'],
            'appearance.sidebar' => $data['sidebar'],
        ]);
        $applier->apply();

        $changes = collect($data)->filter(fn ($v, $k) => $before[$k] !== $v)->map(fn ($v, $k) => [$before[$k], $v])->all();
        if ($changes) {
            $activity->log('settings.updated', __('Updated appearance settings'), null, $changes);
        }

        session()->flash('success', __('Appearance saved. Everyone sees the new look on their next page.'));

        // Full reload so the <html> theme attributes are applied everywhere.
        return $this->redirectRoute('settings.appearance');
    }

    public function render(): View
    {
        return view('livewire.settings.appearance', [
            'themes' => AppearanceSupport::THEMES,
        ])->title(__('Appearance'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Appearance')],
        ]]);
    }
}
