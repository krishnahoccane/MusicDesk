<?php

namespace App\Services\Updates;

use App\Exceptions\LicenseException;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\Activity\ActivityLogger;
use App\Services\Backup\BackupService;
use App\Services\Installation\InstallationState;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseApiClient;
use App\Services\License\LicenseAuthority;
use App\Services\License\LicenseService;
use App\Services\License\LicenseValidationService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Over-the-air updates from MusicDesk.tech for LabelDesk and its add-ons
 * (Administration → Updates), WordPress style:
 *
 *   POST /updates/check     what is newer than the installed versions (every 12 hours, or "Check again")
 *   POST /updates/download  the signed package of one release, for this license / add-on key
 *
 * Every package is verified against the MusicDesk.tech signature before a file
 * is written. Automatic updates are off by default; they are switched on for
 * LabelDesk and for each add-on separately.
 */
class UpdateService
{
    public const STATUS = 'updates.status';

    public const AUTO_CORE = 'updates.auto_core';

    public const PROGRESS = 'updates.progress';

    public function __construct(
        protected SettingsService $settings,
        protected LicenseApiClient $api,
        protected InstallationState $installation,
        protected ActivityLogger $activity,
    ) {}

    // ------------------------------------------------------------ state

    /**
     * Updates come from MusicDesk.tech for installs activated online with a MusicDesk.tech
     * license (not for offline .ldlic activations or development test keys).
     */
    public function availability(): ?string
    {
        if (! config('labeldesk.updates.enabled', true)) {
            return __('Over-the-air updates are turned off on this server (LABELDESK_UPDATES=false).');
        }
        $activation = app(LicenseValidationService::class)->activation();
        if (! $activation) {
            return __('Activate LabelDesk first.');
        }
        if ($activation->provider !== LicenseAuthority::PRODUCTION_DRIVER && ! (LicenseAuthority::inTestSuite() && config('licensing.testing.updates'))) {
            return __('Updates are delivered to installs activated online with a MusicDesk.tech license.');
        }
        if ($activation->offline ?? false) {
            return __('This install was activated offline. Download new versions from your MusicDesk.tech account.');
        }

        return null;
    }

    /** @return array{checked_at: ?string, labeldesk: ?array, addons: array<string, array>, error: ?string} */
    public function status(): array
    {
        $status = $this->settings->get(self::STATUS);
        $status = is_array($status) ? $status : [];

        return [
            'checked_at' => $status['checked_at'] ?? null,
            'labeldesk' => is_array($status['labeldesk'] ?? null) ? $status['labeldesk'] : null,
            'addons' => is_array($status['addons'] ?? null) ? $status['addons'] : [],
            'error' => $status['error'] ?? null,
        ];
    }

    /** The newer LabelDesk release, if any. */
    public function coreUpdate(): ?array
    {
        $release = $this->status()['labeldesk'];

        return $release && version_compare((string) ($release['version'] ?? '0'), (string) config('labeldesk.product.version'), '>') ? $release : null;
    }

    public function addonUpdate(Plugin $plugin): ?array
    {
        $release = $this->status()['addons'][$plugin->addon] ?? null;

        return is_array($release) && version_compare((string) ($release['version'] ?? '0'), $plugin->version, '>') ? $release : null;
    }

