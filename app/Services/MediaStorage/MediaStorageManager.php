<?php

namespace App\Services\MediaStorage;

use App\Models\MediaStorageTarget;
use App\Services\MediaStorage\Drivers\CloudDrive;
use App\Services\MediaStorage\Drivers\Dropbox;
use App\Services\MediaStorage\Drivers\Ftp;
use App\Services\MediaStorage\Drivers\GoogleDrive;
use App\Services\MediaStorage\Drivers\OneDrive;
use App\Services\MediaStorage\Drivers\OneDriveBusiness;
use App\Services\MediaStorage\Drivers\ZohoWorkDrive;
use App\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Settings → Media storage: any number of destinations (several Google Drives,
 * OneDrive, OneDrive for Business, Dropbox, Zoho WorkDrive, FTP), each
 * enabled or disabled, plus the shared "remove server copy after N days".
 */
class MediaStorageManager
{
    /** @var array<string, class-string<CloudDrive>> */
    public const DRIVERS = [
        'google_drive' => GoogleDrive::class,
        'onedrive' => OneDrive::class,
        'onedrive_business' => OneDriveBusiness::class,
        'dropbox' => Dropbox::class,
        'zoho_workdrive' => ZohoWorkDrive::class,
        'ftp' => Ftp::class,
    ];

    public function __construct(protected SettingsService $settings, protected HttpFactory $http) {}

    public function driver(MediaStorageTarget $target): CloudDrive
    {
        $class = self::DRIVERS[$target->driver] ?? throw new MediaStorageException(__('Unknown storage type.'));

        return new $class($target, $this->http);
    }

    /**
     * Enabled destinations that are connected and have a folder.
     *
     * @return Collection<int, MediaStorageTarget>
     */
    public function activeTargets(): Collection
    {
        try {
            if (! Schema::hasTable('media_storage_targets')) {
                return collect();
            }
        } catch (Throwable) {
            return collect();
        }

        return MediaStorageTarget::query()->where('enabled', true)->orderBy('id')->get()
            ->filter(fn (MediaStorageTarget $t) => array_key_exists($t->driver, self::DRIVERS) && $this->driver($t)->isReady())
            ->values();
    }

    public function enabled(): bool
    {
        return $this->activeTargets()->isNotEmpty();
    }

    public function deletesLocal(): bool
    {
        return (bool) $this->settings->get('media.delete_local', false);
    }

    public function deleteAfterDays(): int
    {
        return max(0, min(365, (int) $this->settings->get('media.delete_after_days', 7)));
    }

    /** When the server copy may be removed (null = keep). 0 days means 12 hours. */
    public function localDeleteAt(CarbonInterface $uploadedAt): ?CarbonInterface
    {
        if (! $this->deletesLocal()) {
            return null;
        }

        $days = $this->deleteAfterDays();

        return $days === 0 ? $uploadedAt->copy()->addHours(12) : $uploadedAt->copy()->addDays($days);
    }
}
