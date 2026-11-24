<?php

namespace LabelDesk\Addons\MediaStorage\Livewire;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\MediaStorage\Drivers\CloudDrive;
use LabelDesk\Addons\MediaStorage\Http\OAuthController;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageFile;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageTarget;
use LabelDesk\Addons\MediaStorage\Services\MediaStorageException;
use LabelDesk\Addons\MediaStorage\Services\MediaStorageManager;
use LabelDesk\Addons\MediaStorage\Services\MediaSyncService;
use Livewire\Component;

/**
 * Settings → Media storage: any number of destinations (several Google
 * Drives, OneDrive, OneDrive for Business, Dropbox, Zoho WorkDrive, FTP),
 * each enabled or disabled, with its own folder.
 */
class Settings extends Component
{
    use EnforcesLicense;

    /** Add / edit form */
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $type = 'google_drive';

    public string $name = '';

    /** @var array<string, string> */
    public array $creds = [];

    /** Folder picker */
    public ?int $browsingId = null;

    public string $browseId = '';

    /** @var list<array{id: string, name: string}> */
    public array $path = [];

    /** @var list<array{id: string, name: string}> */
    public array $folders = [];

    public string $newFolder = '';

    /** Shared options */
    public bool $delete_local = false;

    public int $delete_after_days = 7;

    public function mount(SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->delete_local = (bool) $settings->get('media.delete_local', false);
        $this->delete_after_days = (int) $settings->get('media.delete_after_days', 7);
    }

