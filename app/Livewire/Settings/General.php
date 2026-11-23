<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\ActivityLog;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsApplier;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → General: label name, regional formats and log retention.
 */
class General extends Component
{
    use EnforcesLicense;

    public string $app_name = '';

    public string $timezone = 'UTC';

    public string $currency = 'USD';

    public string $locale = 'en';

    public string $date_format = 'd M Y';

    public string $time_format = 'H:i';

    public int|string $retention_days = ActivityLog::DEFAULT_RETENTION_DAYS;

    /** Sign-up on the sign-in page (off by default). */
    public bool $registration = false;

    public bool $registration_artists = true;

    public bool $registration_labels = true;

    public function mount(SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $this->app_name = (string) $settings->get('general.app_name', config('app.name'));
        $this->timezone = (string) $settings->get('general.timezone', config('labeldesk.display_timezone'));
        $this->currency = (string) $settings->get('general.currency', config('labeldesk.currency'));
        $this->locale = (string) $settings->get('general.locale', config('app.locale'));
        $this->date_format = (string) $settings->get('general.date_format', config('labeldesk.date_format'));
        $this->time_format = (string) $settings->get('general.time_format', 'H:i');
        $this->retention_days = (int) $settings->get('activity.retention_days', ActivityLog::DEFAULT_RETENTION_DAYS);
        $this->registration = (bool) $settings->get('auth.registration', false);
        $this->registration_artists = (bool) $settings->get('auth.registration_artists', true);
        $this->registration_labels = (bool) $settings->get('auth.registration_labels', true);
    }

    public function save(SettingsService $settings, SettingsApplier $applier, ActivityLogger $activity): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();

        $this->validate([
            'app_name' => ['required', 'string', 'max:80'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', Rule::in((array) config('labeldesk.installer.currencies'))],
            'locale' => ['required', Rule::in(array_keys((array) config('labeldesk.installer.locales')))],
            'date_format' => ['required', Rule::in(array_keys(SettingsApplier::DATE_FORMATS))],
            'time_format' => ['required', Rule::in(array_keys(SettingsApplier::TIME_FORMATS))],
            'retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'registration' => ['boolean'],
            'registration_artists' => ['boolean'],
            'registration_labels' => ['boolean'],
        ], [], ['app_name' => __('label name'), 'retention_days' => __('retention period')]);

        if ($this->registration && ! $this->registration_artists && ! $this->registration_labels) {
            $this->addError('registration_artists', __('Choose who may sign up, or turn sign-up off.'));

            return;
        }

        $values = [
            'general.app_name' => trim($this->app_name),
            'general.timezone' => $this->timezone,
            'general.currency' => $this->currency,
            'general.locale' => $this->locale,
            'general.date_format' => $this->date_format,
            'general.time_format' => $this->time_format,
            'activity.retention_days' => (int) $this->retention_days,
            'auth.registration' => $this->registration,
            'auth.registration_artists' => $this->registration_artists,
            'auth.registration_labels' => $this->registration_labels,
        ];

        $before = $settings->all();
        $settings->setMany($values);
        $applier->apply();

        $changed = collect($values)->filter(fn ($v, $k) => ($before[$k] ?? null) !== $v)
            ->map(fn ($v, $k) => [$before[$k] ?? null, $v])->all();
        if ($changed) {
            $activity->log('settings.updated', __('Updated general settings'), null, $changed);
        }

        $this->dispatch('toast', message: __('Settings saved.'), type: 'success');
    }

    public function render(): View
    {
        $now = now();

        return view('livewire.settings.general', [
            'timezones' => timezone_identifiers_list(),
            'currencies' => (array) config('labeldesk.installer.currencies'),
            'locales' => (array) config('labeldesk.installer.locales'),
            'dateFormats' => collect(SettingsApplier::DATE_FORMATS)->mapWithKeys(fn ($_, $f) => [$f => $now->format($f)]),
            'timeFormats' => SettingsApplier::TIME_FORMATS,
        ])->title(__('Settings'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings')], ['label' => __('General')],
        ]]);
    }
}
