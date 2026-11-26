# Changelog

All notable changes to LabelDesk are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses Semantic Versioning.

## [4.3.6] — 2026-11-25

### Added
- **Save as draft** on the release and track forms: saves what is filled in and keeps you on the form,
  even when artists or credits are still incomplete (empty rows are left out). Draft tracks are marked
  "Draft" in the track lists, and a release cannot be submitted while one of its tracks is a draft.
- **Upload progress in percent** for master audio, artwork and every other file upload (artist photos,
  documents, royalty statements, add-ons, logos).
- **Mood** on tracks, from PDL's mood list (Romantic, Happy, Sad, Dance, Bhangra, Patriotic, …). The
  value PDL expects is stored with each mood, so deliveries to PDL never need translating.
- **Caller tune (CRBT)** section on the track form: ring-back tone name (up to 64 characters) and the
  start of the 30-second cut, with "Use player position" for tracks that have audio. The cut must end
  before the track does.
- **New artist** button in the Artists card of the release and track forms: adds an artist to the roster
  and to the form without leaving the release or track you are editing.
- **Label website** (Settings → Website), off by default: a public website at the address of the
  installation with a home page (headline, featured release, latest and upcoming releases, artists,
  about, contact), a music catalogue, release pages with tracklists, an artist roster and artist pages.
  It uses the colours, fonts, logo and light/dark mode from Settings → Appearance. Only approved or
  ready releases (released, or upcoming if you choose) and active artists with such a release are
  shown; e-mail addresses, phone numbers, notes, contracts, earnings and audio files never are.
  When the website is off, the address opens the sign-in page as before — for labels with their own site.
- **10 website themes:** Aurora, Minimal, Noir, Editorial, Neon, Vinyl, Studio, Bold, Pastel and Cinema,
  each with its own type, shapes and backgrounds (some always light or always dark).
- **Website pages:** About us, Services, Pricing and Contact us, plus your own pages at `/pages/…`, written
  in Markdown with structured lists for services and pricing plans. Publish, unpublish, order and choose
  which pages appear in the menu. New pages start empty and unpublished.
- **Contact form** on the Contact us page: messages are kept under Settings → Website → Pages and messages
  and e-mailed to the label's public address (with a bot trap and a limit of 3 messages a minute).
- **Installer step 6:** switch the website on, pick its theme and write the headline and introduction.
- **Home page sections** (Settings → Website → Home page), each hidden until filled in: your own buttons
  and line above the headline, a moving strip of the stores you deliver to, a band of numbers, your
  services (from the Services page), "How it works" steps, testimonials, an FAQ and a closing call-to-action
  banner — the building blocks common to music distribution and label websites.

### Changed
- **Singles:** a single's track takes the release title, and follows it when the release is renamed.
- **Composer and lyricist:** new tracks start with a composer row and a lyricist row, and a track needs
  both to be saved (a songwriter counts as both; Save as draft skips the check). The release checklist
  asks for "a composer and a lyricist" on every track.
- **Track length** is no longer typed in: it is read from the uploaded audio and shown in the upload
  preview (with the format) before the track is saved.
- **Track numbers** are the order of the release's track list (1, 2, 3 …); the Disc field is gone from the
  track form. Moving a track up or down works across the whole release. Disc numbers of earlier
  multi-disc releases are kept, so their order does not change.

### Database
- New `moods` table; tracks get `mood_id`, `crbt_name`, `crbt_start_seconds` and `is_draft`. New
  `site_pages` and `site_messages` tables for the website. The update runs by itself the first time a
  page is opened after the files are uploaded.

## [4.3.5] — 2026-11-25

### Fixed
- **After an update every page showed "500 Internal Server Error"** on installations whose
  `bootstrap/cache` still listed packages of the previous files (for example a site installed from a
  development copy: `Class "Laravel\Pail\PailServiceProvider" not found`). LabelDesk now checks these
  cached lists when it starts and rebuilds the ones that are out of date — after an update from the
  Updates page and after uploading files by FTP or the file manager alike.
