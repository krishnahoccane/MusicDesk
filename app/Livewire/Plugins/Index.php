<?php

namespace App\Livewire\Plugins;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Plugin;
use App\Plugins\AddonLicenseService;
use App\Plugins\PluginException;
use App\Plugins\PluginManager;
use App\Services\License\LicenseAuthority;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Administration → Add-ons: upload signed add-on ZIPs from MusicDesk.tech,
 * activate, deactivate and delete them (like WordPress plugins).
 */
class Index extends Component
{
    use EnforcesLicense;
    use WithFileUploads;

    public $package;

    /** @var array<int, string> add-on license keys typed per plugin id */
    public array $keys = [];

    protected function guard(): void
    {
        abort_unless(auth()->user()->can('plugins.manage'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    protected function manager(): PluginManager
    {
        return app(PluginManager::class);
    }

    public function install(): void
    {
        $this->guard();
        $this->validate(['package' => ['required', 'file', 'max:204800', 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream']], [], ['package' => __('add-on file')]);
        if (strtolower((string) $this->package->getClientOriginalExtension()) !== 'zip') {
            $this->addError('package', __('Upload the .zip file you downloaded from MusicDesk.tech.'));

            return;
        }

        try {
            $plugin = $this->manager()->install($this->package->getRealPath());
        } catch (PluginException $e) {
            $this->addError('package', $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('package', __('The add-on could not be installed: :reason', ['reason' => mb_substr($e->getMessage(), 0, 200)]));

            return;
        } finally {
            rescue(fn () => $this->package?->delete(), report: false);
        }

        $this->reset('package');
        $licensed = $this->manager()->licensed($plugin);

        $this->dispatch('toast', type: 'success', message: match (true) {
            $plugin->active => __(':name updated to :version.', ['name' => $plugin->name, 'version' => $plugin->version]),
            $licensed => __(':name :version is installed. Activate it to start using it.', ['name' => $plugin->name, 'version' => $plugin->version]),
            default => __(':name :version is installed. Enter its license key from MusicDesk.tech to activate it.', ['name' => $plugin->name, 'version' => $plugin->version]),
        });
    }

    public function activate(int $id): void
    {
        $this->guard();
        $plugin = Plugin::query()->findOrFail($id);

        $key = trim((string) ($this->keys[$id] ?? ''));
        if ($key === '' && ! $this->manager()->licensed($plugin)) {
            $this->addError('keys.'.$id, __('Enter the license key for :name from your MusicDesk.tech account.', ['name' => $plugin->name]));

            return;
        }

        try {
            $this->manager()->activate($plugin, $key !== '' ? $key : null);
        } catch (PluginException $e) {
            $this->addError('keys.'.$id, $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $plugin->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            $this->dispatch('toast', type: 'danger', message: __(':name could not be activated: :reason', ['name' => $plugin->name, 'reason' => mb_substr($e->getMessage(), 0, 200)]));

            return;
        }

        $this->redirectRoute('plugins.index'); // reload so the new menu items appear
        session()->flash('success', __(':name is active.', ['name' => $plugin->name]));
    }

    public function deactivate(int $id): void
    {
        abort_unless(auth()->user()->can('plugins.manage'), 403); // allowed even while read-only
        $plugin = Plugin::query()->findOrFail($id);
        $this->manager()->deactivate($plugin);

        $this->redirectRoute('plugins.index');
        session()->flash('success', __(':name is deactivated. Its data is kept.', ['name' => $plugin->name]));
    }

    /** Forget the add-on key (frees this domain on it at MusicDesk.tech). Only for inactive add-ons. */
    public function removeKey(int $id): void
    {
        $this->guard();
        $plugin = Plugin::query()->findOrFail($id);
        abort_if($plugin->active, 422);
        app(AddonLicenseService::class)->release($plugin);
        $this->dispatch('toast', type: 'success', message: __('The license key was removed from :name.', ['name' => $plugin->name]));
    }

    public function delete(int $id): void
    {
        $this->guard();
        $plugin = Plugin::query()->findOrFail($id);

        try {
            $this->manager()->delete($plugin);
        } catch (PluginException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __(':name was deleted.', ['name' => $plugin->name]));
    }

    public function render(): View
    {
        $manager = $this->manager();

        return view('livewire.plugins.index', [
            'plugins' => $manager->installed()->map(fn (Plugin $p) => [
                'model' => $p,
                'licensed' => $manager->licensed($p),
                'running' => $manager->isRunning($p->slug),
                'missing' => ! is_file($manager->path($p->slug).DIRECTORY_SEPARATOR.'plugin.json'),
            ]),
            'addonsUrl' => LicenseAuthority::ADDONS_URL,
            'writable' => is_dir($manager->path()) ? is_writable($manager->path()) : is_writable(dirname($manager->path())),
            'path' => $manager->path(),
        ])->title(__('Add-ons'))->layoutData(['breadcrumbs' => [['label' => __('Administration')], ['label' => __('Add-ons')]]]);
    }
}
