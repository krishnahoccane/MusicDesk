<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * "Create an account" for artists and labels, when an administrator has
 * turned sign-up on. Accounts wait for approval before they can sign in.
 */
class RegisterController extends Controller
{
    public function __construct(protected RegistrationService $registration) {}

    public function create(Request $request): View
    {
        abort_unless($this->registration->enabled(), 404);
        $types = $this->registration->types();
        $type = in_array($request->query('type'), $types, true) ? $request->query('type') : $types[0];

        return view('auth.register', ['types' => $types, 'type' => old('type', $type)]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->registration->enabled(), 404);

        // Bots fill every field; people never see this one.
        if (filled($request->input('company_url'))) {
            return redirect()->route('login')->with('status', __('Thank you. Your account is waiting for approval.'));
        }

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'country' => strtoupper(trim((string) $request->input('country'))),
        ]);
        $type = (string) $request->input('type');

        $data = $request->validate([
            'type' => ['required', Rule::in($this->registration->types())],
            'name' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
            'artist_name' => [Rule::requiredIf($type === 'artist'), 'nullable', 'string', 'max:150', 'not_regex:/[<>]/'],
            'label_name' => [Rule::requiredIf($type === 'label'), 'nullable', 'string', 'max:150', 'not_regex:/[<>]/'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'country' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'confirm' => ['accepted'],
        ], [
            'email.unique' => __('An account with this email already exists. Sign in, or reset your password.'),
            'country.regex' => __('Use the two-letter country code (e.g. GB, IN, US).'),
            'confirm.accepted' => __('Please confirm that the details are correct and that you represent this :what.', ['what' => $type === 'label' ? __('label') : __('artist')]),
        ], [
            'artist_name' => __('artist name'), 'label_name' => __('label name'),
        ]);

        try {
            $this->registration->register([
            'type' => $data['type'],
            'name' => trim($data['name']),
            'email' => $data['email'],
            'password' => $data['password'],
            'artist_name' => isset($data['artist_name']) ? trim($data['artist_name']) : null,
            'label_name' => isset($data['label_name']) ? trim($data['label_name']) : null,
            'website' => $data['website'] ?? null,
            'country' => ($data['country'] ?? '') !== '' ? $data['country'] : null,
            ], $request->ip());
        } catch (\App\Exceptions\TrialLimitException $e) {
            return back()->withInput($request->except('password', 'password_confirmation'))->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', __('Thank you, :name. Your account has been created and is waiting for approval. You will get an email when you can sign in.', ['name' => trim($data['name'])]));
    }
}
