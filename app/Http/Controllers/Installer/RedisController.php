<?php

namespace App\Http\Controllers\Installer;

use App\Services\Installation\RedisInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Step 3 (optional): Redis for cache, sessions and the queue. Skipping keeps
 * the database drivers, which work on any shared host.
 */
class RedisController extends InstallerController
{
    public function show(): View|RedirectResponse
    {
        if ($redirect = $this->guard('redis')) {
            return $redirect;
        }

        $saved = $this->wizard->get('redis');

        return view('installer.redis', [
            'step' => 'redis',
            'values' => [
                'host' => $saved['host'] ?? '127.0.0.1',
                'port' => $saved['port'] ?? 6379,
                'username' => $saved['username'] ?? '',
                'database' => $saved['database'] ?? 0,
                'tls' => $saved['tls'] ?? false,
                'use_cache' => $saved['use_cache'] ?? true,
                'use_sessions' => $saved['use_sessions'] ?? true,
                'use_queue' => $saved['use_queue'] ?? true,
            ],
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'hasPassword' => filled($saved['password'] ?? null),
            'client' => RedisInspector::client(),
        ]);
    }

    public function store(Request $request, RedisInspector $inspector): RedirectResponse
    {
        if ($redirect = $this->guard('redis')) {
            return $redirect;
        }

        $data = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:255'],
            'database' => ['required', 'integer', 'between:0,15'],
            'tls' => ['boolean'],
            'use_cache' => ['boolean'],
            'use_sessions' => ['boolean'],
            'use_queue' => ['boolean'],
        ], [], ['host' => __('host'), 'database' => __('database number')]);

        $previous = $this->wizard->get('redis');
        $password = $request->filled('password') ? (string) $data['password']
            : ($request->boolean('keep_password') ? $this->wizard->decrypt($previous['password'] ?? null) : null);

        $connection = [
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'username' => $data['username'] ?? null,
            'password' => $password,
            'database' => (int) $data['database'],
            'tls' => $request->boolean('tls'),
        ];

        $result = $inspector->inspect($connection);
        if (! $result['ok']) {
            return back()->withInput($request->except('password'))->withErrors(['redis' => $result['message']]);
        }

        $this->wizard->put('redis', array_merge($connection, [
            'enabled' => true,
            'password' => $this->wizard->encrypt($password),
            'client' => RedisInspector::client(),
            'use_cache' => $request->boolean('use_cache'),
            'use_sessions' => $request->boolean('use_sessions'),
            'use_queue' => $request->boolean('use_queue'),
            'version' => $result['version'] ?? null,
        ]));

        return $this->next('redis')->with('success', $result['message']);
    }

    public function skip(): RedirectResponse
    {
        if ($redirect = $this->guard('redis')) {
            return $redirect;
        }

        $this->wizard->put('redis', ['enabled' => false]);

        return $this->next('redis');
    }
}
