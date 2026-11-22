<?php

namespace App\Http\Controllers\Installer;

use App\Services\Installation\InstallationService;
use App\Services\License\Data\ActivationContext;
use App\Support\Appearance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class InstallController extends InstallerController
{
    public function show(): View|RedirectResponse
    {
        if ($redirect = $this->guard('install')) {
            return $redirect;
        }

        $db = $this->wizard->get('database');

        return view('installer.install', [
            'step' => 'install',
            'database' => collect($db)->only(['host', 'port', 'database', 'username', 'prefix', 'server', 'version', 'resuming'])->all(),
            'application' => $this->wizard->get('application'),
            'administrator' => collect($this->wizard->get('administrator'))->only(['name', 'email'])->all(),
            'license' => ($this->wizard->get('license')['summary'] ?? []) + ['domain' => ActivationContext::domainFrom((string) ($this->wizard->get('site')['url'] ?? ''))],
            'redis' => collect($this->wizard->get('redis'))->except('password')->all(),
            'mail' => collect($this->wizard->get('mail'))->except('password')->all(),
            'appearanceSummary' => $this->appearanceSummary(),
            'lastError' => $this->state->progress()['last_error'] ?? null,
        ]);
    }

    public function store(Request $request, InstallationService $installer): RedirectResponse
    {
        if ($redirect = $this->guard('install')) {
            return $redirect;
        }

        @set_time_limit(300);

        $db = $this->wizard->get('database');
        $db['password'] = $this->wizard->decrypt($db['password'] ?? null);
        $license = $this->wizard->get('license');
        $redis = $this->wizard->get('redis');
        $redis['password'] = $this->wizard->decrypt($redis['password'] ?? null);
        $mail = $this->wizard->get('mail');
        $mail['password'] = $this->wizard->decrypt($mail['password'] ?? null);

        try {
            $result = $installer->install(
                $db,
                $this->wizard->get('application'),
                $this->wizard->get('administrator'),
                [
                    'key' => (string) $this->wizard->decrypt($license['key'] ?? null),
                    'provider' => (string) ($license['provider'] ?? ''),
                    'payload' => (string) ($license['payload'] ?? ''),
                    'signature' => (string) ($license['signature'] ?? ''),
                    'messages' => $license['messages'] ?? [],
                    'offline' => (bool) ($license['offline'] ?? false),
                    'plan' => $license['summary']['plan'] ?? null,
                ],
                [
                    'redis' => $redis,
                    'mail' => $mail,
                    'customize' => $this->wizard->get('customize'),
                ],
            );
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('installer.install')->withErrors([
                'install' => __('Installation did not complete: :message. No data has been removed; fix the problem and run the installation again to resume.', [
                    'message' => $this->safeMessage($e),
                ]),
            ]);
        }

        $this->wizard->forget();

        return redirect()->route('installer.complete', ['token' => $result['completion_token']]);
    }

    protected function appearanceSummary(): string
    {
        $c = $this->wizard->get('customize');
        $theme = ! empty($c['custom_colors']) ? strtoupper((string) $c['brand_color']).' / '.strtoupper((string) $c['brand_color_2'])
            : __(Appearance::THEMES[$c['theme'] ?? 'musicdesk'][0] ?? 'MusicDesk');
        $modes = ['both' => __('light & dark'), 'light' => __('light only'), 'dark' => __('dark only')][$c['modes'] ?? 'both'] ?? '';

        return $theme.' · '.$modes.' · '.__(':design sign-in page', ['design' => __(Appearance::LOGIN_LAYOUTS[$c['login_layout'] ?? 'spotlight'][0] ?? '')]);
    }

    protected function safeMessage(Throwable $e): string
    {
        // Strip anything that looks like a DSN or credentials.
        $message = preg_replace('/(password|pwd|secret)=?[^\s,;)]*/i', '$1=***', $e->getMessage()) ?? '';

        return Str::limit($message, 240);
    }
}
