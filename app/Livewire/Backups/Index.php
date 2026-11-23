<?php

namespace App\Livewire\Backups;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Backup\BackupService;
use App\Services\SettingsService;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Administration → Backups: create, download and delete backups; nightly
 * schedule and retention.
 */
class Index extends Component
{
    use EnforcesLicense;

    public bool $includeFiles = true;

    public bool $schedule = false;

    public int $retention = BackupService::DEFAULT_RETENTION;

    public function mount(BackupService $backups): void
    {
        $this->schedule = $backups->scheduled();
        $this->retention = $backups->retention();
    }

    public function create(BackupService $backups): void
    {
        $this->ensureFeature('backups');

        if ($paused = $backups->pausedReason()) {
            $this->dispatch('toast', type: 'warning', message: $paused);

            return;
        }

        try {
            $result = $backups->create($this->includeFiles, auth()->user());
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Backup created (:size, :rows database rows, :files files).', [
            'size' => Format::bytes($result['size']), 'rows' => number_format($result['rows']), 'files' => number_format($result['files']),
        ]));
    }

    public function delete(string $name, BackupService $backups): void
    {
        $this->ensureFeature('backups');

        $backups->delete($name, auth()->user())
            ? $this->dispatch('toast', type: 'success', message: __('Backup deleted.'))
            : $this->dispatch('toast', type: 'error', message: __('That backup no longer exists.'));
    }

    public function saveSettings(SettingsService $settings, BackupService $backups): void
    {
        $this->ensureFeature('backups');
        $this->ensureLicenseAllowsWrites();

        $this->validate(['retention' => ['required', 'integer', 'min:1', 'max:90'], 'schedule' => ['boolean']]);

        $settings->setMany(['backups.schedule' => $this->schedule, 'backups.retention' => $this->retention]);
        $removed = $backups->prune();

        $this->dispatch('toast', type: 'success', message: $removed
            ? trans_choice('Backup settings saved. :count old backup was removed.|Backup settings saved. :count old backups were removed.', $removed)
            : __('Backup settings saved.'));
    }

    public function render(BackupService $backups): View
    {
        return view('livewire.backups.index', [
            'backups' => $backups->list(),
            'paused' => $backups->pausedReason(),
            'directory' => 'storage/app/private/backups',
        ])->title(__('Backups'))->layoutData(['breadcrumbs' => [['label' => __('Administration')], ['label' => __('Backups')]]]);
    }
}