- The updater removes those cached lists itself right after replacing the files.
- Files that a new version no longer contains (such as the media storage code that moved to the add-on in
  4.3.4) are removed during an update. They are moved to the rollback folder first, and only when the
  installed version's file list carries a valid MusicDesk.tech signature.
- The update no longer stops half-way on servers with a short PHP time limit: the limit is lifted before
  the download and the package checks, not only before the files are replaced.
- Error pages show even when LabelDesk cannot start completely, instead of the bare "Oops! An Error
  Occurred" page.

## [4.3.4] — 2026-11-24

### Changed
- **Media storage is now an add-on.** Copying master audio and artwork to Google Drive, OneDrive,
  OneDrive for Business, Dropbox, Zoho WorkDrive or FTP (Settings → Media storage) moved out of
  LabelDesk into the **Media storage** add-on from MusicDesk.tech (add-on code `media_storage`).
  Install it under Administration → Add-ons and activate it with its own key: Settings → Media storage
  comes back with the same destinations, folders, upload history and options, and the sign-in links of
  the drives keep the same address, so connected accounts don't need to be set up again.
- **Nothing is deleted.** Existing destinations, the record of what was uploaded and the files on the
  drives stay as they are. Until the add-on is active, nothing new is uploaded, and masters that were
  already removed from the server after upload can't be played or downloaded in LabelDesk. They are
  still on the drive and come back as soon as the add-on is active.
- New installations no longer create the cloud media tables; the add-on creates them when it is installed.

### Added
- Add-ons can run their own background work without a cron job, shown under Administration → System
  (`backgroundTask()`), and serve track masters that are no longer on the server (`fileSource()`).

### Fixed
- "Page not found" pages now show LabelDesk's own explanation when there is one (for example why a
  master can't be fetched), instead of always the generic text.
- Release packages never include local database files (such as a development `database.sqlite`).

## [4.3.3] — 2026-11-23

### Added
- **Updates from MusicDesk.tech** (Administration → Updates, like WordPress): LabelDesk checks
  MusicDesk.tech twice a day for new versions of itself and of every installed add-on, shows what is new
  with a count in the menu, and installs an update with one click. Every package must carry the
  MusicDesk.tech signature and match its checksum before a file is written.
- **Automatic updates**, off by default: switch them on for LabelDesk and for each add-on separately
  (on the Updates page, or "Update automatically" on each add-on under Administration → Add-ons).
- Updating LabelDesk takes a database backup first (when backups are in your plan), shows a short
  "being updated" page while files are replaced, and puts every file back if anything goes wrong.
  `.env`, `storage/`, installed add-ons and your own `.htaccess` are never overwritten.
- New permission "Install LabelDesk and add-on updates" (Super Admin).

### Changed
- **The database updates itself after new files are uploaded** — by an update from the Updates page or
  by FTP / the hosting file manager. The first page opened afterwards runs the database changes and
  clears the configuration, route, cache and compiled-view caches in the background, while visitors see a
  "being updated" page for a few seconds. No terminal or SSH needed, so it works on shared hosting.
  If it fails, the site keeps running, the error is shown on Administration → System and Updates, and it
  is tried again every 15 minutes. `php artisan labeldesk:upgrade` and "Run upgrade" still work.
- **Add-ons create their database tables when they are installed** (before, only on activation), like
  WordPress plugins. Updating an add-on updates its tables straight away.
- Release packages can be signed for over-the-air updates
  (`php scripts/package-release.php --key=<plugin-signing.key>`).

### Fixed
- Installing an add-on did nothing: after choosing the .zip and clicking Install the button stayed
  greyed out (browser error "Cannot read properties of undefined (reading 'name')"). The same fault
  stopped uploads of artist documents and royalty statements.

### MusicDesk.tech
- Over-the-air updates need two new license-server endpoints and signed LabelDesk releases on
  MusicDesk.tech; see `docs/musicdesk-portal-changes-4.3.3.md`. Until they are live, the Updates page
  says it could not check for updates and everything else works as before.

