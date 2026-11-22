<?php

namespace App\Plugins;

use App\Exceptions\LicenseException;
use App\Models\Plugin;
use App\Services\Activity\ActivityLogger;
use App\Services\Installation\InstallationState;
use App\Services\License\LicenseValidationService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Add-ons, WordPress style: upload a signed ZIP from MusicDesk.tech, then
 * activate it with the add-on's own license key (verified by MusicDesk.tech)
 * or deactivate it. An add-on only runs while it is active AND its key is valid.
 */
class PluginManager
{
    /** @var array<string, AddonServiceProvider> running this request */
    protected array $running = [];

    /** @var array<string, true> */
    protected array $autoloaded = [];

    public function __construct(protected Application $app) {}

    public function path(string $slug = ''): string
    {
        $base = rtrim((string) config('labeldesk.plugins_path', base_path('plugins')), '/\\');

        return $slug === '' ? $base : $base.DIRECTORY_SEPARATOR.$slug;
    }

    /** @return Collection<int, Plugin> */
    public function installed(): Collection
    {
        return Plugin::query()->orderBy('name')->get();
    }

    /** The add-on's own license key is verified and its signed token is valid for this domain. */
    public function licensed(Plugin $plugin): bool
    {
        return $this->app->make(AddonLicenseService::class)->valid($plugin);
    }

    public function isRunning(string $slug): bool
    {
        return isset($this->running[$slug]);
    }

    // ------------------------------------------------------------ boot

    /** Start every active, licensed add-on. Never breaks the application. */
    public function boot(): void
    {
        try {
            if (! $this->app->make(InstallationState::class)->isInstalled() || ! Schema::hasTable('plugins')) {
                return;
            }
            $plugins = Plugin::query()->where('active', true)->orderBy('id')->get();
        } catch (Throwable) {
            return;
        }

        foreach ($plugins as $plugin) {
            try {
                if (! $this->licensed($plugin)) {
                    continue; // stays "active" but does not run until the license includes it again
                }
                $this->start($plugin);
            } catch (Throwable $e) {
                Log::error('Add-on failed to start', ['plugin' => $plugin->slug, 'error' => $e->getMessage()]);
                rescue(fn () => $plugin->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly(), report: false);
            }
        }
    }

    protected function start(Plugin $plugin): AddonServiceProvider
    {
        if (isset($this->running[$plugin->slug])) {
            return $this->running[$plugin->slug];
        }

        $manifest = PluginManifest::fromJson((string) @file_get_contents($this->path($plugin->slug).'/plugin.json'));
        if ($manifest->slug() !== $plugin->slug) {
            throw new PluginException(__('The add-on files do not match the installed add-on.'));
        }
        if (! $manifest->compatible()) {
            throw new PluginException(__(':name needs LabelDesk :version or newer.', ['name' => $manifest->name(), 'version' => $manifest->requires()]));
        }

        $this->autoload($manifest);
        $class = $manifest->provider();
        if (! class_exists($class) || ! is_subclass_of($class, AddonServiceProvider::class)) {
            throw new PluginException(__('The add-on provider :class was not found.', ['class' => $class]));
        }

        /** @var AddonServiceProvider $provider */
        $provider = (new $class($this->app))->forPlugin($plugin, $this->path($plugin->slug));
        $this->app->register($provider);

        // Route names set inside the add-on's route files become resolvable.
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        return $this->running[$plugin->slug] = $provider;
    }

