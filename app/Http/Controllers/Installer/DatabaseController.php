<?php

namespace App\Http\Controllers\Installer;

use App\Http\Requests\Installer\DatabaseRequest;
use App\Services\Installation\DatabaseInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DatabaseController extends InstallerController
{
    public function show(): View|RedirectResponse
    {
        if ($redirect = $this->guard('database')) {
            return $redirect;
        }

        $saved = $this->wizard->get('database');
        unset($saved['password']); // never render the password back

        return view('installer.database', [
            'step' => 'database',
            'values' => $saved + ['host' => '127.0.0.1', 'port' => 3306, 'database' => '', 'username' => '', 'prefix' => ''],
            'hasPassword' => ! empty($this->wizard->get('database')['password'] ?? null),
        ]);
    }

    public function store(DatabaseRequest $request, DatabaseInspector $inspector): RedirectResponse
    {
        if ($redirect = $this->guard('database')) {
            return $redirect;
        }

        $data = $request->validated();
        $previous = $this->wizard->get('database');

        // Keep the previously entered password if the field was left blank on
        // a revisit (passwords are never re-displayed).
        $password = $request->filled('password')
            ? (string) $data['password']
            : ($request->boolean('keep_password') ? $this->wizard->decrypt($previous['password'] ?? null) : '');

        $credentials = [
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'database' => $data['database'],
            'username' => $data['username'],
            'password' => $password,
            'prefix' => (string) ($data['prefix'] ?? ''),
        ];

        $result = $inspector->inspect($credentials);

        if (! $result['ok']) {
            return back()->withInput($request->except('password'))->withErrors(['database' => $result['message']]);
        }

        $resumable = $this->state->isResumableFor($credentials['host'], $credentials['database'], $credentials['prefix']);

        if (($result['existing_tables'] ?? 0) > 0 && ! $resumable) {
            return back()->withInput($request->except('password'))->withErrors([
                'database' => __('This database already contains :count table(s) with the prefix ":prefix". LabelDesk will not install into a database that is in use. Choose an empty database or a different table prefix.', [
                    'count' => $result['existing_tables'],
                    'prefix' => $credentials['prefix'] === '' ? __('(none)') : $credentials['prefix'],
                ]),
            ]);
        }

        $this->wizard->put('database', array_merge($credentials, [
            'password' => $this->wizard->encrypt($password),
            'server' => $result['server'] ?? null,
            'version' => $result['version'] ?? null,
            'resuming' => $resumable && ($result['existing_tables'] ?? 0) > 0,
        ]));
        $this->wizard->resetAfter('database');

        return $this->next('database')->with('success', $result['message']);
    }
}
