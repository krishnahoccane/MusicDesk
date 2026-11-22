<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsApplier;
use App\Services\SettingsService;
use App\Support\Appearance as AppearanceSupport;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Settings → Appearance: theme or custom brand colours, font, colour modes,
 * sidebar, logo / favicon, and the sign-in page design and texts.
 */
class Appearance extends Component
{
    use EnforcesLicense, WithFileUploads;

    public string $theme = AppearanceSupport::DEFAULT_THEME;

    public string $modes = 'both';

    public string $default_mode = 'dark';

    public string $sidebar = 'light';

    public string $font = 'inter-tight';

    public bool $custom_colors = false;

    public string $brand_color = '#7c5cff';

    public string $brand_color_2 = '#ff3fd2';

    public string $login_layout = 'spotlight';

    /** @var array<string, string> */
    public array $login = [];

    /** Uploads */
    public $logo = null;

    public $logo_dark = null;

    public $favicon = null;

    public $login_image = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $this->theme = AppearanceSupport::theme();
        $this->modes = AppearanceSupport::modes();
        $this->default_mode = AppearanceSupport::defaultMode()->value;
        $this->sidebar = AppearanceSupport::sidebar();
        $this->font = AppearanceSupport::font();
        $this->custom_colors = AppearanceSupport::brandColor(1) !== null;
        $this->brand_color = AppearanceSupport::brandColor(1) ?? AppearanceSupport::THEMES[$this->theme][3];
        $this->brand_color_2 = AppearanceSupport::brandColor(2) ?? AppearanceSupport::THEMES[$this->theme][4];
        $this->login_layout = AppearanceSupport::loginLayout();
        $this->login = AppearanceSupport::loginTexts();
    }

    public static function rules(): array
    {
        return [
            'theme' => ['required', Rule::in(array_keys(AppearanceSupport::THEMES))],
            'modes' => ['required', Rule::in(AppearanceSupport::MODES)],
            'default_mode' => ['required', Rule::in(['system', 'light', 'dark'])],
            'sidebar' => ['required', Rule::in(AppearanceSupport::SIDEBARS)],
            'font' => ['required', Rule::in(array_keys(AppearanceSupport::FONTS))],
            'custom_colors' => ['boolean'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brand_color_2' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'login_layout' => ['required', Rule::in(array_keys(AppearanceSupport::LOGIN_LAYOUTS))],
            'login.headline' => ['nullable', 'string', 'max:80'],
            'login.accent' => ['nullable', 'string', 'max:40'],
            'login.headline_end' => ['nullable', 'string', 'max:80'],
            'login.subtitle' => ['nullable', 'string', 'max:240'],
            'login.welcome' => ['nullable', 'string', 'max:60'],
            'login.note' => ['nullable', 'string', 'max:240'],
        ];
    }

    public static function uploadRules(): array
    {
        return [
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:'.Branding::MAX_KB['logo']],
            'logo_dark' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:'.Branding::MAX_KB['logo_dark']],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,svg', 'max:'.Branding::MAX_KB['favicon']],
            'login_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.Branding::MAX_KB['login_image']],
        ];
    }

    public function removeFile(string $kind, SettingsService $settings, SettingsApplier $applier): void
    {
        $this->guard();
        abort_unless(array_key_exists($kind, Branding::KINDS), 404);

        Branding::delete($settings->get('appearance.'.$kind));
        $settings->set('appearance.'.$kind, null);
        $applier->apply();
        $this->dispatch('toast', type: 'success', message: __('Removed.'));
    }

    protected function guard(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    public function save(SettingsService $settings, SettingsApplier $applier, ActivityLogger $activity)
    {
        $this->guard();
        $data = $this->validate(self::rules() + self::uploadRules());

        $values = [
            'appearance.theme' => $data['theme'],
            'appearance.modes' => $data['modes'],
            'appearance.default_mode' => $data['default_mode'],
            'appearance.sidebar' => $data['sidebar'],
            'appearance.font' => $data['font'],
            'appearance.brand_color' => $this->custom_colors ? strtolower($data['brand_color']) : null,
            'appearance.brand_color_2' => $this->custom_colors ? strtolower($data['brand_color_2']) : null,
            'appearance.login_layout' => $data['login_layout'],
        ];
        foreach (array_keys(AppearanceSupport::loginTextDefaults()) as $key) {
            $value = trim((string) ($this->login[$key] ?? ''));
            $values['appearance.login_'.$key] = $value === AppearanceSupport::loginTextDefaults()[$key] ? null : $value;
        }

        try {
            foreach (array_keys(Branding::KINDS) as $kind) {
                if ($this->{$kind}) {
                    $name = Branding::store($kind, $this->{$kind});
                    Branding::delete($settings->get('appearance.'.$kind));
                    $values['appearance.'.$kind] = $name;
                }
            }
        } catch (RuntimeException $e) {
            $this->addError('logo', $e->getMessage());

            return null;
        }

        $settings->setMany($values);
        $applier->apply();
        $activity->log('settings.updated', __('Updated appearance settings'));

        session()->flash('success', __('Appearance saved. Everyone sees the new look on their next page.'));

        // Full reload so the <html> attributes and brand CSS apply everywhere.
        return $this->redirectRoute('settings.appearance');
    }

    public function render(): View
    {
        return view('livewire.settings.appearance', [
            'themes' => AppearanceSupport::THEMES,
            'fonts' => AppearanceSupport::FONTS,
            'layouts' => AppearanceSupport::LOGIN_LAYOUTS,
            'files' => collect(array_keys(Branding::KINDS))->mapWithKeys(fn ($k) => [$k => Branding::url($k)])->all(),
            'previewCss' => $this->custom_colors && AppearanceSupport::validHex($this->brand_color) && AppearanceSupport::validHex($this->brand_color_2)
                ? sprintf('--ld-accent:%s;--ld-accent-rgb:%s;--ld-accent-2:%s;--ld-accent-2-rgb:%s;',
                    $this->brand_color, implode(',', AppearanceSupport::rgb($this->brand_color)),
                    $this->brand_color_2, implode(',', AppearanceSupport::rgb($this->brand_color_2)))
                : '',
        ])->title(__('Appearance'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Appearance')],
        ]]);
    }
}
