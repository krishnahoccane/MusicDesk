<?php

namespace App\Console\Commands;

use App\Exceptions\LicenseException;
use App\Services\Installation\InstallationState;
use App\Services\License\LicenseValidationService;
use Illuminate\Console\Command;

/**
 * Periodic license heartbeat (scheduled daily). Never prints the key.
 */
class VerifyLicense extends Command
{
    protected $signature = 'labeldesk:license:verify';

    protected $description = 'Verify this installation\'s license with MusicDesk.tech and refresh the signed cache';

    public function handle(InstallationState $installation, LicenseValidationService $validation): int
    {
        if (! $installation->isInstalled()) {
            $this->warn('LabelDesk is not installed.');

            return self::SUCCESS;
        }

        try {
            $snapshot = $validation->refresh();
        } catch (LicenseException $e) {
            $this->error('License verification failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('License verified: '.$snapshot->state->label().' ('.($snapshot->result?->plan ?? 'unknown').' plan).');

        return self::SUCCESS;
    }
}
