<?php

namespace App\Services\License;

use App\Services\License\Data\ActivationContext;
use Illuminate\Http\Response;

/**
 * Activation request file for servers without internet access. The customer
 * uploads it in the MusicDesk portal (Licenses → Offline activation) and
 * receives a .ldlic file with a token valid for 365 days.
 */
class OfflineRequestFile
{
    /**
     * @return array<string, string>
     */
    public static function contents(ActivationContext $context): array
    {
        return [
            'format' => 'ldreq-1',
            'product' => 'LabelDesk',
            'domain' => $context->domain,
            'instance_id' => $context->installationId,
            'version' => $context->version,
            'php_version' => PHP_VERSION,
        ];
    }

    public static function download(ActivationContext $context): Response
    {
        $name = 'labeldesk-activation-request-'.preg_replace('/[^a-z0-9.-]+/', '-', $context->domain).'.json';

        return response((string) json_encode(self::contents($context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
