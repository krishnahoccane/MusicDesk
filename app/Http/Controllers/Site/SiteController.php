<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Release;
use App\Services\ArtistService;
use App\Services\Catalog\ReleaseService;
use App\Services\Website\WebsiteContent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The label's public website (when switched on in Settings → Website).
 */
class SiteController extends Controller
{
    public function __construct(protected WebsiteContent $site) {}

    protected function ensureEnabled(): void
    {
        abort_unless($this->site->enabled(), 404);
    }

    /** "/": the website when it is on, otherwise straight to sign-in (or the dashboard). */
    public function home(): View|RedirectResponse
    {
        if (! $this->site->enabled()) {
            return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
        }

        $settings = $this->site->settings();

        return view('site.home', [
            'settings' => $settings,
            'featured' => $this->site->featured(),
            'latest' => $settings['show_releases'] ? $this->site->latest(8) : collect(),
            'upcoming' => $settings['show_releases'] ? $this->site->upcoming(4) : collect(),
            'artists' => $settings['show_artists'] ? $this->site->artists()->limit(12)->get() : collect(),
            'releaseCount' => $this->site->releases()->count(),
            'artistCount' => $this->site->artists()->count(),
        ]);
    }

    public function music(): View
    {
        $this->ensureEnabled();
        abort_unless($this->site->settings()['show_releases'], 404);

        return view('site.music', [
            'settings' => $this->site->settings(),
            'releases' => $this->site->releases()->orderByDesc('release_date')->orderByDesc('id')->paginate(24),
            'upcoming' => $this->site->upcoming(8),
        ]);
    }

    public function release(string $slug): View|RedirectResponse
    {
        $this->ensureEnabled();
        $release = Release::query()->withoutGlobalScope('artist-account')->find((int) $slug);
        abort_unless($release && $this->site->isPublic($release), 404);
        if ($slug !== WebsiteContent::releaseSlug($release)) {
            return redirect()->route('site.release', WebsiteContent::releaseSlug($release), 301);
        }

        $release->load([
            'artists' => fn ($q) => $q->withoutGlobalScope('artist-account'),
            'tracks' => fn ($q) => $q->withoutGlobalScope('artist-account')->with(['artists' => fn ($a) => $a->withoutGlobalScope('artist-account')]),
            'genre', 'language',
        ]);

        return view('site.release', [
            'settings' => $this->site->settings(),
            'release' => $release,
            'more' => $this->site->releases()->whereKeyNot($release->id)
                ->whereHas('artists', fn ($q) => $q->withoutGlobalScope('artist-account')->whereIn('artists.id', $release->artists->pluck('id')))
                ->orderByDesc('release_date')->limit(4)->get(),
            'upcoming' => $release->release_date?->isFuture() ?? false,
        ]);
    }

    public function roster(): View
    {
        $this->ensureEnabled();
        abort_unless($this->site->settings()['show_artists'], 404);

        return view('site.roster', ['settings' => $this->site->settings(), 'artists' => $this->site->artists()->get()]);
    }

    public function artist(string $slug): View|RedirectResponse
    {
        $this->ensureEnabled();
        abort_unless($this->site->settings()['show_artists'], 404);
        $artist = Artist::query()->withoutGlobalScope('artist-account')->find((int) $slug);
        abort_unless($artist && $this->site->isPublicArtist($artist), 404);
        if ($slug !== WebsiteContent::artistSlug($artist)) {
            return redirect()->route('site.artist', WebsiteContent::artistSlug($artist), 301);
        }

        return view('site.artist', [
            'settings' => $this->site->settings(),
            'artist' => $artist,
            'links' => $artist->links()->withoutGlobalScope('artist-account')->get(),
            'releases' => $this->site->releases((bool) $this->site->settings()['show_upcoming'])
                ->whereHas('artists', fn ($q) => $q->withoutGlobalScope('artist-account')->where('artists.id', $artist->id))
                ->orderByDesc('release_date')->get(),
        ]);
    }

    // ------------------------------------------------------------ images (only of what the website shows)

    public function artwork(string $release, string $size = 'thumb'): Response
    {
        $this->ensureEnabled();
        $model = Release::query()->withoutGlobalScope('artist-account')->find((int) $release);
        abort_unless($model && $this->site->isPublic($model), 404);

        $path = $size === 'full' ? $model->artwork_path : ($model->artwork_thumb_path ?? $model->artwork_path);

        return $this->image(ReleaseService::DISK, $path);
    }

    public function photo(string $artist): Response
    {
        $this->ensureEnabled();
        $model = Artist::query()->withoutGlobalScope('artist-account')->find((int) $artist);
        abort_unless($model && $this->site->isPublicArtist($model), 404);

        return $this->image(ArtistService::DISK, $model->photo_path);
    }

    protected function image(string $disk, ?string $path): Response
    {
        $storage = Storage::disk($disk);
        abort_unless($path && $storage->exists($path), 404);

        return $storage->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
