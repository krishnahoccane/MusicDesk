<?php

namespace App\Http\Controllers\Installer;

use App\Http\Requests\Installer\AdministratorRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdministratorController extends InstallerController
{
    public function show(): View|RedirectResponse
    {
        if ($redirect = $this->guard('administrator')) {
            return $redirect;
        }

        $saved = $this->wizard->get('administrator');

        return view('installer.administrator', [
            'step' => 'administrator',
            'values' => ['name' => $saved['name'] ?? '', 'email' => $saved['email'] ?? ''],
        ]);
    }

    public function store(AdministratorRequest $request): RedirectResponse
    {
        if ($redirect = $this->guard('administrator')) {
            return $redirect;
        }

        $data = $request->validated();

        // Only the hash is kept between steps.
        $this->wizard->put('administrator', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
        ]);

        return $this->next('administrator');
    }
}
