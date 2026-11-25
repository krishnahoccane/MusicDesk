<?php

namespace App\Services\Website;

use App\Enums\ArtistStatus;
use App\Enums\ReleaseStatus;
use App\Models\Artist;
use App\Models\Release;
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

    public const DEFAULTS = [
        'enabled' => false,
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
