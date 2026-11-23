<?php

namespace App\Services\System;

use App\Models\ActivityLog;
use App\Plugins\AddonLicenseService;
use App\Services\Backup\BackupService;
use App\Services\Installation\InstallationState;
use App\Services\License\LicenseService;
use App\Services\License\LicenseValidationService;
use App\Services\MediaStorage\MediaSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background work without a cron job.
 *
 * After a page has been sent to the browser (PHP-FPM / LiteSpeed finish the
 * response first, so nobody waits), LabelDesk runs whatever is due:
 *
 *   license    check with MusicDesk.tech        every 60 minutes
 *   queue      queued jobs (imports, emails)    every minute
 *   media      cloud uploads, local cleanup     every 5 minutes
 *   backup     nightly backup (when enabled)    once a day, from 02:00
 *   cleanup    old activity log entries         once a day
 *
 * One run at a time (cache lock); a cheap "next check" timestamp means most
 * requests do nothing at all. Failures are logged, never shown to visitors.
 */
class AutomaticTasks
{
    public const LAST_RUN_KEY = 'labeldesk:auto:last-run';

    /** task => minutes between runs */
    public const TASKS = ['license' => 60, 'queue' => 1, 'media' => 5, 'backup' => 1440, 'cleanup' => 1440];

    public function __construct(protected InstallationState $installation) {}

    /**
     * Called after each response; returns the tasks that ran (for tests).
     *
     * @return list<string>
     */
    public function runDue(bool $force = false): array
    {
        if (! $this->installation->isInstalled()) {
            return [];
        }

        // Cheap gate: look at most once a minute.
        if (! $force && (int) Cache::get('labeldesk:auto:next-check', 0) > now()->timestamp) {
            return [];
        }
        Cache::put('labeldesk:auto:next-check', now()->addMinute()->timestamp, 300);

        $lock = Cache::lock('labeldesk:auto:running', 600);
        if (! $lock->get()) {
            return [];
        }

        $ran = [];
        try {
            @set_time_limit(300);
            @ignore_user_abort(true);

            foreach (self::TASKS as $task => $minutes) {
                if (! $this->isDue($task, $minutes)) {
                    continue;
                }
                Cache::forever($this->key($task), now()->timestamp);

                try {
                    $this->{'run'.ucfirst($task)}();
                    $ran[] = $task;
                } catch (Throwable $e) {
                    Log::warning('Automatic task failed', ['task' => $task, 'error' => $e->getMessage()]);
                }
            }

            Cache::forever(self::LAST_RUN_KEY, now()->timestamp);
        } finally {
            $lock->release();
        }

        return $ran;
    }

    protected function isDue(string $task, int $minutes): bool
    {
        $last = (int) Cache::get($this->key($task), 0);

        if ($task === 'backup') {
            // Nightly: the first run after 02:00 (label time zone) that is 20+ hours after the previous one.
            $local = now()->setTimezone((string) config('labeldesk.display_timezone', 'UTC'));

            return $local->hour >= 2 && now()->timestamp - $last >= 20 * 3600;
        }

        return now()->timestamp - $last >= $minutes * 60;
    }

    protected function key(string $task): string
    {
        return 'labeldesk:auto:'.$task;
    }

    public static function lastRun(string $task): ?int
    {
        $value = Cache::get('labeldesk:auto:'.$task);

        return $value ? (int) $value : null;
    }

    // ------------------------------------------------------------ tasks

    protected function runLicense(): void
    {
        app(LicenseValidationService::class)->refreshIfStale();
        app(AddonLicenseService::class)->refreshDue();
    }

    protected function runQueue(): void
    {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 25, '--tries' => 3, '--quiet' => true]);
    }

    protected function runMedia(): void
    {
        app(MediaSyncService::class)->run(25);
    }

    protected function runBackup(): void
    {
        $backups = app(BackupService::class);
        if ($backups->scheduled() && app(LicenseService::class)->canUse('backups') && $backups->pausedReason() === null) {
            @set_time_limit(0);
            $backups->create(true, null, 'scheduled');
        }
    }

    protected function runCleanup(): void
    {
        Artisan::call('model:prune', ['--model' => [ActivityLog::class]]);
    }
}
