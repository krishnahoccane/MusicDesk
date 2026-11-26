<?php

namespace App\Services\Auth;

use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Enums\SystemRole;
use App\Models\Artist;
use App\Models\Label;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Self sign-up for artists and labels (Settings → General → Sign-up, off by
 * default). New accounts are inactive until an administrator activates them.
 */
class RegistrationService
{
    public function __construct(protected SettingsService $settings, protected ActivityLogger $activity) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('auth.registration', false) && $this->types() !== [];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_values(array_filter([
            $this->settings->get('auth.registration_artists', true) ? 'artist' : null,
            $this->settings->get('auth.registration_labels', true) ? 'label' : null,
        ]));
    }

    /**
     * @param  array{type: string, name: string, email: string, password: string, artist_name?: string|null, label_name?: string|null, website?: string|null, country?: string|null}  $data
     */
    public function register(array $data, ?string $ip = null): User
    {
        // A free trial at its artist limit takes no new artist sign-ups (visitors see a neutral note).
        if ($data['type'] === 'artist' && ! app(\App\Services\License\TrialLimits::class)->allows('artists')) {
            throw new \App\Exceptions\TrialLimitException(__('Sign-ups for artists are paused at the moment. Please try again later.'), '');
        }

        $user = DB::transaction(function () use ($data) {
            $user = new User(['name' => $data['name'], 'email' => mb_strtolower($data['email'])]);
            $user->password = Hash::make($data['password']);
            $user->forceFill(['is_active' => false, 'signup_type' => $data['type']])->save();

            if ($data['type'] === 'artist') {
                $user->assignRole(SystemRole::Artist->value);
                $artist = Artist::query()->create([
                    'name' => $data['artist_name'],
                    'legal_name' => $data['name'],
                    'type' => ArtistType::Solo,
                    'status' => ArtistStatus::Prospect,
                    'country' => $data['country'] ?? null,
                    'email' => $user->email,
                ]);
                $user->artists()->attach($artist->id);
            } else {
                // A label account gets its role when an administrator approves it.
                $label = new Label([
                    'name' => $data['label_name'],
                    'contact_name' => $data['name'],
                    'email' => $user->email,
                    'website' => $data['website'] ?? null,
                    'country' => $data['country'] ?? null,
                    'is_active' => false,
                ]);
                $label->forceFill(['user_id' => $user->id])->save();
            }

            return $user;
        });

        $this->activity->log('user.signed_up', $data['type'] === 'artist'
            ? __(':name signed up as an artist (:artist)', ['name' => $user->name, 'artist' => $data['artist_name']])
            : __(':name signed up for the label :label', ['name' => $user->name, 'label' => $data['label_name']]), $user, ['ip' => $ip], $user);

        $this->notifyAdministrators($user, $data);

        return $user;
    }

    /** Email every active user who manages users. Never fails the sign-up. */
    protected function notifyAdministrators(User $user, array $data): void
    {
        try {
            $admins = User::query()->where('is_active', true)->orderBy('id')->limit(200)->get()
                ->filter(fn (User $u) => $u->can('users.manage'))->take(20);
            $what = $data['type'] === 'artist'
                ? __('Artist: :name', ['name' => $data['artist_name']])
                : __('Label: :name', ['name' => $data['label_name']]);
            $body = implode("\n", [
                __('A new account is waiting for approval on :site.', ['site' => config('app.name')]),
                '',
                __('Name: :name', ['name' => $user->name]),
                __('Email: :email', ['email' => $user->email]),
                $what,
                '',
                __('Review it under Administration → Users (filter "Inactive") and activate it to give access:'),
                route('users.index', ['status' => 'inactive']),
            ]);

            foreach ($admins as $admin) {
                Mail::raw($body, fn ($m) => $m->to($admin->email)->subject(__('New sign-up: :name', ['name' => $user->name])));
            }
        } catch (Throwable $e) {
            Log::warning('Sign-up notification could not be sent', ['error' => $e->getMessage()]);
        }
    }

    /** First activation of a signed-up account: tell the person they can sign in. */
    public function approved(User $user): void
    {
        if (! $user->signup_type || $user->approved_at) {
            return;
        }
        $user->forceFill(['approved_at' => now()])->save();

        if ($user->signup_type === 'label') {
            Label::query()->where('user_id', $user->id)->update(['is_active' => true]);
        }

        try {
            Mail::raw(implode("\n", [
                __('Hello :name,', ['name' => $user->name]),
                '',
                __('Your account on :site has been approved. You can now sign in:', ['site' => config('app.name')]),
                route('login'),
            ]), fn ($m) => $m->to($user->email)->subject(__('Your :site account is approved', ['site' => config('app.name')])));
        } catch (Throwable $e) {
            Log::warning('Approval email could not be sent', ['error' => $e->getMessage()]);
        }
    }
}
