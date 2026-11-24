<?php

namespace App\Plugins;

use App\Models\Plugin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class for an add-on's service provider (the "provider" in plugin.json).
 *
 *   class BulkUploadServiceProvider extends AddonServiceProvider
 *   {
 *       public function boot(): void
 *       {
 *           $this->views();
 *           $this->migrations();
 *           $this->webRoutes('routes/web.php');
 *           $this->navigation('Catalog', ['label' => 'Bulk upload', 'icon' => 'upload', 'route' => 'bulk-upload.index', 'permission' => 'catalog.bulk_upload'], after: 'tracks.index');
 *       }
 *   }
 *
 * Lifecycle hooks: activate() after its migrations ran, deactivate(), uninstall() before its
 * files are deleted. Only boot()/register() run on every request, and only while the add-on is
 * active and on the license.
 */
abstract class AddonServiceProvider extends ServiceProvider
{
    protected ?Plugin $plugin = null;

    protected string $basePath = '';

    public function forPlugin(Plugin $plugin, string $basePath): static
    {
        $this->plugin = $plugin;
        $this->basePath = rtrim($basePath, '/\\');

        return $this;
    }

    public function slug(): string
    {
        return (string) $this->plugin?->slug;
    }

    public function path(string $relative = ''): string
    {
        return $this->basePath.($relative !== '' ? '/'.ltrim($relative, '/') : '');
    }

    protected function registry(): PluginRegistry
    {
        return $this->app->make(PluginRegistry::class);
    }

    // ------------------------------------------------------------ lifecycle hooks

    public function activate(): void {}

    public function deactivate(): void {}

    public function uninstall(): void {}

    // ------------------------------------------------------------ helpers

    /** Signed-in application pages: same middleware as core pages (auth, active user, license policy). */
    protected function webRoutes(string $file): void
    {
        Route::middleware(['web', 'auth', 'auth.session', 'active', 'license', 'plugin:'.$this->slug()])->group($this->path($file));
    }

    /** Public pages with a session (e.g. SSO sign-in and callbacks). */
    protected function guestRoutes(string $file): void
    {
        Route::middleware(['web', 'plugin:'.$this->slug()])->group($this->path($file));
    }

    /** Stateless routes under /api (no session, no CSRF): REST APIs and incoming webhooks. Authenticate them yourself. */
    protected function apiRoutes(string $file, string $prefix = 'api'): void
    {
        Route::prefix($prefix)->middleware(['api', 'plugin:'.$this->slug()])->group($this->path($file));
    }

    /** Blade views as "<slug>::name". */
    protected function views(string $relative = 'resources/views'): void
    {
        $this->loadViewsFrom($this->path($relative), $this->slug());
    }

    /** Migrations, run when the add-on is activated or updated. */
    protected function migrations(string $relative = 'database/migrations'): void
    {
        $this->loadMigrationsFrom($this->path($relative));
    }

    protected function translations(string $relative = 'lang'): void
    {
        $this->loadJsonTranslationsFrom($this->path($relative));
    }

    /** @param  array<string, mixed>  $item */
    protected function navigation(string $section, array $item, ?string $after = null): void
    {
        $this->registry()->navigation($section, $item, $after);
    }

    /** @param  array<string, string>  $permissions */
    protected function permissions(string $key, string $label, array $permissions): void
    {
        $this->registry()->permissions($key, $label, $permissions, $this->slug());
    }

    protected function settingsTab(string $route, string $label, string $icon = 'puzzle', string $can = 'settings.manage'): void
    {
        $this->registry()->settingsTab($route, $label, $icon, $can, $this->slug());
    }

    /** A Blade view rendered under the sign-in form (e.g. "Sign in with Microsoft"). */
    protected function loginButton(string $view): void
    {
        $this->registry()->loginButton($view, $this->slug());
    }

    /** Background work every $minutes (no cron needed); see PluginRegistry::task(). */
    protected function backgroundTask(string $name, int $minutes, callable $run, ?string $label = null): void
    {
        $this->registry()->task($name, $minutes, $run, $this->slug(), $label);
    }

    /** Serve track masters that are no longer on the server; see PluginRegistry::fileSource(). */
    protected function fileSource(callable $resolver): void
    {
        $this->registry()->fileSource($resolver, $this->slug());
    }

    protected function dashboardWidget(string $view): void
    {
        $this->registry()->dashboardWidget($view, $this->slug());
    }
}
