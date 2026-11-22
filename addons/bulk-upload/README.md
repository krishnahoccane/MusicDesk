# Bulk upload add-on

Import artist profiles and releases with tracks from Excel (XLSX) or CSV templates:
**Catalog → Bulk upload**, with the access switches in **Settings → Bulk upload**.

| | |
|---|---|
| Slug | `bulk-upload` |
| Add-on code (license) | `bulk_upload` |
| Namespace | `LabelDesk\Addons\BulkUpload\` |
| Permission | `catalog.bulk_upload` ("Bulk upload artists and releases") |
| Table | `bulk_uploads` (upload history) |
| Settings | `bulk_upload.staff` (default on), `bulk_upload.artists` (default off) |

## Who may use it

- Administrators (`settings.manage`): always.
- Staff: roles with `catalog.bulk_upload` while "Label team" is on.
- Artist accounts: releases only, for their own artist profiles, while "Artist accounts" is on.
  Unknown artists are never created for them and other artists' names are refused.

On a new installation the first activation gives `catalog.bulk_upload` to Super Admin, Label Manager
and Artist Manager. Installations upgraded from LabelDesk core keep their roles and upload history.
Deactivating or deleting the add-on keeps the history and settings.

## Layout

```
src/BulkUploadServiceProvider.php   routes, gate "bulk-upload", permission, menu item, settings tab
src/Livewire/Index.php              Catalog → Bulk upload (check, then import)
src/Livewire/Settings.php           Settings → Bulk upload
src/Http/TemplateController.php     template downloads (CSV / XLSX)
src/Services/                       BulkUploadService, BulkSheet, ArtistBulkImporter, ReleaseBulkImporter
src/Models/BulkUpload.php
database/migrations/                creates bulk_uploads only if missing
```

## Tests and build

```
php vendor/bin/phpunit addons/bulk-upload/tests
php scripts/build-plugin.php addons/bulk-upload --key="<path to plugin-signing.key>"
```