    protected function guard(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    protected function manager(): MediaStorageManager
    {
        return app(MediaStorageManager::class);
    }

    protected function target(int $id): MediaStorageTarget
    {
        return MediaStorageTarget::query()->findOrFail($id);
    }

    /** @return class-string<CloudDrive> */
    protected function driverClass(string $type): string
    {
        abort_unless(array_key_exists($type, MediaStorageManager::DRIVERS), 404);

        return MediaStorageManager::DRIVERS[$type];
    }

    // ------------------------------------------------------------ add / edit

    public function startAdd(string $type): void
    {
        $this->guard();
        $class = $this->driverClass($type);
        $count = MediaStorageTarget::query()->where('driver', $type)->count();

        $this->fill([
            'showForm' => true,
            'editingId' => null,
            'type' => $type,
            'name' => $class::label().($count ? ' '.($count + 1) : ''),
            'creds' => collect($class::fields())->map(fn ($f) => (string) ($f['default'] ?? ''))->all(),
        ]);
        $this->browsingId = null;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->guard();
        $target = $this->target($id);
        $class = $this->driverClass($target->driver);

        $this->fill([
            'showForm' => true,
            'editingId' => $target->id,
            'type' => $target->driver,
            'name' => $target->name,
            // Secrets are never sent back to the browser.
            'creds' => collect($class::fields())->map(fn ($f, $k) => ($f['secret'] ?? false) ? '' : (string) $target->credential($k, $f['default'] ?? ''))->all(),
        ]);
        $this->browsingId = null;
        $this->resetErrorBag();
    }

    public function cancelForm(): void
    {
        $this->reset('showForm', 'editingId', 'creds', 'name');
    }

    public function saveTarget(ActivityLogger $activity)
    {
        $this->guard();
        $class = $this->driverClass($this->type);
        $existing = $this->editingId ? $this->target($this->editingId) : null;

        $rules = ['name' => ['required', 'string', 'max:80']];
        $attributes = ['name' => __('name')];
        foreach ($class::fields() as $key => $field) {
            $hasStored = $existing && filled($existing->credential($key));
            $required = ($field['required'] ?? false) && ! (($field['secret'] ?? false) && $hasStored);
            $rules['creds.'.$key] = array_values(array_filter([
                $required ? 'required' : 'nullable', 'string', 'max:500',
                ($field['type'] === 'select') ? Rule::in(array_keys($field['options'] ?? [])) : null,
                $key === 'port' ? 'regex:/^\d{1,5}$/' : null,
                $key === 'host' ? 'regex:/^[A-Za-z0-9.\-]+$/' : null,
            ]));
            $attributes['creds.'.$key] = mb_strtolower((string) $field['label']);
        }
        $this->validate($rules, [], $attributes);

        $values = [];
        foreach ($class::fields() as $key => $field) {
            $value = trim((string) ($this->creds[$key] ?? ''));
            if (($field['secret'] ?? false) && $value === '' && $existing) {
                continue; // keep the stored secret
            }
            $values[$key] = $value;
        }

        $target = $existing ?? new MediaStorageTarget(['driver' => $this->type, 'enabled' => false]);
        $target->name = trim($this->name);
        $target->setCredentials($values);
        $target->save();
        $this->manager()->driver($target)->forgetAccessToken();

        $activity->log('settings.updated', $existing ? __('Updated storage :name', ['name' => $target->name]) : __('Added storage :name', ['name' => $target->name]));
        $this->cancelForm();

        if ($class::usesOAuth()) {
            return $this->redirectRoute('settings.media.connect', $target);
        }

        // FTP: log in now to test the details.
        try {
            $account = $this->manager()->driver($target)->testConnection();
            $target->fill(['account' => $account, 'connected_at' => now()])->save();
            $this->dispatch('toast', type: 'success', message: __(':name is connected. Now choose its folder.', ['name' => $target->name]));
        } catch (MediaStorageException $e) {
            $target->fill(['connected_at' => null, 'enabled' => false])->save();
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }

        return null;
    }

    public function toggle(int $id, MediaSyncService $sync, ActivityLogger $activity): void
    {
        $this->guard();
        $target = $this->target($id);

        if (! $target->enabled && ! $this->manager()->driver($target)->isReady()) {
            $this->dispatch('toast', type: 'danger', message: __('Connect :name and choose its folder before enabling it.', ['name' => $target->name]));

            return;
        }

        $target->update(['enabled' => ! $target->enabled]);
        $queued = $target->enabled ? $sync->queueAll($target) : 0;
        $activity->log('settings.updated', $target->enabled ? __('Enabled storage :name', ['name' => $target->name]) : __('Disabled storage :name', ['name' => $target->name]));

        $this->dispatch('toast', type: 'success', message: $target->enabled
            ? trans_choice(':name enabled. :count existing file is queued.|:name enabled. :count existing files are queued.', $queued, ['name' => $target->name])
            : __(':name disabled. Files already there stay.', ['name' => $target->name]));
    }

    public function delete(int $id, MediaSyncService $sync, ActivityLogger $activity): void
    {
        $this->guard();
        $target = $this->target($id);

        $only = $sync->onlyIn($target);
        if ($only > 0) {
            $this->dispatch('toast', type: 'danger', message: trans_choice(
                ':count file exists only in :name (its server copy was removed), so it cannot be removed.|:count files exist only in :name (their server copies were removed), so it cannot be removed.',
                $only, ['name' => $target->name],
            ));

            return;
        }

        $this->manager()->driver($target)->forgetAccessToken();
        $target->delete();
        $activity->log('settings.updated', __('Removed storage :name', ['name' => $target->name]));
        $this->dispatch('toast', type: 'success', message: __('Removed. Files already uploaded stay in that storage.'));
    }

    // ------------------------------------------------------------ folder picker

    public function browse(int $id): void
    {
        $this->guard();
        $drive = $this->manager()->driver($this->target($id));
        $this->browsingId = $id;
        $this->showForm = false;
        $this->path = [['id' => $drive->rootId(), 'name' => $drive->rootName()]];
        $this->open($drive->rootId());
    }

    public function open(string $folderId, ?string $name = null): void
    {
        $this->guard();
        abort_if($this->browsingId === null, 422);

        try {
            $this->folders = $this->manager()->driver($this->target($this->browsingId))->listFolders($folderId);
        } catch (MediaStorageException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());

            return;
        }

        if ($name !== null) {
            $this->path[] = ['id' => $folderId, 'name' => $name];
        }
        $this->browseId = $folderId;
    }

    public function up(int $index): void
    {
        $this->path = array_slice($this->path, 0, $index + 1);
        $this->open($this->path[$index]['id']);
    }

