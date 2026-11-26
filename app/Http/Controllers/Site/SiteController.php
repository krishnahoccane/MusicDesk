<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Release;
use App\Models\SiteMessage;
use App\Models\SitePage;
use App\Services\ArtistService;
use App\Services\Catalog\ReleaseService;
use App\Services\Website\WebsiteContent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        $services = SitePage::query()->published()->where('type', 'services')->first();

        return view('site.home', [
            'settings' => $settings,
            'home' => $this->site->home(),
            'servicesPage' => $services,
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

    // ------------------------------------------------------------ pages

    public function about(): View
    {
        return $this->builtIn('about');
    }

    public function services(): View
    {
        return $this->builtIn('services');
    }

    public function pricing(): View
    {
        return $this->builtIn('pricing');
    }

    public function contact(): View
    {
        return $this->builtIn('contact');
    }

    public function page(string $slug): View
    {
        $this->ensureEnabled();
        $page = SitePage::query()->published()->where('type', 'custom')->where('slug', $slug)->first();
        abort_unless($page, 404);

        return $this->show($page);
    }

    protected function builtIn(string $type): View
    {
        $this->ensureEnabled();
        $page = SitePage::query()->published()->where('type', $type)->first();
        abort_unless($page, 404);

        return $this->show($page);
    }

    protected function show(SitePage $page): View
    {
        return view('site.page', ['settings' => $this->site->settings(), 'page' => $page]);
    }

    /** Contact form: kept in LabelDesk and e-mailed to the label's public address. */
    public function send(Request $request): RedirectResponse
    {
        $this->ensureEnabled();
        $page = SitePage::query()->published()->where('type', 'contact')->first();
        abort_unless($page && ($page->content['form'] ?? true), 404);

        // Left empty by people; filled in by bots.
        if (filled($request->input('website_url'))) {
            return redirect()->route('site.contact')->with('sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $message = SiteMessage::query()->create($data + ['ip' => $request->ip()]);

        $to = (string) ($this->site->settings()['contact_email'] ?? '');
        if ($to !== '') {
            try {
                Mail::raw(implode("\n", [
                    __('New message from your website :site', ['site' => url('/')]),
                    '',
                    __('Name: :name', ['name' => $data['name']]),
                    __('E-mail: :email', ['email' => $data['email']]),
                    __('Subject: :subject', ['subject' => $data['subject'] ?? '—']),
                    '',
                    $data['message'],
                ]), fn ($m) => $m->to($to)->replyTo($data['email'], $data['name'])
                    ->subject(__('Website message: :subject', ['subject' => $data['subject'] ?: $data['name']])));
                $message->forceFill(['mailed' => true])->save();
            } catch (\Throwable $e) {
                Log::warning('Website message could not be e-mailed', ['error' => $e->getMessage()]);
            }
        }

        return redirect()->route('site.contact')->with('sent', true);
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
