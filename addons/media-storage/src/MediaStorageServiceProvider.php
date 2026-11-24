<?php

namespace LabelDesk\Addons\MediaStorage;

use App\Models\Release;
use App\Models\Track;
use App\Plugins\AddonServiceProvider;
use LabelDesk\Addons\MediaStorage\Console\SyncMedia;
use LabelDesk\Addons\MediaStorage\Http\CloudStream;
use LabelDesk\Addons\MediaStorage\Livewire\Settings;
use LabelDesk\Addons\MediaStorage\Services\MediaSyncService;
use Livewire\Livewire;

/**
 * Media storage: master audio and original artwork copied to any number of
 * destinations (Google Drive, OneDrive, OneDrive for Business, Dropbox,
 * Zoho WorkDrive, FTP), configured in Settings → Media storage.
 *
 * Moved out of LabelDesk core in 4.3.4. Installations upgraded from core keep
 * their destinations, upload records and settings (media.delete_local,
 * media.delete_after_days): the migrations only create missing tables, and
 * deactivating or deleting the add-on never removes them.
 */
class MediaStorageServiceProvider extends AddonServiceProvider
{
    public function boot(): void
    {
        $this->views();
        $this->migrations();
        $this->webRoutes('routes/web.php');

        Livewire::component('media-storage.settings', Settings::class);
        $this->settingsTab('settings.media', 'Media storage', 'cloud-upload');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncMedia::class]);
        }

        // Uploads and removal of server copies that are due, every 5 minutes without cron.
        $this->backgroundTask('media', 5, fn () => $this->app->make(MediaSyncService::class)->run(25), __('Cloud media uploads and clean-up'));

        // Play / download of masters whose server copy was removed: stream a cloud copy.
        $this->fileSource(fn (Track $track, string $disposition, string $filename, string $ascii) => $this->app->make(CloudStream::class)($track, $disposition, $filename, $ascii));

        // Queue uploads / renames when files or names change.
        Track::saved(function (Track $track) {
            if ($track->audio_path && ($track->wasRecentlyCreated || $track->wasChanged(['audio_path', 'isrc']))) {
                $this->app->make(MediaSyncService::class)->queueTrack($track);
            }
        });
        Release::saved(function (Release $release) {
            $sync = $this->app->make(MediaSyncService::class);
            if ($release->wasChanged(['title', 'upc'])) {
                $sync->queueRelease($release, withTracks: true); // folder rename
            } elseif ($release->wasChanged(['artwork_original_path', 'artwork_path'])) {
                $sync->queueRelease($release);
            }
        });
    }

    /**
     * Destinations, upload records and settings are kept: the tables may predate
     * the add-on, and files whose server copy was removed exist only in the cloud.
     */
    public function uninstall(): void {}
}