    public function createFolder(): void
    {
        $this->guard();
        $this->newFolder = trim($this->newFolder);
        $this->validate(['newFolder' => ['required', 'string', 'max:120', 'not_regex:/[\\\\\/:*?"<>|]/']], [], ['newFolder' => __('folder name')]);

        try {
            $id = $this->manager()->driver($this->target($this->browsingId))->createFolder($this->newFolder, $this->browseId);
        } catch (MediaStorageException $e) {
            $this->addError('newFolder', $e->getMessage());

            return;
        }

        $name = $this->newFolder;
        $this->reset('newFolder');
        $this->open($id, $name);
    }

    public function chooseFolder(ActivityLogger $activity): void
    {
        $this->guard();
        $target = $this->target($this->browsingId);
        $label = implode(' / ', array_column($this->path, 'name'));
        $target->update(['folder_id' => $this->browseId, 'folder_name' => $label]);
        $activity->log('settings.updated', __('Storage :name folder set to :folder', ['name' => $target->name, 'folder' => $label]));

        $this->reset('browsingId', 'folders', 'path', 'browseId');
        $this->dispatch('toast', type: 'success', message: __('Files for :name will be stored in :folder. Enable it to start uploading.', ['name' => $target->name, 'folder' => $label]));
    }

    public function closeBrowser(): void
    {
        $this->reset('browsingId', 'folders', 'path', 'browseId');
    }

    // ------------------------------------------------------------ shared options

    public function saveOptions(SettingsService $settings, MediaSyncService $sync): void
    {
        $this->guard();
        $this->validate(['delete_local' => ['boolean'], 'delete_after_days' => ['required', 'integer', 'min:0', 'max:365']], [], ['delete_after_days' => __('days')]);

        $settings->setMany(['media.delete_local' => $this->delete_local, 'media.delete_after_days' => $this->delete_after_days]);
        $sync->rescheduleLocalDeletion();
        $this->dispatch('toast', type: 'success', message: __('Saved.'));
    }

    public function syncNow(MediaSyncService $sync): void
    {
        $this->guard();
        @set_time_limit(120);
        $stats = $sync->run(45);

        $this->dispatch('toast', type: $stats['failed'] ? 'warning' : 'success', message: __('Uploaded :up, failed :failed, server copies removed :removed. Larger files continue in the background.', [
            'up' => $stats['uploaded'], 'failed' => $stats['failed'], 'removed' => $stats['removed'],
        ]));
    }

    public function queueAll(MediaSyncService $sync): void
    {
        $this->guard();
        $count = $sync->queueAll();
        $this->dispatch('toast', type: 'success', message: trans_choice(':count file queued.|:count files queued.', $count));
    }

    public function render(): View
    {
        $manager = $this->manager();
        $targets = MediaStorageTarget::query()->orderBy('id')->get();
        $counts = MediaStorageFile::query()->selectRaw('target_id, status, count(*) as n')->groupBy('target_id', 'status')->get()
            ->groupBy('target_id')->map(fn ($rows) => $rows->pluck('n', 'status'));

        $class = MediaStorageManager::DRIVERS[$this->type] ?? MediaStorageManager::DRIVERS['google_drive'];

        return view('media-storage::settings', [
            'types' => collect(MediaStorageManager::DRIVERS)->map(fn ($c) => $c::label())->all(),
            'targets' => $targets->map(fn (MediaStorageTarget $t) => [
                'model' => $t,
                'label' => (MediaStorageManager::DRIVERS[$t->driver] ?? null)::label(),
                'oauth' => (MediaStorageManager::DRIVERS[$t->driver] ?? null)::usesOAuth(),
                'connected' => $manager->driver($t)->isConnected(),
                'ready' => $manager->driver($t)->isReady(),
                'counts' => $counts[$t->id] ?? collect(),
            ]),
            'fields' => $class::fields(),
            'formLabel' => $class::label(),
            'formOAuth' => $class::usesOAuth(),
            'redirectUri' => OAuthController::redirectUri($this->type),
            'hasSecret' => fn (string $key) => $this->editingId && filled($this->target($this->editingId)->credential($key)),
            'browsingName' => $this->browsingId ? $this->target($this->browsingId)->name : null,
            'problems' => MediaStorageFile::query()->whereIn('status', ['failed', 'waiting'])
                ->with(['release:id,title', 'track:id,title', 'target:id,name'])->latest('updated_at')->limit(6)->get(),
            'totals' => MediaStorageFile::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ])->title(__('Media storage'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Media storage')],
        ]]);
    }
}