    protected function autoload(PluginManifest $manifest): void
    {
        if (isset($this->autoloaded[$manifest->slug()])) {
            return;
        }
        $base = $this->path($manifest->slug());
        $prefix = $manifest->namespace();
        $src = $base.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR;

        spl_autoload_register(function (string $class) use ($prefix, $src) {
            if (str_starts_with($class, $prefix)) {
                $file = $src.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });

        // Optional bundled libraries (vendor/autoload.php inside the add-on).
        if (is_file($base.'/vendor/autoload.php')) {
            require_once $base.'/vendor/autoload.php';
        }

        $this->autoloaded[$manifest->slug()] = true;
    }

    // ------------------------------------------------------------ install / update

    public function install(string $zipPath): Plugin
    {
        if (! File::isDirectory($this->path())) {
            File::ensureDirectoryExists($this->path());
        }
        if (! is_writable($this->path())) {
            throw new PluginException(__('The plugins folder is not writable. Give the web server write access to :path.', ['path' => $this->path()]));
        }

        $package = PluginPackage::open($zipPath);
        $manifest = $package->manifest;

        try {
            if (! $manifest->compatible()) {
                throw new PluginException(__(':name :version needs LabelDesk :required or newer. Update LabelDesk first.', ['name' => $manifest->name(), 'version' => $manifest->version(), 'required' => $manifest->requires()]));
            }

            $existing = Plugin::query()->where('slug', $manifest->slug())->first();
            if ($existing && version_compare($manifest->version(), $existing->version, '<')) {
                throw new PluginException(__('Version :new is older than the installed :old.', ['new' => $manifest->version(), 'old' => $existing->version]));
            }
            if ($existing && $existing->addon !== $manifest->addon()) {
                throw new PluginException(__('This package belongs to a different add-on.'));
            }

            $package->extractTo($this->path($manifest->slug()));
        } finally {
            $package->close();
        }

        $plugin = Plugin::query()->updateOrCreate(['slug' => $manifest->slug()], [
            'name' => $manifest->name(),
            'version' => $manifest->version(),
            'addon' => $manifest->addon(),
            'description' => $manifest->description(),
            'manifest' => $manifest->data,
            'installed_at' => $existing?->installed_at ?? now(),
            'last_error' => null,
        ])->fresh();

        $this->app->make(ActivityLogger::class)->log('plugin.installed', $existing
            ? __('Updated add-on :name to :version', ['name' => $plugin->name, 'version' => $plugin->version])
            : __('Installed add-on :name :version', ['name' => $plugin->name, 'version' => $plugin->version]), $plugin);

        // An update of an active add-on runs its new migrations straight away.
        if ($plugin->active && $this->licensed($plugin)) {
            $this->migrate($plugin);
        }
        $this->refreshCaches();

        return $plugin;
    }

    // ------------------------------------------------------------ activate / deactivate / delete

    /**
     * Activate; with $key, the add-on license key is verified with MusicDesk.tech first.
     */
    public function activate(Plugin $plugin, ?string $key = null): void
    {
        if (! $this->app->make(LicenseValidationService::class)->snapshot()->allowsWrites()) {
            throw new PluginException(__('Add-ons cannot be activated while the license is read-only.'));
        }

        if ($key !== null && trim($key) !== '') {
            try {
                $this->app->make(AddonLicenseService::class)->activate($plugin, $key);
            } catch (LicenseException $e) {
                $plugin->forceFill(['license_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                throw new PluginException(__('The license key for :name was not accepted: :reason', ['name' => $plugin->name, 'reason' => $e->getMessage()]));
            }
        }

        if (! $this->licensed($plugin->refresh())) {
            throw new PluginException(__('Enter the license key for :name. Get it from your MusicDesk.tech account.', ['name' => $plugin->name]));
        }

        $provider = $this->start($plugin);
        $this->migrate($plugin);
        $this->syncPermissions($plugin);
        $provider->activate();

        $plugin->forceFill(['active' => true, 'activated_at' => now(), 'last_error' => null])->save();
        $this->app->make(ActivityLogger::class)->log('plugin.activated', __('Activated add-on :name', ['name' => $plugin->name]), $plugin);
        $this->refreshCaches();
    }

    public function deactivate(Plugin $plugin): void
    {
        if (isset($this->running[$plugin->slug])) {
            rescue(fn () => $this->running[$plugin->slug]->deactivate(), report: true);
            unset($this->running[$plugin->slug]); // its routes answer 404 from now on
        }

        $plugin->forceFill(['active' => false])->save();
        $this->app->make(ActivityLogger::class)->log('plugin.deactivated', __('Deactivated add-on :name', ['name' => $plugin->name]), $plugin);
        $this->refreshCaches();
    }

    /** Delete the files of an inactive add-on. Its data stays unless the add-on's uninstall() removes it. */
    public function delete(Plugin $plugin): void
    {
        if ($plugin->active) {
            throw new PluginException(__('Deactivate :name before deleting it.', ['name' => $plugin->name]));
        }

        if (File::isDirectory($this->path($plugin->slug))) {
            rescue(function () use ($plugin) {
                if ($this->licensed($plugin)) {
                    $this->start($plugin)->uninstall();
                }
            }, report: true);
            File::deleteDirectory($this->path($plugin->slug));
        }

        // Free this domain on the add-on key.
        rescue(fn () => $this->app->make(AddonLicenseService::class)->release($plugin), report: false);

        $plugin->delete();
        $this->app->make(ActivityLogger::class)->log('plugin.deleted', __('Deleted add-on :name', ['name' => $plugin->name]));
        $this->refreshCaches();
    }

    protected function migrate(Plugin $plugin): void
    {
        $dir = $this->path($plugin->slug).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if (! File::isDirectory($dir)) {
            return;
        }

        $code = Artisan::call('migrate', ['--path' => $dir, '--realpath' => true, '--force' => true]);
        if ($code !== 0) {
            throw new PluginException(__('The add-on database update failed: :output', ['output' => mb_substr(trim(Artisan::output()), 0, 300)]));
        }
    }

    protected function syncPermissions(Plugin $plugin): void
    {
        $names = $this->app->make(PluginRegistry::class)->permissionNames($plugin->slug);
        if ($names === [] || ! Schema::hasTable('permissions')) {
            return;
        }
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function refreshCaches(): void
    {
        rescue(fn () => Artisan::call('route:clear'), report: false);
    }
}
