<?php

namespace App\Services\Website;

use App\Enums\ArtistStatus;
use App\Enums\ReleaseStatus;
use App\Models\Artist;
use App\Models\Release;
use App\Models\SitePage;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The label's public website (Settings → Website). Off by default: then "/" opens the
 * sign-in page as before, for labels that run their own website.
 *
 * Only public catalogue data is ever shown: approved / ready releases whose release
 * date has come (and, if chosen, upcoming ones), and active artists with such a release.
 * Never shown: e-mail addresses, phone numbers, notes, legal names, contracts,
 * royalties, audio files, drafts or releases still in review.
 */
class WebsiteContent
{
    public const SOCIALS = [
        'instagram' => 'Instagram', 'youtube' => 'YouTube', 'spotify' => 'Spotify', 'apple_music' => 'Apple Music',
        'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'x' => 'X', 'soundcloud' => 'SoundCloud',
    ];

    /**
     * Website themes: key => [name, description, forced colour mode or null, preview background, preview text].
     * Each changes type, shapes, backgrounds and the hero; brand colours and logo come from Appearance.
     */
    public const THEMES = [
        'aurora' => ['Aurora', 'Soft glowing gradients in your brand colours.', null, '#0d0b1e', '#ffffff'],
        'minimal' => ['Minimal', 'Clean white space, crisp black type.', 'light', '#ffffff', '#111111'],
        'noir' => ['Noir', 'Deep black, high contrast, sharp edges.', 'dark', '#000000', '#f5f5f5'],
        'editorial' => ['Editorial', 'Serif headlines and a magazine layout.', null, '#f7f3ec', '#1c1917'],
        'neon' => ['Neon', 'Dark stage with glowing outlines.', 'dark', '#05030f', '#e9e6ff'],
        'vinyl' => ['Vinyl', 'Warm paper tones and round record covers.', 'light', '#f3ead9', '#2b2118'],
        'studio' => ['Studio', 'Frosted glass panels over colour.', null, '#1d2433', '#f1f5f9'],
        'bold' => ['Bold', 'Thick borders, square corners, huge type.', null, '#fffb00', '#000000'],
        'pastel' => ['Pastel', 'Gentle tints and rounded shapes.', 'light', '#fdf2f8', '#3b2a4a'],
        'cinema' => ['Cinema', 'Full-width dramatic hero, dark and cinematic.', 'dark', '#0a0a0a', '#ffffff'],
    ];

    public const DEFAULTS = [
        'enabled' => false,
        'theme' => 'aurora',
        'hero_title' => '',
        'hero_text' => '',
        'about_title' => '',
        'about_text' => '',
        'show_releases' => true,
        'show_upcoming' => true,
        'show_artists' => true,
        'show_about' => true,
        'show_contact' => true,
        'show_login_link' => true,
        'contact_email' => '',
        'contact_phone' => '',
        'contact_address' => '',
        'featured_release_id' => null,
        'hero_use_login_image' => false,
        'meta_description' => '',
        'socials' => [],
    ];

    public function __construct(protected SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('website.enabled', false);
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = $this->settings->get('website.'.$key, $default);
        }
        $out['socials'] = array_intersect_key(is_array($out['socials']) ? $out['socials'] : [], self::SOCIALS);

