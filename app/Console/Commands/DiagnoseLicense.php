<?php

namespace App\Console\Commands;

use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseAuthority;
use App\Services\License\LicenseConnectivity;
use Illuminate\Console\Command;

/**
 * Tests the connection to the MusicDesk.tech license server (dummy key only).
 */
class DiagnoseLicense extends Command
{
    protected $signature = 'labeldesk:license:diagnose {--domain= : Domain to send (default: from APP_URL)}';

    protected $description = 'Test whether this server can reach the MusicDesk.tech license server';

    public function handle(LicenseConnectivity $connectivity): int
    {
        $domain = (string) ($this->option('domain') ?: ActivationContext::domainFrom((string) config('app.url')));
        $this->line('License server: '.LicenseAuthority::apiBaseUrl());
        $this->line('Domain sent:    '.$domain);
        $this->newLine();

        $result = $connectivity->diagnose($domain);

        foreach ($result['checks'] as $check) {
            $this->line(($check['ok'] ? '<info>OK</info>   ' : '<error>FAIL</error> ').$check['label'].': '.$check['detail']);
        }
        $this->newLine();
        $this->line('This server\'s public IP: '.($result['ip'] ?? 'unknown'));
        if ($result['ray']) {
            $this->line('Cloudflare Ray ID:       '.$result['ray']);
        }
        if ($result['advice']) {
            $this->newLine();
            $this->warn($result['advice']);
        }

        $result['ok'] ? $this->info('The license server can be reached.') : $this->error('The license server cannot be used from this server yet.');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