    /** For the menu badge: releases newer than what is installed. */
    public function availableCount(): int
    {
        try {
            $count = $this->coreUpdate() ? 1 : 0;
            foreach (Plugin::query()->get() as $plugin) {
                $count += $this->addonUpdate($plugin) ? 1 : 0;
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    public function autoCore(): bool
    {
        return (bool) $this->settings->get(self::AUTO_CORE, false);
    }

    public function setAutoCore(bool $on, ?User $user = null): void
    {
        $this->settings->set(self::AUTO_CORE, $on);
        $this->activity->log('updates.auto', $on ? __('Turned on automatic LabelDesk updates') : __('Turned off automatic LabelDesk updates'), null, [], $user);
    }

    public function setAutoAddon(Plugin $plugin, bool $on, ?User $user = null): void
    {
        $plugin->forceFill(['auto_update' => $on])->save();
        $this->activity->log('updates.auto', $on
            ? __('Turned on automatic updates for :name', ['name' => $plugin->name])
            : __('Turned off automatic updates for :name', ['name' => $plugin->name]), $plugin, [], $user);
    }

    /** @return array{state: string, message: string, at: int}|null */
    public function progress(): ?array
    {
        $raw = @file_get_contents($this->progressFile());
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public function setProgress(string $state, string $message): void
    {
        File::ensureDirectoryExists(dirname($this->progressFile()));
        @file_put_contents($this->progressFile(), json_encode(['state' => $state, 'message' => $message, 'at' => now()->timestamp]), LOCK_EX);
    }

    public function busy(): bool
    {
        $progress = $this->progress();

        return $progress !== null && in_array($progress['state'], ['queued', 'downloading', 'installing'], true)
            && (int) $progress['at'] > now()->subMinutes(20)->timestamp;
    }

    protected function progressFile(): string
    {
        // Outside the cache (cache:clear runs during the update) and readable while maintenance mode is on.
        return storage_path('framework/labeldesk-update-progress.json');
    }

    // ------------------------------------------------------------ check

    /**
     * Ask MusicDesk.tech what is new. Stores the answer; never throws.
     */
    public function check(): array
    {
        if ($this->availability() !== null) {
            return $this->status();
        }

        try {
            $activation = app(LicenseValidationService::class)->activation();
            $addons = Plugin::query()->get()->map(fn (Plugin $p) => array_filter([
                'addon' => $p->addon,
                'slug' => $p->slug,
                'version' => $p->version,
                'token' => $p->license_token,
            ]))->values()->all();

            $data = $this->api->post('updates_check', [
                'token' => app(LicenseActivationService::class)->currentToken($activation),
                'version' => (string) config('labeldesk.product.version'),
                'php_version' => PHP_VERSION,
                'instance_id' => (string) $this->installation->installationId(),
                'addons' => $addons,
            ]);

            $status = [
                'checked_at' => now()->toIso8601String(),
                'labeldesk' => $this->release($data['labeldesk'] ?? null),
                'addons' => collect(is_array($data['addons'] ?? null) ? $data['addons'] : [])
                    ->map(fn ($r) => $this->release($r))->filter()->all(),
                'error' => null,
            ];
        } catch (Throwable $e) {
            $status = ['error' => $e instanceof LicenseException ? $e->getMessage() : __('MusicDesk.tech could not be reached.')] + $this->status();
            $status['checked_at'] = now()->toIso8601String();
        }

        $this->settings->set(self::STATUS, $status, 'json');

        return $this->status();
    }

    /** Keep only known fields of a release from the server. */
    protected function release(mixed $release): ?array
    {
        if (! is_array($release) || ! is_string($release['version'] ?? null) || ! preg_match('/^\d+\.\d+\.\d+[0-9A-Za-z.\-]*$/', $release['version'])) {
            return null;
        }

        return [
            'version' => $release['version'],
            'released_at' => is_string($release['released_at'] ?? null) ? $release['released_at'] : null,
            'changelog' => is_string($release['changelog'] ?? null) ? mb_substr($release['changelog'], 0, 20000) : null,
            'requires_php' => is_string($release['requires_php'] ?? null) ? $release['requires_php'] : null,
            'requires_labeldesk' => is_string($release['requires_labeldesk'] ?? null) ? $release['requires_labeldesk'] : null,
            'size' => is_numeric($release['size'] ?? null) ? (int) $release['size'] : null,
            'sha256' => is_string($release['sha256'] ?? null) ? strtolower($release['sha256']) : null,
            'downloadable' => (bool) ($release['downloadable'] ?? false),
            'reason' => is_string($release['reason'] ?? null) ? mb_substr($release['reason'], 0, 300) : null,
        ];
    }

    /** Why a release cannot be installed here (null = it can). */
    public function blocker(?array $release): ?string
    {
        if (! $release) {
            return __('No update available.');
        }
        if (! $release['downloadable']) {
            return $release['reason'] ?: __('Your updates period has ended. Renew it on MusicDesk.tech to get this version.');
        }
        if ($release['requires_php'] && version_compare(PHP_VERSION, $release['requires_php'], '<')) {
            return __('Version :version needs PHP :php or newer; this server runs :current.', ['version' => $release['version'], 'php' => $release['requires_php'], 'current' => PHP_VERSION]);
        }
        if ($release['requires_labeldesk'] && version_compare((string) config('labeldesk.product.version'), $release['requires_labeldesk'], '<')) {
            return __('Needs LabelDesk :version or newer. Update LabelDesk first.', ['version' => $release['requires_labeldesk']]);
        }
        if (! app(LicenseValidationService::class)->snapshot()->allowsWrites()) {
            return __('Updates cannot be installed while the license is read-only.');
        }

        return null;
    }

    // ------------------------------------------------------------ install

    /**
     * Download, verify and install the newer LabelDesk. The database upgrade runs on
     * the next page view (AutoUpgrade middleware).
     *
     * @return array{from: string, to: string}
     */
    public function installCore(?User $user = null, bool $automatic = false): array
    {
        $release = $this->coreUpdate();
        if (($blocker = $this->blocker($release)) !== null) {
            throw new RuntimeException($blocker);
        }
        $updater = app(CoreUpdater::class);
        if (($problems = $updater->problems()) !== []) {
            throw new RuntimeException(__('This server does not allow automatic updates: :problems Upload the new version by FTP or the file manager instead; the database update then runs on its own.', ['problems' => implode(' ', $problems)]));
        }

        $from = (string) config('labeldesk.product.version');
        $to = (string) $release['version'];

        // Download, checks and unpacking can take minutes on shared hosting.
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $this->setProgress('downloading', __('Downloading LabelDesk :version…', ['version' => $to]));
            $this->backupFirst($user);
            $file = $this->download('labeldesk', $to, $release, null);

            $this->setProgress('installing', __('Installing LabelDesk :version…', ['version' => $to]));
            $package = CorePackage::open($file, $to);
            try {
                $result = $updater->install($package);
            } finally {
                $package->close();
                @unlink($file);
            }
        } catch (Throwable $e) {
            $this->setProgress('failed', $e->getMessage());
            $this->activity->log('updates.failed', __('LabelDesk :version could not be installed: :reason', ['version' => $to, 'reason' => mb_substr($e->getMessage(), 0, 300)]), null, ['automatic' => $automatic], $user);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        $this->setProgress('done', __('LabelDesk :version is installed. Finishing the database update…', ['version' => $to]));
        $this->activity->log('updates.installed', __('Updated LabelDesk from :from to :to', ['from' => $from, 'to' => $to]), null, [
            'automatic' => $automatic, 'files' => $result['written'], 'kept' => $result['kept'],
        ], $user);
        $this->settings->set('updates.last_core', ['from' => $from, 'to' => $to, 'at' => now()->toIso8601String(), 'automatic' => $automatic], 'json');

        return ['from' => $from, 'to' => $to];
    }

    /** Download, verify and install the newer version of an add-on (creates / updates its tables too). */
    public function installAddon(Plugin $plugin, ?User $user = null, bool $automatic = false): Plugin
    {
        $release = $this->addonUpdate($plugin);
        if (($blocker = $this->blocker($release)) !== null) {
            throw new RuntimeException($blocker);
        }

        $from = $plugin->version;
        $file = $this->download($plugin->addon, (string) $release['version'], $release, $plugin);
        try {
            $updated = app(PluginManager::class)->install($file);
        } finally {
            @unlink($file);
        }

        $this->activity->log('updates.installed', __('Updated add-on :name from :from to :to', ['name' => $plugin->name, 'from' => $from, 'to' => $updated->version]), $updated, ['automatic' => $automatic], $user);

        return $updated;
    }

    protected function download(string $product, string $version, array $release, ?Plugin $plugin): string
    {
        $activation = app(LicenseValidationService::class)->activation();
        $dir = storage_path('app/private/updates');
        File::ensureDirectoryExists($dir);
        $file = $dir.'/download-'.preg_replace('/[^a-z0-9_\-]/i', '', $product).'-'.bin2hex(random_bytes(4)).'.zip';

        $result = $this->api->download('updates_download', array_filter([
            'token' => app(LicenseActivationService::class)->currentToken($activation),
            'product' => $product,
            'version' => $version,
            'addon_token' => $plugin?->license_token,
            'instance_id' => (string) $this->installation->installationId(),
        ]), $file);

        if ($release['sha256'] && ! hash_equals($release['sha256'], $result['sha256'])) {
            @unlink($file);
            throw new RuntimeException(__('The download is damaged (checksum mismatch). Try again.'));
        }

        return $file;
    }

    /** A database backup before a LabelDesk update, when backups are available. Never blocks the update. */
    protected function backupFirst(?User $user): void
    {
        if (! config('labeldesk.updates.backup_first', true)) {
            return;
        }

        try {
            $backups = app(BackupService::class);
            if (app(LicenseService::class)->canUse('backups') && $backups->pausedReason() === null) {
                $backups->create(false, $user, 'update');
            }
        } catch (Throwable $e) {
            Log::warning('Backup before update failed', ['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------ automatic

    /**
     * Background task (AutomaticTasks, every 12 hours): check, then install what
     * the administrator switched to automatic. LabelDesk first; add-ons on the next run.
     *
     * @return list<string> what was updated
     */
    public function runAutomatic(): array
    {
        if ($this->availability() !== null || $this->busy()) {
            return [];
        }
        $this->check();

        $done = [];
        if ($this->autoCore() && $this->coreUpdate() && $this->blocker($this->coreUpdate()) === null) {
            try {
                $result = $this->installCore(null, true);

                return ['labeldesk '.$result['to']];
            } catch (Throwable $e) {
                Log::warning('Automatic LabelDesk update failed', ['error' => $e->getMessage()]);

                return [];
            }
        }

        foreach (Plugin::query()->where('auto_update', true)->get() as $plugin) {
            if ($this->blocker($this->addonUpdate($plugin)) !== null) {
                continue;
            }
            try {
                $updated = $this->installAddon($plugin, null, true);
                $done[] = $plugin->slug.' '.$updated->version;
            } catch (Throwable $e) {
                Log::warning('Automatic add-on update failed', ['plugin' => $plugin->slug, 'error' => $e->getMessage()]);
                $plugin->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }
        }

        return $done;
    }
}