## [4.3.2] — 2026-11-23

### Added
- **Sign-up for artists and labels** (off by default): "Create an account" on the sign-in page with a
  form for artists (artist name) and labels (label name, website). Turn it on during installation
  (step 6) or in Settings → General → Sign-up, and choose whether artists, labels or both may sign up.
  New accounts cannot sign in until an administrator activates them in Users; administrators are
  emailed for every sign-up and the person is emailed when approved. Artists get an artist account
  linked to their new artist profile; label sign-ups create an inactive sub-label that is activated with
  the account (the administrator chooses the account's role). Strong passwords, a bot trap and a limit
  of 5 sign-ups a minute.
- **Catalog → Labels**: sub-labels and imprints under the main label, nestable (a label can be part of
  another), with code, contact, website, country and active/inactive status. Each release can be
  assigned to a label; the label name delivered to stores follows it. Labels with releases or
  sub-labels cannot be deleted. New permissions "View labels" and "Manage labels".
- **Deactivation needs an emailed code**: after the administrator's password, MusicDesk.tech emails a
  one-time code to the email the license is registered to; the installation is deactivated only with
  that code. Offline activations still need only the password.
- `docs/musicdesk-portal-changes-4.3.2.md`: what the MusicDesk.tech portal and license server need
  for this version.

### Changed
- **Backups pause from 30 days before the license expires** (and while it is expired or suspended):
  "Back up now" and the nightly backup are turned off with an explanation until the license is
  renewed. Existing backups can still be downloaded.
- The footer shows only the version (e.g. v4.3.2); the sidebar shows only your logo and label name;
  the sign-in and suspension pages no longer say "Powered by LabelDesk".

### Fixed
- The account menu (top right) opened behind the page content.
- On Settings → License, the Deactivate dialog could not be used: the dark backdrop covered it and
  blocked typing the password.
- Messages meant for the sign-in page (for example "The license is active again. You can sign in.")
  were not shown.

## [4.3.1] — 2026-11-23

### Added — change the license key
- **Change license key** on Settings → License: enter the new key and confirm your password. The new key
  is checked with MusicDesk.tech first; only when it is accepted is the old key released for this domain
  and replaced. Refused keys change nothing.
- **On the suspension page** (no one can sign in there): "Administrator: use a different license key"
  asks for the new key plus the email and password of an active administrator allowed to manage the
  license. Nobody is signed in by it; on success the sign-in page opens. Limited to 5 attempts a minute
  and logged.
- The suspension page shows the actual reason when the installation was blocked by MusicDesk.tech
  rather than expired.

### Added — add-ons (plugins)
- **Administration → Add-ons**: upload add-ons downloaded from MusicDesk.tech, then activate,
  deactivate or delete them, like WordPress plugins. Packages must be signed by MusicDesk.tech
  (Ed25519 signature over every file); unsigned, changed or unsafe packages are refused before anything
  is extracted. Uploading a newer version updates an add-on in place.
- **Every add-on has its own license key** (`LDAD-…`), verified with MusicDesk.tech for this domain and
  re-checked every hour. An add-on runs only while it is active and its key is valid; a revoked,
  suspended or expired key, or a domain removed from the main license, pauses it (its data is kept).
- Add-ons can add pages, sidebar items, permissions, Settings tabs, sign-in buttons, `/api` routes and
  database tables. Developer guide: `docs/plugins.md`; signed builds with `scripts/build-plugin.php`.
- Available add-ons: **Bulk upload**, **REST API**, **Incoming webhooks**, **Single sign-on (SSO)**.

### Changed — bulk upload is an add-on
- **Bulk upload** moved out of LabelDesk core into the **Bulk upload** add-on (`addons/bulk-upload`,
  add-on code `bulk_upload`). Catalog → Bulk upload appears only while the add-on is active with a valid
  key. Its access switches moved from Settings → Catalogue to their own tab, **Settings → Bulk upload**.
  The permission "Bulk upload artists and releases" (`catalog.bulk_upload`) now belongs to the add-on;
  roles keep it on upgrade, and on a new installation it is given to Label Manager and Artist Manager the
  first time the add-on is activated. Upload history (`bulk_uploads`) is kept.

### Added — license enforcement and cloud media storage
- **No cron job needed**: background work (hourly license check, queued jobs, media uploads every
  5 minutes, nightly backup from 02:00, clean-up) runs by itself after page loads, once at a time; status
  in Administration → System. Optional `php artisan labeldesk:tasks:run`.
- **Hard-wired licensing**: server address, issuer, signing key and provider are constants
  (`LicenseAuthority`); the `LICENSE_*` `.env` settings are gone and ignored. The development provider
  only runs in tests or `php artisan serve` with `APP_ENV=local`.
- **Expiry policy**: read-only from the expiry date for 10 days (sign-in allowed), then the application is
  suspended — no sign-in — until renewed. Hourly license checks; "I have renewed — check again" on the
  suspension page. Ribbons: expiring in 30 days (staff dashboards), expired (every page and the sign-in
  page, with the lock date; countdown on staff dashboards).
- **Media storage** (Settings → Media storage): any number of storage destinations, each with its own
  enable/disable switch, account and folder — several Google Drives, OneDrive (personal), OneDrive for
  Business (Microsoft 365, organisation tenant), Dropbox, Zoho WorkDrive (all data centres) and FTP/FTPS.
  Every file is copied to every enabled storage. Per-release folders `<Release title>_<UPC>` with masters
  as `<ISRC>.<ext>` and artwork as `<UPC>.<ext>` in their original format (verified by size and checksum —
  MD5, SHA-1 or Dropbox content hash), automatic renames, optional removal of the server copy after N days
  (0 = 12 hours) only once every enabled storage holds a verified copy, with streaming back from a
  storage. A storage cannot be removed while files exist only there. Uploads run every 5 minutes.
- **License check failure alert**: every failed license check emails the vendor (PHP mail(), subject
  "<domain> - license check failed") and posts domain name, error, whether the email was sent, and the
  server's public IP address to the vendor's webhook. No license key or token is included in either.
  The failure is also reported to the license server's security webhook (`POST /report` on
  MusicDesk.tech, at most once an hour per reason); if it answers `lock` (domain blocked or key revoked)
  the installation is suspended until a later license check succeeds.
