<?php

namespace App\Http\Controllers\Installer;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Step 4 (can be skipped): outgoing email for password resets, invitations
 * and notifications. Stored in Settings → Email at the end of installation.
 */
class MailController extends InstallerController
{
    public const ENCRYPTIONS = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (port 25)'];

    public function show(): View|RedirectResponse
    {
        if ($redirect = $this->guard('mail')) {
            return $redirect;
        }

        $saved = $this->wizard->get('mail');

        return view('installer.mail', [
            'step' => 'mail',
            'values' => [
                'host' => $saved['host'] ?? '',
                'port' => $saved['port'] ?? 587,
                'encryption' => $saved['encryption'] ?? 'tls',
                'username' => $saved['username'] ?? '',
                'from_address' => $saved['from_address'] ?? '',
                'from_name' => $saved['from_name'] ?? '',
            ],
            'configured' => ($saved['mailer'] ?? null) === 'smtp',
            'hasPassword' => filled($saved['password'] ?? null),
            'encryptions' => self::ENCRYPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard('mail')) {
            return $redirect;
        }

        $data = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(array_keys(self::ENCRYPTIONS))],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120', 'not_regex:/[<>"\r\n]/'],
            'test_to' => ['nullable', 'email', 'max:255'],
        ], [], ['host' => __('SMTP server'), 'from_address' => __('sender address'), 'test_to' => __('test address')]);

        $previous = $this->wizard->get('mail');
        $password = $request->filled('password') ? (string) $data['password']
            : ($request->boolean('keep_password') ? $this->wizard->decrypt($previous['password'] ?? null) : null);

        $settings = [
            'mailer' => 'smtp',
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'] ?? null,
            'from_address' => $data['from_address'],
            'from_name' => ($data['from_name'] ?? null) ?: null,
        ];

        if ($request->input('action') === 'test') {
            $to = ($data['test_to'] ?? null) ?: $data['from_address'];

            try {
                Mail::build([
                    'transport' => 'smtp',
                    'scheme' => $data['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                    'host' => $data['host'],
                    'port' => (int) $data['port'],
                    'username' => $data['username'] ?? null,
                    'password' => $password,
                    'timeout' => 15,
                    'auto_tls' => $data['encryption'] !== 'none',
                ])->raw(__('This is a test email from the LabelDesk installer. Email is working.'), function ($message) use ($to, $data) {
                    $message->to($to)
                        ->from($data['from_address'], ($data['from_name'] ?? null) ?: config('labeldesk.product.name'))
                        ->subject(__('LabelDesk test email'));
                });
            } catch (Throwable $e) {
                return back()->withInput($request->except('password'))->withErrors([
                    'mail' => __('The test email could not be sent: :reason', ['reason' => Str::limit(preg_replace('/(password|auth)\S*/i', '***', $e->getMessage()) ?? '', 220)]),
                ]);
            }

            // Keep what was tested so it is not typed again.
            $this->wizard->put('mail', $settings + ['password' => $this->wizard->encrypt($password)]);

            return back()->withInput($request->except('password'))->with('success', __('Test email sent to :address. Check the inbox (and spam folder), then continue.', ['address' => $to]));
        }

        $this->wizard->put('mail', $settings + ['password' => $this->wizard->encrypt($password)]);

        return $this->next('mail');
    }

    public function skip(): RedirectResponse
    {
        if ($redirect = $this->guard('mail')) {
            return $redirect;
        }

        // "log": emails are written to the log until SMTP is set up in Settings → Email.
        $this->wizard->put('mail', ['mailer' => 'log']);

        return $this->next('mail');
    }
}
