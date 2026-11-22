<?php

/*
|--------------------------------------------------------------------------
| No cron job needed
|--------------------------------------------------------------------------
|
| LabelDesk runs its background work itself, after pages are served (see
| App\Services\System\AutomaticTasks): hourly license check, queued jobs,
| cloud media uploads, the nightly backup and clean-up.
|
| Optional: `php artisan labeldesk:tasks:run` runs whatever is due now.
|
*/
