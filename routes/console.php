<?php

use App\Livewire\System\Info as SystemInfo;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Requires one cron entry on the server (see docs/installation.md):
|   * * * * * php /path/to/labeldesk/artisan schedule:run >> /dev/null 2>&1
|
*/

// License heartbeat: refresh the signed license cache once a day.
Schedule::command('labeldesk:license:verify')->dailyAt('03:17')->withoutOverlapping();

// Process queued jobs on hosting without a persistent worker.
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// Remove activity log entries older than the retention period (Settings → General).
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->dailyAt('02:41')->withoutOverlapping();

// Nightly backup, when enabled in Administration → Backups.
Schedule::command('labeldesk:backup --scheduled')->dailyAt('02:15')->withoutOverlapping();

// Heartbeat shown in Administration → System, so admins can see the cron job works.
Schedule::call(fn () => Cache::forever(SystemInfo::HEARTBEAT_KEY, now()->timestamp))->everyMinute()->name('labeldesk-heartbeat');
