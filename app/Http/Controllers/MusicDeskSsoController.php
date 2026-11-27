<?php

namespace App\Http\Controllers;

use App\Exceptions\SupportSsoException;
use App\Services\Activity\ActivityLogger;
use App\Services\License\LicenseService;
use App\Services\Security\SupportSsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * POST /musicdesk/sso — MusicDesk support signs in as the site's Super Admin with a one-time,
 * MusicDesk.tech-signed token (see App\Services\Security\SupportSsoService). It works whatever the
 * licence state is, so support can fix a locked or unlicensed site. Never accepts the token by GET.
 */
class MusicDeskSsoController extends Controller
{
    public function __construct(protected SupportSsoService $sso, protected ActivityLogger $activity, protected LicenseService $license) {}

    public function store(Request $request): RedirectResponse|Response
    {
        if (! $this->sso->enabled()) {
            return $this->deny(__('Support sign-in is turned off on this site.'));
        }

        try {
            $claims = $this->sso->verify((string) $request->input('token', ''));
        } catch (SupportSsoException $e) {
            Log::warning('MusicDesk support sign-in refused', ['reason' => $e->reason, 'ip' => $request->ip()]);

            return $this->deny($e->getMessage());
        }

        $admin = $this->sso->supportAdmin();
        if (! $admin) {
            return $this->deny(__('There is no active Super Admin on this site to sign in as.'));
        }

        $by = trim((string) ($claims['by'] ?? '')) ?: __('MusicDesk support');
        $byEmail = trim((string) ($claims['by_email'] ?? ''));

        // Sign in without "remember me" and without touching the Super Admin's last-login, password or 2FA.
        Auth::login($admin, remember: false);
        $request->session()->regenerate();
        $request->session()->put('musicdesk_support', [
            'by' => mb_substr($by, 0, 120),
            'by_email' => mb_substr($byEmail, 0, 160),
            'until' => now()->addMinutes(SupportSsoService::SESSION_MINUTES)->timestamp,
        ]);

        $this->activity->log('security.support_signin', __('MusicDesk support (:by, :email) signed in as :user', [
            'by' => $by, 'email' => $byEmail ?: '—', 'user' => $admin->name,
        ]), $admin, array_filter([
            'ip' => $request->ip(),
            'license' => $claims['lic'] ?? null,
            'addon' => $claims['addon'] ?? null,
            'reason' => isset($claims['reason']) ? mb_substr((string) $claims['reason'], 0, 300) : null,
        ], fn ($v) => $v !== null), $admin);

        if ($this->sso->emailsNotice() && filter_var($admin->email, FILTER_VALIDATE_EMAIL)) {
            $this->notify($admin->email, $admin->name, $by);
        }

        // Never redirect to a URL from the request. A site that is read-only or locked lands on the licence page.
        $usable = rescue(fn () => $this->license->snapshot()->allowsWrites(), false, report: false);

        return redirect()->route($usable ? 'dashboard' : 'license.show');
    }

    protected function notify(string $to, string $name, string $by): void
    {
        try {
            Mail::raw(implode("\n", [
                __('Hello :name,', ['name' => $name]),
                '',
                __(':by from MusicDesk support signed in to your LabelDesk on :date to help with a support request.', [
                    'by' => $by, 'date' => now()->setTimezone((string) config('labeldesk.display_timezone'))->format('d M Y, H:i'),
                ]),
                __('If you did not expect this, you can turn support sign-in off in Settings → Security and contact MusicDesk.tech.'),
            ]), fn ($m) => $m->to($to)->subject(__('MusicDesk support signed in to your :app', ['app' => config('app.name')])));
        } catch (Throwable $e) {
            Log::warning('Support sign-in notice could not be e-mailed', ['error' => $e->getMessage()]);
        }
    }

    protected function deny(string $message): Response
    {
        return response()->view('errors.support-sso', ['message' => $message], 403);
    }
}
