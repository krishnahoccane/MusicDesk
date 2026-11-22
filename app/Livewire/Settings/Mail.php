<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsApplier;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Settings → Email: outgoing mail (password links, invitations, notifications).
 * The SMTP password is stored encrypted and never sent back to the browser.
 */
class Mail extends Component
{
    use EnforcesLicense;

    public string $mailer = 'smtp';

    public string $host = '';

    public int|string $port = 587;

    public string $scheme = '';

    public string $username = '';

    /** Write-only: blank keeps the saved password. */
    public string $password = '';

    public bool $forget_password = false;

    public string $from_address = '';

    public string $from_name = '';

    #[Locked]
    public bool $hasPassword = false;

    public function mount(SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $this->mailer = (string) $settings->get('mail.mailer', in_array(config('mail.default'), ['smtp', 'sendmail', 'log'], true) ? config('mail.default') : 'smtp');
        $this->host = (string) $settings->get('mail.host', config('mail.mailers.smtp.host'));
        $this->port = (int) $settings->get('mail.port', config('mail.mailers.smtp.port') ?: 587);
        $this->scheme = (string) $settings->get('mail.scheme', config('mail.mailers.smtp.scheme') === 'smtps' ? 'smtps' : '');
        $this->username = (string) $settings->get('mail.username', config('mail.mailers.smtp.username'));
        $this->from_address = (string) $settings->get('mail.from_address', config('mail.from.address'));
        $this->from_name = (string) $settings->get('mail.from_name', config('mail.from.name'));
        $this->hasPassword = filled($settings->get('mail.password'));
    }

    public function save(SettingsService $settings, SettingsApplier $applier, ActivityLogger $activity): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();

        $smtp = $this->mailer === 'smtp';
        $this->validate([
            'mailer' => ['required', Rule::in(['smtp', 'sendmail', 'log'])],
            'host' => [$smtp ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => [$smtp ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'scheme' => ['nullable', Rule::in(['', 'smtps'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
        ], [
            'host.regex' => __('Enter a host name such as smtp.example.com.'),
        ], ['from_address' => __('sender address'), 'from_name' => __('sender name')]);

        $settings->setMany([
            'mail.mailer' => $this->mailer,
            'mail.host' => trim($this->host),
            'mail.port' => (int) $this->port,
            'mail.scheme' => $this->scheme,
            'mail.username' => trim($this->username),
            'mail.from_address' => trim($this->from_address),
            'mail.from_name' => trim($this->from_name),
        ]);

        if ($this->forget_password) {
            $settings->set('mail.password', null, 'encrypted');
        } elseif ($this->password !== '') {
            $settings->set('mail.password', $this->password, 'encrypted');
        }

        $this->hasPassword = filled($settings->get('mail.password'));
        $this->reset('password', 'forget_password');

        $applier->apply();
        app(MailManager::class)->purge();

        $activity->log('settings.updated', __('Updated email settings'), null, [
            'mailer' => $this->mailer, 'host' => $this->host, 'port' => (int) $this->port,
        ]);

        $this->dispatch('toast', message: __('Email settings saved.'), type: 'success');
    }

    public function sendTest(SettingsApplier $applier, ActivityLogger $activity): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $key = 'mail-test|'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->dispatch('toast', message: __('Please wait a minute before sending another test email.'), type: 'warning');

            return;
        }
        RateLimiter::hit($key, 60);

        $applier->apply();
        app(MailManager::class)->purge();
        $to = auth()->user()->email;

        try {
            MailFacade::raw(
                __("This is a test email from :app.\n\nIf you can read this, outgoing email is configured correctly.", ['app' => config('app.name')]),
                fn ($message) => $message->to($to)->subject(__(':app test email', ['app' => config('app.name')]))
            );
        } catch (Throwable $e) {
            Log::warning('Test email failed.', ['error' => $e::class]);
            $this->dispatch('toast', message: __('Sending failed: :error', ['error' => str($e->getMessage())->limit(160)]), type: 'danger');

            return;
        }

        $activity->log('settings.mail_test', __('Sent a test email'), null, ['to' => $to]);
        $this->dispatch('toast', message: config('mail.default') === 'log'
            ? __('Test email written to the application log (mailer is set to "Log only").')
            : __('Test email sent to :email.', ['email' => $to]), type: 'success');
    }

    public function render(): View
    {
        return view('livewire.settings.mail')
            ->title(__('Email settings'))
            ->layoutData(['breadcrumbs' => [['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Email')]]]);
    }
}
