# Backups and restore

LabelDesk can back up its database and uploaded files from **Administration → Backups**
(permission `backups.manage`, license feature `backups`).

## What a backup contains

Each backup is one ZIP file in `storage/app/private/backups`:

| Entry | Contents |
|---|---|
| `database.sql` | Every table's structure and data (session, cache, job and password-reset rows are skipped) |
| `manifest.json` | LabelDesk version, date, database driver, row count per table, number of files |
| `files/private/…` | Master audio, contracts and documents, royalty statement files (`storage/app/private`) |
| `files/public/…` | Release artwork and other public uploads (`storage/app/public`) |

"Database only" backups (file name ending in `-db.zip`) skip the two `files/` folders.

**Not included:** the `.env` file. It holds your database and mail passwords and the application key
(`APP_KEY`). Keep a copy of `.env` somewhere safe and private: encrypted values in the backup (the
license key and the SMTP password) can only be read with the same `APP_KEY`.

## Making backups

- **Back up now** on the Backups page.
- **Nightly**: turn on "Back up every night" (runs at 02:15 server time). This needs the LabelDesk cron job:
  `* * * * * php /path/to/labeldesk/artisan schedule:run >> /dev/null 2>&1`
- **SSH**: `php artisan labeldesk:backup` (add `--database-only` to skip files).
- **Retention**: "Backups to keep" (1–90, default 7). Older backups are deleted after each new one.

Download important backups and keep copies off the server. A backup stored only on the same server is
lost if the server is lost.

## Restoring

Restoring replaces all current data, so LabelDesk does not offer it as a button. To restore:

1. Download the backup and unzip it on your computer.
2. In cPanel → **MySQL Databases**, create an empty database and user (or empty the existing database).
3. In cPanel → **phpMyAdmin**, select that database → **Import** → choose `database.sql` → Go.
   For large files use SSH: `mysql -u USER -p DATABASE < database.sql`.
4. Upload the contents of `files/private` to `storage/app/private` and `files/public` to
   `storage/app/public` (File Manager or FTP).
5. Make sure `.env` has the database name, user and password from step 2 and the **same `APP_KEY`** as
   the installation the backup came from.
6. If you restored onto a newer LabelDesk version, run the upgrade: Administration → System →
   **Run upgrade**, or `php artisan labeldesk:upgrade`.
7. Sign in and check Settings → License. If the domain changed, transfer the license there.

## Upgrading LabelDesk

1. Take a backup.
2. Upload the new LabelDesk files over the old ones (keep `.env` and `storage/`).
3. Open Administration → System and click **Run upgrade** (or `php artisan labeldesk:upgrade`).
   The upgrade only adds database changes and new permissions; it never drops data.
