# Installing LabelDesk

## 1. Server requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.2 (8.3 recommended) |
| PHP extensions | bcmath, ctype, curl, fileinfo, filter, gd, json, mbstring, openssl, pdo, pdo_mysql, sodium, tokenizer, xml, zip (intl recommended) |
| Database | MySQL 8.0+ or MariaDB 10.6+ (an **empty** database) |
| PHP settings | `memory_limit` ≥ 256M (artwork processing); `upload_max_filesize` and `post_max_size` large enough for your WAV/FLAC masters — a 5-minute 24-bit/48 kHz stereo WAV is about 85 MB, so 256M or more is recommended; `max_execution_time` ≥ 120 |
| Writable | `storage/`, `bootstrap/cache/`, and the LabelDesk folder during installation (to create `.env`) |
| Outbound HTTPS | to `musicdesk.tech` for license activation and periodic verification |

Node.js, Composer, Redis, Docker and SSH are **not** required.

The installer's *Requirements* step checks all of the above and tells you exactly what is missing.

## 2. cPanel / shared hosting

Use the **release ZIP** (`labeldesk-<version>.zip` from your MusicDesk.tech account). It already contains
`vendor/` (PHP libraries) and `public/build/` (compiled styles and scripts), so no Composer, Node or SSH
is needed. The source code on its own does not run: you would see "The upload is incomplete" (or, with
older versions, `Failed opening required …/vendor/autoload.php`).

**Before uploading:** in cPanel → MySQL Databases, create a database and a user, and add the user to
the database with **All Privileges**. In cPanel → Select PHP Version, choose PHP 8.2 or newer (8.3
recommended) and enable the extensions listed above.

**Option A — recommended: point the document root at `public`**

1. In cPanel → File Manager, upload the ZIP to your home folder (for example `/home/USER/labeldesk/`)
   and choose **Extract**.
2. In cPanel → Domains, set the domain's or subdomain's **document root** to
   `/home/USER/labeldesk/public`.
3. Enable SSL (cPanel → SSL/TLS Status → Run AutoSSL) so the site opens on `https://`.
4. Open the domain in a browser. The installation wizard starts.

**Option B — the files are already in `public_html` (document root cannot be changed)**

Extract the ZIP so that `public_html` contains `app/`, `public/`, `vendor/`, `.htaccess`, `index.php` and
the other files directly. Do not leave them in a sub-folder like `public_html/labeldesk-1.0.0/`. The
included `.htaccess` then:

- refuses `.env`, the source code, `storage/`, `vendor/` and every other private file (HTTP 403);
- serves the built assets from `public/`;
- sends everything else to LabelDesk.

It needs Apache or LiteSpeed with `mod_rewrite` (standard on cPanel). Hidden files must be uploaded:
in File Manager → Settings, tick **Show Hidden Files (dotfiles)** to check that `.htaccess` is there.
Option A is still preferred because private files are then outside the web folder entirely.

**No cron job is needed.** LabelDesk runs its background work by itself while it is being used, after
each page has been sent: hourly license check, queued jobs, cloud media uploads, the nightly backup and
clean-up (Administration → System shows when each last ran).

**Checks after uploading** (either option):

- Opening `https://your-domain/.env` must show **403 Forbidden**, never the file.
- If the page says "The upload is incomplete", `vendor/` or `public/build/` was not uploaded or
  extracted. Upload the release ZIP again (not the source code).

## 3. VPS (Ubuntu/Debian, Nginx)

```bash
sudo apt install nginx mariadb-server php8.3-fpm php8.3-{bcmath,curl,gd,mbstring,mysql,xml,zip,intl}
sudo mysql -e "CREATE DATABASE labeldesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'labeldesk'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
  GRANT ALL ON labeldesk.* TO 'labeldesk'@'localhost';"
sudo unzip labeldesk.zip -d /var/www/labeldesk
sudo chown -R www-data:www-data /var/www/labeldesk/storage /var/www/labeldesk/bootstrap/cache
sudo chown www-data /var/www/labeldesk      # so the installer can create .env (revert afterwards if you like)
```

Nginx server block (`root` must be the `public` folder):

