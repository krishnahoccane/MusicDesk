<?php

namespace App\Livewire\Profile;

use App\Enums\ThemePreference;
use App\Services\Activity\ActivityLogger;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The signed-in user's own profile: name, email, theme and password.
 * Personal account maintenance stays available in license-restricted mode.
 */
class Edit extends Component
{
    public string $name = '';

    public string $email = '';

    public string $theme = 'system';

    /** Required only when the email address changes. */
    public string $email_password = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->theme = ($user->theme ?? ThemePreference::default())->value;
    }

    public function saveProfile(ActivityLogger $activity): void
    {
        $user = auth()->user();
        $emailChanged = Str::lower(trim($this->email)) !== $user->email;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'theme' => ['required', new Enum(ThemePreference::class)],
            'email_password' => [$emailChanged ? 'required' : 'nullable', 'string', 'max:255'],
        ], [], ['email_password' => __('current password')]);

        if ($emailChanged) {
            $this->checkPassword('email_password', $this->email_password);
        }

        $before = ['name' => $user->name, 'email' => $user->email];
        $user->fill([
            'name' => $this->name,
            'email' => Str::lower(trim($this->email)),
            'theme' => $this->theme,
        ])->save();

        $this->reset('email_password');
        $this->email = $user->email;

        $changes = array_filter([
            'name' => $before['name'] !== $user->name ? [$before['name'], $user->name] : null,
            'email' => $before['email'] !== $user->email ? [$before['email'], $user->email] : null,
        ]);
        if ($changes) {
            $activity->log('profile.updated', __('Updated own profile'), $user, $changes);
        }

        $this->dispatch('toast', message: __('Your profile has been saved.'), type: 'success');
        $this->dispatch('profile-theme-changed', theme: $this->theme);
    }

    public function savePassword(UserService $users, ActivityLogger $activity): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults(), 'different:current_password'],
        ], [
            'password.different' => __('Choose a password different from your current one.'),
        ]);

        $this->checkPassword('current_password', $this->current_password);

        $user = auth()->user();
        $user->password = $this->password;
        $user->save();

        // Other browsers and "remember me" cookies are signed out; this one stays.
        $users->terminateSessions($user);
        $users->keepCurrentSession($user->fresh());

        $this->reset('current_password', 'password', 'password_confirmation');
        $activity->log('profile.password_changed', __('Changed own password'), $user);

        $this->dispatch('toast', message: __('Your password has been changed. Other sessions were signed out.'), type: 'success');
    }

    /**
     * Verifies the current password with throttling against guessing.
     */
    protected function checkPassword(string $field, string $value): void
    {
        $key = 'profile-password|'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                $field => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Hash::check($value, auth()->user()->getAuthPassword())) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages([$field => __('The password is incorrect.')]);
        }

        RateLimiter::clear($key);
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.profile.edit', [
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'themes' => ThemePreference::cases(),
        ])->title(__('My profile'))->layoutData(['breadcrumbs' => [['label' => __('My profile')]]]);
    }
}
