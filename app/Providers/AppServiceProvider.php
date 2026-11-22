<?php

namespace App\Providers;

use App\Models\Artist;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Policies\ArtistPolicy;
use App\Policies\ReleasePolicy;
use App\Policies\RolePolicy;
use App\Policies\TrackPolicy;
use App\Policies\UserPolicy;
use App\Services\Activity\ActivityLogger;
use App\Services\BulkUpload\BulkUploadService;
use App\Services\Installation\InstallationState;
use App\Services\License\LicenseFeatureService;
use App\Services\License\LicenseManager;
use App\Services\License\LicenseValidationService;
use App\Services\Navigation\NavigationBuilder;
use App\Services\SettingsApplier;
use App\Services\SettingsService;
use App\Support\Appearance;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NavigationBuilder::class);
        $this->app->singleton(InstallationState::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(LicenseManager::class);
        $this->app->singleton(LicenseValidationService::class);
        $this->app->singleton(ActivityLogger::class);
    }

    public function boot(): void
    {
        // Catch lazy-loading and mass-assignment mistakes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Named rate limiters (distinct keys so nested limits don't share counters).
        RateLimiter::for('installer', fn (Request $request) => Limit::perMinute(30)->by('installer|'.$request->ip()));
        RateLimiter::for('license-activation', fn (Request $request) => Limit::perMinute(10)->by('license|'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(6)->by('password-reset|'.$request->ip()));

        // Database settings (Settings → General / Email) override .env defaults.
        if ($this->app->make(InstallationState::class)->isInstalled()) {
            $this->app->make(SettingsApplier::class)->apply();
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Artist::class, ArtistPolicy::class);
        Gate::policy(Release::class, ReleasePolicy::class);
        Gate::policy(Track::class, TrackPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        // Security-relevant authentication events go to the activity log.
        Event::listen(Failed::class, function (Failed $event) {
            app(ActivityLogger::class)->log('auth.failed', __('Failed sign-in attempt'), null, [
                'email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 255),
            ]);
        });
        Event::listen(Lockout::class, function (Lockout $event) {
            app(ActivityLogger::class)->log('auth.lockout', __('Sign-in temporarily locked after repeated failures'), null, [
                'email' => mb_substr((string) $event->request->input('email', ''), 0, 255),
            ]);
        });

        // Super Admin implicitly passes every *permission* check ("users.manage").
        // Model policies (update, delete …) still run for Super Admins so rules
        // such as "cannot delete yourself" apply to everyone.
        // Bulk upload: administrators, staff and artist accounts per Settings → Catalogue.
        Gate::define('bulk-upload', fn (User $user) => $this->app->make(BulkUploadService::class)->typesFor($user) !== []);

        Gate::before(function ($user, string $ability) {
            return str_contains($ability, '.') && method_exists($user, 'hasRole') && $user->hasRole('Super Admin') ? true : null;
        });

        $this->app->make(NavigationBuilder::class)->resolveFeaturesUsing(
            fn (string $feature) => $this->app->make(LicenseFeatureService::class)->canUse($feature)
        );

        View::composer('components.layouts.app', function ($view) {
            $user = auth()->user();
            $view->with('navigation', $this->app->make(NavigationBuilder::class)->for($user));
        });

        View::composer(['components.layouts.app', 'components.layouts.auth', 'components.layouts.installer'], function ($view) {
            $appearance = Appearance::for(auth()->user());
            $view->with('appearance', $appearance);
            $view->with('themePreference', $appearance['preference']);
        });
    }
}