- Administration → System no longer lists the license check among the automatic tasks.
- `CHANGELOG.txt`: plain-text copy of the full change history, included in the release package.
- The original artwork file is now kept alongside the re-encoded display copy.
- Server (MusicDesk-Frontend): tokens carry the signed `expires` claim and the response `expires_at`.

### Fixed — installation on shared hosting
- License activation failed with "License verification failed." on new installs: the `.env` template
  pointed at an old placeholder address (`/api/v1/licensing`) instead of `https://musicdesk.tech/api/license/v1`.
  The template is corrected and installs created from the old template are redirected automatically.
- Clear errors when something other than the license server answers: a Cloudflare block (with the Ray ID),
  a wrong server address, or another HTTP error. New "Test connection to MusicDesk.tech" button in the
  installer and `php artisan labeldesk:license:diagnose` (dummy key only; shows the server's public IP).
- Missing `vendor/` or `public/build/` shows an "upload is incomplete" page instead of a PHP fatal error;
  a root `.htaccess` + `index.php` make installs extracted into `public_html` safe; a hand-copied `.env`
  without `APP_KEY` gets one before installation.

### Added — MusicDesk design system, sign-in designs, new installer
- **MusicDesk look** (from the MusicDesk UI/UX guide): ink canvas with frosted-glass cards,
  white-alpha hairlines, volt/flare gradients, tinted status badges, mono table headers and eyebrows,
  Inter Tight + Instrument Serif accent + JetBrains Mono (bundled, no font CDN), soft page glow (volt for
  the label team, accent for artist accounts) and film grain. New default theme "MusicDesk", dark by
  default; light mode keeps the same recipes. Branded page-header eyebrows on every screen.
- **White-label**: custom brand colours (dark-mode shades derived automatically), font choice, logo,
  dark-mode logo and favicon uploads (SVGs with scripts are refused), served without a storage symlink.
- **Five sign-in page designs** — Spotlight, Split, Cover, Studio, Minimal — with editable headline,
  italic accent word, subtitle, form heading, note and cover image; previews for administrators.
- **New installation wizard**: 0 license (with site address) → 1 server check → 2 database → 3 Redis
  (optional, tested, choose cache/sessions/queue) → 4 SMTP (test email, or skip) → 5 super admin →
  6 full customisation with live preview → review with "Change" links → install.

### Added — Milestone 10 (Commercial release) and artist accounts
- **Artist accounts**: a new "Artist" role for artists' own logins, linked to one or more artist profiles.
  Artists see and edit only their own profiles, releases, tracks, credits, deliveries, contracts and
  earnings; everything else (other artists, label revenue, users, settings) is hidden or answers 404.
- **Bulk upload** (Catalog → Bulk upload): artists and releases with tracks from Excel / CSV templates,
  check-before-import with row-level problems, optional creation of missing artists, upload history.
  Administrators always; the label team and artist accounts each have an on/off switch
  (Settings → Catalogue). *Now the Bulk upload add-on, see Unreleased.*
- **Import sales reports** (Reports → Import sales reports): choose or create a period, upload, map and
  process distributor / store sales reports; recent imports across all periods.
- Branded error pages (403, 404, 419, 429, 500, 503) that work without built assets.
- Release packaging script (`scripts/package-release.php`) and guides `docs/user-guide.md`, `docs/release.md`.
- Route security tests: every application route requires sign-in; guests are redirected; viewers are
  refused on management pages.

### Changed / security — Milestone 10
- The private storage disk is no longer served by URL (`storage/{path}` route removed); private files are
  only served by LabelDesk controllers after permission checks.
- ISRC and UPC duplicate checks always cover the whole catalogue (also for artist accounts) without
  revealing other artists' releases.
- Royalty statements can be opened with `payments.view` (artist accounts, own artists only).
- Indexes on royalty lines for revenue by store and by territory.
- Dependency audit: `composer audit` and `npm audit` report no known vulnerabilities.

### Added — Milestone 9 (Reports, backups, configuration, redesign)
- **New look**: redesigned dashboard (welcome hero, quick actions, KPI cards, revenue / release / store
  charts, upcoming releases, "Needs attention", administration panel with license, user allowance and
  system health, activity timeline), sidebar, top bar and cards.
- **Themes**: eight colour themes (Aurora, Ocean, Emerald, Sunset, Rose, Midnight, Graphite, Sand), three
  sidebar styles, and a light/dark policy (both, light only, dark only) with a default mode — chosen by
  the administrator in Settings → Appearance with live preview.
- **Global search** (Ctrl/Cmd+K or "/") across artists, releases, tracks, contributors and users,
  respecting permissions and the license plan.
- **Reports**: releases by status and date, catalogue gaps (missing audio / ISRC / credits), store
  deliveries, distribution by store, royalty revenue by period / artist / release / track / store /
  territory, artist balances (as of a date), artist payments, contracts ending soon. Filters, on-screen
  totals, CSV and Excel export, export log.
- **Backups**: database (pure PHP, no mysqldump) and uploaded files in one ZIP, download, delete,
  retention, nightly schedule, `labeldesk:backup` command, restore guide (`docs/backups.md`).
- **Settings → Catalogue**: label name, © / ℗ line formats, default language, genres and languages.
- **Administration → System**: versions, server, database, storage, scheduler heartbeat, queue and email
  status; database upgrade button and `labeldesk:upgrade` command.
- **Licensing**: connected to the production MusicDesk.tech licensing server (v1: activate, check-in,
  deactivate) with Ed25519 JWT verification, server messages, support-until and latest-version info,
  staging domains, automatic re-activation, and offline activation with request / `.ldlic` files.

### Changed — Milestone 9
- `users.theme` is now nullable (NULL = not chosen) so the administrator's default mode applies.
- Starter genre, language and store lists are seeded only once; upgrades no longer re-add entries the
  label deleted.
- License keys use the `LDSK-XXXX-XXXX-XXXX-XXXX` format; plans are Starter, Business and Enterprise.

### Added — Milestone 8 (Royalties)
- Royalty periods (non-overlapping; lockable after they end), with matched revenue, artist and label
  totals and unmatched amounts.
- Statement imports: CSV (comma/semicolon/tab, auto-detected) and XLSX via OpenSpout (streamed),
  automatic column guessing, saved mapping profiles, decimal point or comma, currency with exchange rate,
  live preview of parsed amounts, duplicate-file protection, queued processing with a "Process now"
  fallback for hosts without cron, revert.
- Matching by ISRC (original release preferred over compilations) or UPC; manual matching of unknown
  identifiers to a track, or "not ours".
- Exact allocation: equal split between primary artists (rounding remainder to the last), artist share
  from the recording/distribution/license contract in force on the sale date, else the configurable
  default artist share; label commission is the remainder.
- Artist accounts: earnings posted on lock, recoupable advances and expenses, payments (never above the
  balance), adjustments and reversals; artist balances page and artist Earnings tab.
- Royalty statements per artist and period (on screen, printable, CSV and XLSX), provisional while open.
- Dashboard royalty figures.

### Added — Milestone 7 (Distribution)
- Store list (27 major DSPs seeded) with lead times, delivery notes, activate/deactivate and management
  screen (`dsps.manage`).
- Release → Distribution tab: add stores (ready releases only), mark delivered (back-datable), mark live
  with store ID and link, store rejections and takedowns (reason required), cancel or confirm takedowns,
  bulk "mark delivered/live", remove pending stores, per-store history, lead-time warnings.
- Business → Distribution overview: counts by status, filters by store and status, search, and releases
  that are ready but not sent anywhere; dashboard distribution figures.
- Releases cannot be reopened while pending, delivered or live at any store.

### Added — Milestone 6 (Music catalog)
- Releases: list (search by title, artist, UPC or catalogue no.; status/type/genre filters), create/edit
  with ordered artist credits (primary/featuring/remixer), UPC checksum validation, ©/℗ lines, genre and
  language, square artwork (re-encoded, thumbnail), soft delete/restore while editable.
- Tracks: per-release track list with disc/position ordering, ISRC validation and uniqueness (re-use
  allowed on compilations), artist credits, songwriter/producer credits with publishing shares (≤100%
  per role), explicit flag (release explicit follows its tracks), global Tracks list.
- Master audio: WAV/FLAC uploads inspected from the file header (sample rate, bit depth, channels,
  duration; ≥44.1 kHz/16-bit), stored privately, streamed with range support and downloadable only with
  `tracks.download_master` (downloads logged).
- Contributors directory (IPI, PRO) with inline creation from the track form.
- Approval workflow: draft → submitted → in review → approved → ready for delivery, request changes
  with a required comment, withdraw, reopen; a completeness checklist gates submission and approval;
  content is locked outside draft; full status history.
- Artist profile Releases tab; artists credited on releases or tracks cannot be deleted.
- Reference data seeder (genres, languages); dashboard release figures.

### Added — Milestone 5 (Artist management)
- Artists: list with search, status/type/country filters, sorting and pagination; create/edit with
  photo upload (re-encoded to a clean JPEG); soft delete and restore; profile page with tabs for
  overview, contacts, links, contracts, documents and activity.
- Contacts (one primary per artist), streaming/social links validated per platform (http/https only).
- Contracts: type, status, term, artist royalty share (DECIMAL percent), advance (DECIMAL(18,6)) and
  currency; active contracts past their end date show as expired; Business → Contracts overview with
  an "ending within 60 days" filter and dashboard figures.
- Private documents: stored under random names on the non-public disk, file type checked from content,
  size-limited, downloaded only through an authorised route (logged), optional link to a contract.
- License features: `artists` gates the artist module; `documents` gates contracts and documents.
- Upload limits configurable via `LABELDESK_DOCUMENT_MAX_KB` / `LABELDESK_IMAGE_MAX_KB`.

### Added — Milestone 4 (Authentication & administration)
- Password reset by email with generic responses (no account discovery), throttling, active
  accounts only; resetting signs the user out everywhere.
- My profile: name, email (current password required), theme and password change.
- Users: search and filter by role/status, add (with an emailed set-password link or a chosen
  password), edit, deactivate, delete and restore, resend password link. The license user allowance
  is enforced on the server; there is always one active Super Admin; nobody can deactivate, delete or
  re-role themselves, or grant access they do not have.
- Roles: built-in roles keep their names but their permissions are editable; custom roles require
  the `custom_roles` license feature; permissions grouped by module with select-all.
- Settings: General (label name, timezone, currency, date/time format, language, log retention) and
  Email (SMTP/sendmail/log, encrypted password, test email), applied over `.env` at boot.
- Activity log: sign-ins, failures, lockouts, user/role/settings changes; filter by event, user,
  date and text; expandable details; nightly pruning by retention period.
- `auth.session` middleware so password changes end other sessions.

### Changed
- Super Admin's `Gate::before` bypass now applies to permission checks only; model policies (e.g.
  "cannot delete yourself") apply to Super Admins too.
