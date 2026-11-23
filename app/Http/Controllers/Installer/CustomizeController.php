<?php

namespace App\Http\Controllers\Installer;

use App\Livewire\Settings\Appearance as AppearanceSettings;
use App\Services\SettingsApplier;
use App\Support\Appearance;
use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Step 6: everything about how the site looks and reads, in one place —
 * site name and regional settings, colour theme or custom brand colours,
 * font, light/dark policy, sidebar, logo, favicon, and the sign-in page
 * design and texts. All of it can be changed later in Settings.
 */
class CustomizeController extends InstallerController
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard('customize')) {
            return $redirect;
        }

        return view('installer.customize', [
            'step' => 'customize',
            'v' => $this->values($request),
            'url' => (string) ($this->wizard->get('site')['url'] ?? ''),
            'files' => $this->fileUrls(),
            'themes' => Appearance::THEMES,
            'fonts' => Appearance::FONTS,
            'layouts' => Appearance::LOGIN_LAYOUTS,
            'timezones' => timezone_identifiers_list(),
            'currencies' => (array) config('labeldesk.installer.currencies'),
            'locales' => (array) config('labeldesk.installer.locales'),
            'dateFormats' => SettingsApplier::DATE_FORMATS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard('customize')) {
            return $redirect;
        }

        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'not_regex:/[<>"\r\n]/'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', 'string', Rule::in((array) config('labeldesk.installer.currencies'))],
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('labeldesk.installer.locales')))],
            'date_format' => ['required', Rule::in(array_keys(SettingsApplier::DATE_FORMATS))],
            'remove' => ['array'],
            'remove.*' => [Rule::in(array_keys(Branding::KINDS))],
        ] + AppearanceSettings::rules() + AppearanceSettings::uploadRules(), [], ['name' => __('site name')]);

        $files = (array) ($this->wizard->get('customize')['files'] ?? []);

        try {
            foreach (array_keys(Branding::KINDS) as $kind) {
                if (in_array($kind, (array) $request->input('remove', []), true)) {
                    Branding::delete($files[$kind] ?? null);
                    unset($files[$kind]);
                }
                if ($request->hasFile($kind)) {
                    $name = Branding::store($kind, $request->file($kind));
                    Branding::delete($files[$kind] ?? null);
                    $files[$kind] = $name;
                }
            }
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['logo' => $e->getMessage()]);
        }

        $custom = $request->boolean('custom_colors');
        $login = [];
        foreach (array_keys(Appearance::loginTextDefaults()) as $key) {
            $login[$key] = trim((string) ($data['login'][$key] ?? ''));
        }

        $this->wizard->put('customize', [
            'theme' => $data['theme'],
            'custom_colors' => $custom,
            'brand_color' => strtolower($data['brand_color']),
            'brand_color_2' => strtolower($data['brand_color_2']),
            'font' => $data['font'],
            'modes' => $data['modes'],
            'default_mode' => $data['default_mode'],
            'sidebar' => $data['sidebar'],
            'login_layout' => $data['login_layout'],
            'login' => $login,
            'registration' => $request->boolean('registration'),
            'files' => $files,
            'date_format' => $data['date_format'],
        ]);

        $this->wizard->put('application', [
            'name' => $data['name'],
            'url' => (string) ($this->wizard->get('site')['url'] ?? ''),
            'timezone' => $data['timezone'],
            'currency' => $data['currency'],
            'locale' => $data['locale'],
        ]);

        if ($request->input('action') === 'preview') {
            return redirect()->route('installer.customize.preview', $data['login_layout']);
        }

        return $this->next('customize');
    }

    /**
     * Sign-in page preview with the choices saved in this step.
     */
    public function preview(string $design): View|RedirectResponse
    {
        if ($redirect = $this->guard('customize')) {
            return $redirect;
        }
        abort_unless(array_key_exists($design, Appearance::LOGIN_LAYOUTS), 404);

        $this->applyToConfig();
        $app = $this->wizard->get('application');
        if (! empty($app['name'])) {
            config(['app.name' => $app['name']]);
        }

        return view('auth.login', ['design' => $design, 'preview' => true]);
    }

    /**
     * Wizard choices → config('labeldesk.appearance'), as SettingsApplier does after installation.
     */
    protected function applyToConfig(): void
    {
        $c = $this->wizard->get('customize');
        if ($c === []) {
            return;
        }

        $values = [
            'theme' => $c['theme'], 'font' => $c['font'], 'modes' => $c['modes'], 'default_mode' => $c['default_mode'],
            'sidebar' => $c['sidebar'], 'login_layout' => $c['login_layout'],
            'brand_color' => $c['custom_colors'] ? $c['brand_color'] : null,
            'brand_color_2' => $c['custom_colors'] ? $c['brand_color_2'] : null,
        ];
        foreach (($c['login'] ?? []) as $key => $value) {
            $values['login_'.$key] = $value;
        }
        foreach (array_keys(Branding::KINDS) as $kind) {
            $values[$kind] = $c['files'][$kind] ?? null;
        }

        foreach ($values as $key => $value) {
            config(['labeldesk.appearance.'.$key => $value]);
        }
    }

    protected function values(Request $request): array
    {
        $c = $this->wizard->get('customize');
        $app = $this->wizard->get('application');
        $defaults = Appearance::THEMES[Appearance::DEFAULT_THEME];

        return [
            'name' => $app['name'] ?? 'LabelDesk',
            'timezone' => $app['timezone'] ?? 'UTC',
            'currency' => $app['currency'] ?? 'USD',
            'locale' => $app['locale'] ?? 'en',
            'date_format' => $c['date_format'] ?? 'd M Y',
            'theme' => $c['theme'] ?? Appearance::DEFAULT_THEME,
            'custom_colors' => (bool) ($c['custom_colors'] ?? false),
            'brand_color' => $c['brand_color'] ?? $defaults[3],
            'brand_color_2' => $c['brand_color_2'] ?? $defaults[4],
            'font' => $c['font'] ?? 'inter-tight',
            'modes' => $c['modes'] ?? 'both',
            'default_mode' => $c['default_mode'] ?? 'dark',
            'sidebar' => $c['sidebar'] ?? 'light',
            'login_layout' => $c['login_layout'] ?? 'spotlight',
            'login' => ($c['login'] ?? []) + Appearance::loginTextDefaults(),
            'registration' => (bool) ($c['registration'] ?? false),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    protected function fileUrls(): array
    {
        $files = (array) ($this->wizard->get('customize')['files'] ?? []);

        return collect(array_keys(Branding::KINDS))->mapWithKeys(fn ($kind) => [
            $kind => ! empty($files[$kind]) && preg_match(Branding::NAME_PATTERN, $files[$kind]) ? route('branding.file', $files[$kind]) : null,
        ])->all();
    }
}
