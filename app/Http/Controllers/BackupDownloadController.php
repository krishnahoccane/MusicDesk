<?php

namespace App\Http\Controllers;

use App\Services\Activity\ActivityLogger;
use App\Services\Backup\BackupService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupDownloadController extends Controller
{
    public function __invoke(string $backup, BackupService $backups, ActivityLogger $activity): BinaryFileResponse
    {
        $path = $backups->path($backup);
        abort_unless($path !== null, 404);

        $activity->log('backup.downloaded', __('Downloaded backup :name', ['name' => $backup]));

        return response()->download($path, $backup, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