```nginx
server {
    server_name labels.example.com;
    root /var/www/labeldesk/public;
    index index.php;
    client_max_body_size 512M;   # must allow your largest master file

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

Then enable HTTPS (e.g. `certbot --nginx`) and open the site. No cron entry is needed.

## 4. The installation wizard

Open your domain in a browser. Until LabelDesk is installed, every page leads to `/install`.

| Step | What happens |
|---|---|
| Welcome | Accept the installation terms |
| **0 · License** | Confirm the site address and enter your `LDSK-…` key. It is validated with MusicDesk.tech and activated for this domain. Servers without internet can use the offline request / `.ldlic` file instead |
| **1 · Server check** | PHP version, extensions and writable folders; failures must be fixed before continuing |
| **2 · Database** | MySQL 8 / MariaDB 10.6+ details; the connection is tested and a database already in use is refused |
| **3 · Redis** *(optional)* | Host, port, password, database number and TLS; choose cache, sessions and/or queue. Tested before continuing. **Skip** keeps the database drivers (works on any shared host). Uses the PHP redis extension if present, otherwise the bundled Predis client |
| **4 · Email** *(can be skipped)* | SMTP server, port, encryption, login and sender. **Send test email** checks it on the spot. Skipping logs emails until you set it up in Settings → Email |
| **5 · Super admin** | Your own account with full access (strong password required) |
| **6 · Customise** | Site name, time zone, currency, date format and language. Then the look: colour theme (MusicDesk by default) or your own brand colours, font, which colour modes are allowed and the default, sidebar style, logo / dark-mode logo / favicon, and the sign-in page: one of five designs (Spotlight, Split, Cover, Studio, Minimal) with your own headline, italic accent word, subtitle, form heading, note and cover image. A live preview recolours as you choose; **Preview the sign-in page** opens the real page |
| Review & install | A summary with a **Change** link per step, then **Install**. Tables, roles, your account and all settings are created; `.env` is written last, followed by the lock file |

Everything from steps 3, 4 and 6 can be changed later in **Settings** (Appearance, Catalogue, Email).
Passwords entered in the wizard are kept encrypted or hashed in the session and are never shown again.

## 5. Security of the installer

- The installer only works **before** installation. Afterwards every `/install` page returns 404.
- The lock file is `storage/app/private/installed.lock` (outside the public folder).
- There is no web-based "reinstall" option.
- LabelDesk never installs into a database that already contains tables with the chosen prefix.

## 6. Recovery

**Installation was interrupted** (browser closed, timeout, server error):
open the site again and run the wizard with the **same database**. LabelDesk detects the unfinished
installation and resumes it — migrations only add tables, defaults are only added if missing, and
nothing is deleted.

**The installer opens on a site that was already installed** (the lock file was lost, e.g. during a
hosting move): do **not** run the wizard. With SSH (or your host's "run command" tool) run:
```
php artisan labeldesk:install:restore-lock
```
It checks that the configured database contains a finished installation and recreates the lock.
Artist photos and documents are stored in `storage/app/private/artists/`. Include the whole
`storage/app/private` folder in your backups (the built-in backup tool arrives in a later version).

If you have no shell access, restore `storage/app/private/installed.lock` from your backup or contact
MusicDesk support.

**Reinstalling from scratch** (destroys nothing automatically): create a new empty database, delete
`storage/app/private/installed.lock` and `.env` via your file manager, and open the site.

## 7. License activation and transfer

- Your license key is issued only by MusicDesk.tech and is shown in your MusicDesk account.
- See **Settings → License** for status, plan, enabled features, last verification and actions:
  - **Verify now** — contacts MusicDesk.tech immediately.
  - **Deactivate installation** — releases the activation slot (requires your password). LabelDesk
    becomes read-only on this server; your data is kept.
  - **Transfer** — after moving to a new domain, set `APP_URL` in `.env` to the new address, sign in,
    and click *Transfer to new-domain*. MusicDesk.tech's account rules decide whether it is allowed.
- **Offline operation:** LabelDesk verifies the license daily. If MusicDesk.tech cannot be reached,
  everything keeps working until the last signed verification expires plus a 14-day grace period
  (a warning banner is shown). After that, LabelDesk switches to **read-only mode** until the license
  is verified again: you can still sign in, view and export data; changes are blocked. No data is ever
  deleted or modified because of a licensing problem.
