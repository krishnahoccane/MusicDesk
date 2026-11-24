# Media storage add-on

Copies master audio and original artwork to any number of destinations — several Google Drives,
OneDrive, OneDrive for Business, Dropbox, Zoho WorkDrive and FTP servers — each enabled or disabled
with its own folder: **Settings → Media storage**. Part of LabelDesk core until 4.3.4.

```
<folder>/<Release title>_<UPC>/<ISRC>.<ext>    master audio, byte for byte
<folder>/<Release title>_<UPC>/<UPC>.<ext>     original artwork
```

Uploads are verified (size and checksum). Renamed titles or codes rename the remote folder and files.
Optionally the server copy is removed N days after upload (0 = 12 hours), but only once every enabled
destination holds a verified copy, checked again just before deleting. Removed masters still play and
download: they are streamed from a destination.

| | |
|---|---|
| Slug | `media-storage` |
| Add-on code (license) | `media_storage` |
| Requires | LabelDesk 4.3.4 |
| Namespace | `LabelDesk\Addons\MediaStorage\` |
| Settings page | `settings/media-storage` (route `settings.media`), permission `settings.manage` |
| OAuth redirect URI | `<app URL>/settings/media-storage/callback/<driver>` (unchanged from core) |
| Tables | `media_storage_targets`, `media_storage_folders`, `media_storage_files` |
| Settings | `media.delete_local` (default off), `media.delete_after_days` (default 7) |
| Background task | `media`, every 5 minutes, no cron needed (`AutomaticTasks`) |
| Command | `php artisan labeldesk:media:sync [--budget=240] [--all]` |

## Upgrading from LabelDesk core

Installations that used media storage before 4.3.4 already have the tables, destinations, OAuth
tokens and settings. The add-on's migration only creates tables that are missing, so installing it
picks everything up again; nobody has to reconnect a drive or re-register a redirect URI.

While the add-on is not active, nothing is uploaded and masters whose server copy was removed cannot
be played or downloaded (LabelDesk answers 404 with a hint to activate the add-on). Deactivating or
deleting the add-on keeps the tables, records and settings, and the files already in the destinations.

## Extension points used

- `settingsTab('settings.media', …)` — the Settings tab.
- `backgroundTask('media', 5, …)` — `MediaSyncService::run(25)`.
- `fileSource(…)` — `Http\CloudStream` streams a cloud copy when a track's master is not on the server.
- `Track::saved` / `Release::saved` — queue uploads and renames.

## Layout

```
src/MediaStorageServiceProvider.php   wiring (routes, tab, task, file source, model hooks, command)
src/Livewire/Settings.php             Settings → Media storage
src/Http/OAuthController.php          OAuth connect / callback per destination
src/Http/CloudStream.php              streams a removed master from a destination (Range support)
src/Services/                         MediaStorageManager, MediaSyncService, MediaStorageException
src/Drivers/                          GoogleDrive, OneDrive, OneDriveBusiness, Dropbox, ZohoWorkDrive, Ftp
src/Models/                           MediaStorageTarget, MediaStorageFolder, MediaStorageFile
src/Console/SyncMedia.php             labeldesk:media:sync
database/migrations/                  creates the three tables only if missing
```

## Tests and build

```
php vendor/bin/phpunit addons/media-storage
php scripts/build-plugin.php addons/media-storage --key="<path to plugin-signing.key>"
```