- `RolesAndPermissionsSeeder` no longer re-grants permissions an administrator removed from a
  built-in role.

### Added — Milestone 3 (Licensing)
- MusicDesk.tech licensing client: provider abstraction (MusicDesk, future Envato, development mock),
  HTTPS-only API client with idempotency keys, replay protection and HMAC request signing.
- Ed25519-signed license tokens verified with an embedded public key; signed offline cache with a
  14-day grace period, then documented read-only mode. Fails closed when not configured.
- Feature entitlements from the license (`canUse()`, `feature:` middleware, locked navigation items).
- Settings → License page: masked key, plan, expiry, activations, user allowance, features, history;
  Verify now, Deactivate (password confirmed), Transfer to new domain, re-activation.
- Daily `labeldesk:license:verify` heartbeat and opportunistic refresh for hosts without cron.

### Added — Milestone 2 (Installation wizard)
- Browser installer: welcome & terms, requirements check, database test (refuses non-empty databases),
  application settings, administrator, license activation, install, one-time completion page.
- Automatic `.env` + application key creation on first visit; atomic, escaped `.env` writer.
- Permanent installation lock outside `public/`; installer returns 404 once installed.
- Interrupted installations resume safely; `labeldesk:install:restore-lock` recovery command.
- Default roles (Super Admin, Label Manager, Artist Manager, Distribution Manager, Accountant, Viewer)
  and 41 permissions; settings store.
- Installation guide (`docs/installation.md`) for cPanel and VPS.

### Added — Milestone 1 (Foundation)
- Laravel 12 application with Livewire 3, Spatie Laravel Permission and OpenSpout.
- Bootstrap 5 design system compiled from SCSS with CSS-variable design tokens.
- Light, dark and system themes, persisted per user, applied before first paint.
- Application shell: collapsible sidebar, mobile off-canvas navigation, top bar, breadcrumbs,
  toast notifications.
- Reusable Blade components: icon (server-rendered Lucide), logo, page header, stat card, badge,
  alert, empty state, breadcrumbs.
- Sign-in and sign-out with per-email+IP throttling, session regeneration and inactive-account
  blocking.
- Security headers middleware.
- Config-driven, permission-aware sidebar navigation.
