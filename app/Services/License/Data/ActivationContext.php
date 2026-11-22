<?php

namespace App\Services\License\Data;

/**
 * Identifies this installation to the licensing authority.
 */
final class ActivationContext
{
    public function __construct(
        public readonly string $domain,
        public readonly string $applicationUrl,
        public readonly string $installationId,
        public readonly string $version,
        public readonly string $productCode,
    ) {}

    /**
     * Build from an application URL and installation id.
     */
    public static function for(string $applicationUrl, string $installationId): self
    {
        return new self(
            domain: self::domainFrom($applicationUrl),
            applicationUrl: rtrim($applicationUrl, '/'),
            installationId: $installationId,
            version: (string) config('labeldesk.product.version', config('licensing.product_version')),
            productCode: (string) config('licensing.product_code'),
        );
    }

    /**
     * Stable, non-reversible fingerprint of this installation + domain.
     */
    public function fingerprint(): string
    {
        return hash_hmac('sha256', strtolower($this->domain), $this->installationId);
    }

    public static function domainFrom(string $url): string
    {
        return self::normalizeDomain($url);
    }

    /**
     * Same rules as the MusicDesk license server: lower-case, without scheme,
     * path, query, port, "www." and trailing dot.
     */
    public static function normalizeDomain(string $value): string
    {
        $d = strtolower(trim($value));
        $d = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $d);
        $d = (string) preg_replace('#[/?\#].*$#', '', $d);
        $d = (string) preg_replace('#:\d+$#', '', $d);
        $d = (string) preg_replace('/^www\./', '', $d);

        return rtrim($d, '.');
    }

    /**
     * Staging domains are free and unlimited on MusicDesk licenses.
     */
    public function isStaging(): bool
    {
        return $this->domain === 'localhost'
            || (bool) preg_match('/^(staging|dev|test)\.|\.(test|local|localhost|invalid)$/', $this->domain)
            || (bool) filter_var($this->domain, FILTER_VALIDATE_IP);
    }
}
