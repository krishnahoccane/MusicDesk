<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Plugin;
use App\Services\Updates\CoreUpdater;
use App\Services\Updates\UpdateService;
use App\Services\System\UpgradeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * Administration → Updates: new versions of LabelDesk and of each add-on from
 * MusicDesk.tech, one-click install and per-product automatic updates (WordPress style).
 */
class Updates extends Component
{
    use EnforcesLicense;

    protected function guard(bool $writes = true): void
    {
        abort_unless(auth()->user()?->can('updates.manage'), 403);
        if ($writes) {
            $this->ensureLicenseAllowsWrites();
        }
    }

    protected function updates(): UpdateService
    {
        return app(UpdateService::class);
    }

    public function mount(): void
    {
        $this->guard(false);
        // First visit, or the last answer is older than 12 hours: ask MusicDesk.tech now.
        $checked = $this->updates()->status()['checked_at'];
        if ($this->updates()->availability() === null && (! $checked || now()->diffInHours($checked, true) >= 12)) {
            $this->updates()->check();
        }
    }

    public function checkNow(): void
    {
        $this->guard(false);
        $status = $this->updates()->check();
        $this->dispatch('toast', type: $status['error'] ? 'danger' : 'success', message: $status['error'] ?? __('Checked for updates.'));
    }

    public function installCore(): void
    {
        $this->guard();
        $updates = $this->updates();
        if ($updates->busy()) {
            $this->dispatch('toast', type: 'warning', message: __('An update is already running.'));

            return;
        }
        if (($blocker = $updates->blocker($updates->coreUpdate())) !== null) {
            $this->dispatch('toast', type: 'danger', message: $blocker);

            return;
        }
        if (($problems = app(CoreUpdater::class)->problems()) !== []) {
            $this->dispatch('toast', type: 'danger', message: implode(' ', $problems));

            return;
        }

        $user = auth()->user();
        $updates->setProgress('queued', __('Starting the update…'));
        // Download and install after this response is sent; the page follows the progress.
        app()->terminating(function () use ($updates, $user) {
            try {
                $updates->installCore($user);
            } catch (Throwable $e) {
                report($e);
            }
        });
        $this->dispatch('update-started');
    }

    public function installAddon(int $id): void
    {
        $this->guard();
        $plugin = Plugin::query()->findOrFail($id);

        try {
            $updated = $this->updates()->installAddon($plugin, auth()->user());
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', type: 'danger', message: __(':name could not be updated: :reason', ['name' => $plugin->name, 'reason' => mb_substr($e->getMessage(), 0, 300)]));

            return;
        }

        $this->dispatch('toast', type: 'success', message: __(':name was updated to :version.', ['name' => $updated->name, 'version' => $updated->version]));
    }

    public function toggleAutoCore(): void
    {
        $this->guard(false);
        $updates = $this->updates();
        $updates->setAutoCore(! $updates->autoCore(), auth()->user());
        $this->dispatch('toast', type: 'success', message: $updates->autoCore()
            ? __('LabelDesk will install its updates automatically.')
            : __('LabelDesk updates will wait for you to install them.'));
    }

    public function toggleAutoAddon(int $id): void
    {
        $this->guard(false);
        $plugin = Plugin::query()->findOrFail($id);
        $this->updates()->setAutoAddon($plugin, ! $plugin->auto_update, auth()->user());
        $this->dispatch('toast', type: 'success', message: $plugin->auto_update
            ? __(':name will be updated automatically.', ['name' => $plugin->name])
            : __('Automatic updates for :name are off.', ['name' => $plugin->name]));
    }

    public function render(): View
    {
        $updates = $this->updates();
        $core = $updates->coreUpdate();
        $upgrade = app(UpgradeService::class);

        return view('livewire.system.updates', [
            'availability' => $updates->availability(),
            'status' => $updates->status(),
            'core' => $core,
            'coreBlocker' => $core ? $updates->blocker($core) : null,
            'coreChangelog' => $core && $core['changelog'] ? Str::markdown($core['changelog'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null,
            'autoCore' => $updates->autoCore(),
            'serverProblems' => app(CoreUpdater::class)->problems(),
            'progress' => $updates->busy() ? $updates->progress() : null,
            'lastProgress' => $updates->progress(),
            'upgradeFailure' => $upgrade->failure(),
            'addons' => Plugin::query()->orderBy('name')->get()->map(fn (Plugin $p) => [
                'model' => $p,
                'release' => $release = $updates->addonUpdate($p),
                'blocker' => $release ? $updates->blocker($release) : null,
            ]),
            'version' => (string) config('labeldesk.product.version'),
        ])->title(__('Updates'))->layoutData(['breadcrumbs' => [['label' => __('Administration')], ['label' => __('Updates')]]]);
    }
}