        return $out;
    }

    public function theme(): string
    {
        $theme = (string) $this->settings->get('website.theme', 'aurora');

        return array_key_exists($theme, self::THEMES) ? $theme : 'aurora';
    }

    /** Published pages shown in the menu, in order. */
    public function menuPages(): Collection
    {
        try {
            return SitePage::query()->published()->where('show_in_menu', true)->orderBy('sort')->orderBy('id')->get();
        } catch (\Throwable) {
            return collect(); // table not there yet (before the database update)
        }
    }

    /** The built-in pages exist (unpublished and empty until the label fills them in). */
    public function ensureBuiltInPages(): void
    {
        $sort = 0;
        foreach (SitePage::BUILT_IN as $type => [$slug, $title]) {
            $sort += 10;
            SitePage::query()->firstOrCreate(['type' => $type], [
                'slug' => $slug, 'title' => __($title), 'content' => $type === 'contact' ? ['form' => true] : [],
                'is_published' => false, 'show_in_menu' => true, 'sort' => $sort,
            ]);
        }
    }

    /** Optional home page sections (Settings → Website → Home page); nothing is shown until filled in. */
    public const HOME_DEFAULTS = [
        'eyebrow' => '', 'cta1_label' => '', 'cta1_url' => '', 'cta2_label' => '', 'cta2_url' => '',
        'stats' => [], 'stores_title' => '', 'stores' => '', 'show_services' => true, 'services_title' => '',
        'steps_title' => '', 'steps' => [], 'testimonials_title' => '', 'testimonials' => [],
        'faq_title' => '', 'faq' => [], 'cta_title' => '', 'cta_text' => '', 'cta_label' => '', 'cta_url' => '',
    ];

    /** @return array<string, mixed> */
    public function home(): array
    {
        $saved = $this->settings->get('website.home', []);

        return array_merge(self::HOME_DEFAULTS, array_intersect_key(is_array($saved) ? $saved : [], self::HOME_DEFAULTS));
    }

    /** Store names typed one per line or separated by commas. @return list<string> */
    public static function storeNames(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text) ?: []))));
    }

    public function heroTitle(): string
    {
        return (string) ($this->settings()['hero_title'] ?: config('app.name'));
    }

    // ------------------------------------------------------------ public catalogue

    /** Releases that may be shown (artist-account scope off: the website is the same for every visitor). */
    public function releases(bool $withUpcoming = false): Builder
    {
        return Release::query()->withoutGlobalScope('artist-account')
            ->whereIn('status', [ReleaseStatus::Approved->value, ReleaseStatus::Ready->value])
            ->whereNotNull('release_date')
            ->when(! $withUpcoming, fn ($q) => $q->whereDate('release_date', '<=', now()->toDateString()))
            ->with(['artists' => fn ($q) => $q->withoutGlobalScope('artist-account'), 'genre']);
    }

    /** @return Collection<int, Release> */
    public function latest(int $limit = 8): Collection
    {
        return $this->releases()->orderByDesc('release_date')->orderByDesc('id')->limit($limit)->get();
    }

    /** @return Collection<int, Release> */
    public function upcoming(int $limit = 4): Collection
    {
        if (! $this->settings()['show_upcoming']) {
            return collect();
        }

        return $this->releases(true)->whereDate('release_date', '>', now()->toDateString())->orderBy('release_date')->limit($limit)->get();
    }

    public function featured(): ?Release
    {
        $id = (int) $this->settings()['featured_release_id'];
        $release = $id > 0 ? $this->releases((bool) $this->settings()['show_upcoming'])->find($id) : null;

        return $release ?? $this->latest(1)->first();
    }

    public function isPublic(Release $release): bool
    {
        return $this->releases((bool) $this->settings()['show_upcoming'])->whereKey($release->id)->exists();
    }

    /** Active artists with at least one public release. */
    public function artists(): Builder
    {
        $ids = $this->releases((bool) $this->settings()['show_upcoming'])->with([])->pluck('id');

        return Artist::query()->withoutGlobalScope('artist-account')
            ->where('status', ArtistStatus::Active->value)
            ->whereHas('releases', fn ($q) => $q->withoutGlobalScope('artist-account')->whereIn('releases.id', $ids))
            ->orderBy('name');
    }

    public function isPublicArtist(Artist $artist): bool
    {
        return $this->artists()->whereKey($artist->id)->exists();
    }

    // ------------------------------------------------------------ links

    public static function releaseSlug(Release $release): string
    {
        return $release->id.'-'.(Str::slug($release->title) ?: 'release');
    }

    public static function artistSlug(Artist $artist): string
    {
        return $artist->id.'-'.(Str::slug($artist->name) ?: 'artist');
    }
}
