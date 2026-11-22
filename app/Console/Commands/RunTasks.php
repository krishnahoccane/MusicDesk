<?php

namespace App\Console\Commands;

use App\Services\System\AutomaticTasks;
use Illuminate\Console\Command;

/**
 * Optional: run due background work now (it also runs automatically on page visits).
 */
class RunTasks extends Command
{
    protected $signature = 'labeldesk:tasks:run';

    protected $description = 'Run the background tasks that are due (license check, queue, media uploads, backup, clean-up)';

    public function handle(AutomaticTasks $tasks): int
    {
        $ran = $tasks->runDue(force: true);
        $this->info($ran === [] ? 'Nothing was due.' : 'Ran: '.implode(', ', $ran).'.');

        return self::SUCCESS;
    }
}
