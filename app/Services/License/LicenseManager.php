<?php

namespace App\Services\License;

use App\Services\License\Contracts\LicenseProviderInterface;
use App\Services\License\Providers\EnvatoLicenseProvider;
use App\Services\License\Providers\MockLicenseProvider;
use App\Services\License\Providers\MusicDeskLicenseProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves license providers by name. New sales channels register here
 * without changes to the installer or the license pages.
 */
class LicenseManager
{
    /** @var array<string, class-string<LicenseProviderInterface>> */
    protected array $providers = [
        'musicdesk' => MusicDeskLicenseProvider::class,
        'envato' => EnvatoLicenseProvider::class,
        'mock' => MockLicenseProvider::class,
    ];

    public function __construct(protected Container $container) {}

    public function provider(?string $name = null): LicenseProviderInterface
    {
        $name ??= (string) config('licensing.driver', 'musicdesk');

        $class = $this->providers[$name] ?? throw new InvalidArgumentException("Unknown license provider [{$name}].");

        return $this->container->make($class);
    }

    /**
     * @param  class-string<LicenseProviderInterface>  $class
     */
    public function extend(string $name, string $class): void
    {
        $this->providers[$name] = $class;
    }
}
