# LabelDesk — Development Progress

## Environment (re-checked 2026-11-20 after XAMPP reinstall)

| Item | Finding |
|------|---------|
| OS | Windows 11 (development machine) |
| PHP | **8.3.36** CLI (XAMPP, `F:\xampp\php`) |
| PHP extensions | All required present. Enabled `gd`, `intl`, `sodium`, `zip` in `php.ini` (backup: `php.ini.labeldesk-backup`) |
| Composer / Node | 2.6.6 / 22.20.0 (Node is build-time only) |
| Database | **MariaDB 10.11.16** on 127.0.0.1:3306 — dev database `labeldesk` |
| Tests | SQLite in-memory (`vendor/bin/phpunit`) |
| Git | Repository initialised, nothing committed yet |

Local dev runs with `APP_ENV=local` and `LICENSE_DRIVER=mock`. Dev credentials are documented in
`database/seeders/LocalDevelopmentSeeder.php`.

---

## Milestone 1 — Foundation ✅

Laravel 12.73 + Livewire 3, Spatie Permission, OpenSpout; Bootstrap 5 design system with CSS-variable
tokens; light/dark/system themes persisted per user; app shell (collapsible sidebar, mobile
off-canvas, top bar, breadcrumbs, toasts); reusable Blade components; login/logout with throttling,
session regeneration and inactive-account blocking; security headers; config-driven navigation.
Tests: 20 passed. See git history / CHANGELOG for the file list.

---

## Milestone 2 — Installation wizard ✅

**Files created**
- Services (`app/Services/Installation`): `InstallationState` (lock + resumable progress),
  `EnvironmentBootstrapper` (creates `.env` + APP_KEY on first visit, before Laravel boots),
  `EnvironmentWriter` (atomic, escaped `.env` updates), `RequirementsChecker`, `DatabaseInspector`
  (read-only connection/version/emptiness check, password-safe errors), `DatabaseConfigurator`,
  `InstallerSession` (step tracking; DB password encrypted, admin password hashed), `InstallationService`.
- `app/Services/SettingsService.php`, `app/Models/Setting.php`
- Controllers `app/Http/Controllers/Installer/*` (Welcome, Requirements, Database, Application,
  Administrator, License, Install, Complete); Form Requests `app/Http/Requests/Installer/*`
- Middleware: `EnsureApplicationInstalled` (prepended to web group), `RedirectIfInstalled`
- `routes/installer.php`; views `resources/views/installer/*`, layout `components/layouts/installer`,
  `resources/scss/_installer.scss`
- `app/Enums/SystemRole.php`, `database/seeders/RolesAndPermissionsSeeder.php` (6 roles, 41 permissions)
- `app/Console/Commands/RestoreInstallationLock.php` (`labeldesk:install:restore-lock`)
- Migration `2026_11_20_100000_create_settings_table.php`
- `docs/installation.md` (requirements, cPanel, VPS/Nginx, wizard, security, recovery, license transfer)
- Tests `tests/Installation/*` (access/locking, requirements, env writer, full wizard)

**Behaviour verified**
- Fresh upload → `/install`; all other routes redirect there until installed; afterwards every
  installer route returns 404 and the completion page works once with a one-time token.
- Steps cannot be skipped; changing the URL invalidates the license step.
- Non-empty databases are refused; interrupted installs resume against the same database
  (migrations forward-only, idempotent seeders, admin upserted). `.env` is written only after
  migrations succeed; the lock is written last.
- No plain passwords in session, progress file or logs; DB password with quotes/`#`/`$` round-trips.

## Milestone 3 — Licensing integration ✅ (client) / ⏳ external server pending

**Files created**
- `config/licensing.php`
- `app/Services/License/`: `Contracts/LicenseProviderInterface`, `Data/{ActivationContext,LicenseResult,LicenseSnapshot}`,
  `LicenseApiClient` (HTTPS-only, idempotency keys reused across retries, timestamp+nonce, HMAC signing),
  `LicenseSignatureVerifier` (Ed25519 via libsodium), `LicenseManager`, `LicenseActivationService`,
  `LicenseValidationService` (state, grace period, refresh, opportunistic refresh), `LicenseCacheService`,
  `LicenseFeatureService` (`canUse()`), `LicenseService`, `Providers/{MusicDesk,Envato,Mock}LicenseProvider`
- `app/Enums/LicenseState.php`, `app/Exceptions/LicenseException.php`
- Models `LicenseActivation` (key + secret encrypted, masked display), `LicenseCache`, `LicenseEvent`
- Middleware `LicenseMiddleware` (read-only policy), `EnsureFeatureEnabled` (`feature:x`);
  Livewire trait `App\Livewire\Concerns\EnforcesLicense`
- `app/Http/Controllers/License/LicenseStatusController.php`, views `license/show`, `license/feature-unavailable`;
  license banner in the app layout; navigation locks plan-gated items
- `app/Console/Commands/VerifyLicense.php` (`labeldesk:license:verify`, scheduled daily in `routes/console.php`)
- Migration `2026_11_20_100100_create_licensing_tables.php`
- Tests `tests/Licensing/*` (+ `Support/OfflineMockLicenseProvider`)
- `LICENSE_API_SPEC.md` rewritten as the full v1 contract

**Files modified (M2 + M3)**: `bootstrap/app.php`, `routes/admin.php`, `routes/console.php`,
`app/Providers/AppServiceProvider.php` (singletons, rate limiters, feature resolver),
`config/labeldesk.php` (installer section), `config/database.php` (`DB_PREFIX`), `.env.example`
(now the customer pre-install template), `phpunit.xml`, `tests/TestCase.php`,
`database/seeders/{DatabaseSeeder,LocalDevelopmentSeeder}.php`, `components/layouts/app.blade.php`.

**Packages installed**: none (uses PHP sodium + Laravel HTTP client).

**Commands executed**: enabled PHP extensions; created `labeldesk` MariaDB database; `migrate:fresh`
on MariaDB; `npm run build`; `vendor/bin/pint`; `vendor/bin/phpunit`.

**Tests**: `vendor/bin/phpunit` → **72 passed, 311 assertions** (Unit 4+, Installation 22, Licensing 30, Feature 16).
Covers installer requirements, DB validation, lock, admin creation, auth, role permissions on license
pages, license activation, invalid/expired/suspended keys, activation limits, offline grace period,
restricted mode, tampered cache, forged signatures, feature entitlements, unauthorised access.

**Browser end-to-end (MariaDB 10.11)**: fresh `.env` → wizard → requirements all green → wrong DB
name shows a clear error → real DB → application (Asia/Kolkata, INR) → administrator → expired key
rejected → mock key activated (Professional) → install → completion page → `/install` returns 404 →
sign in → License page shows masked key, plan, features, times in local timezone → **Verify now** works.
Database afterwards: 18 tables, 6 roles, 41 permissions, encrypted key, settings rows, lock file.

**Outstanding issues**
1. **External dependency:** the MusicDesk.tech licensing server does not exist. Production
   activation fails closed until it is built and its public key is set (`LICENSE_PUBLIC_KEY`).
2. User allowance (`user_limit`) from the license is exposed by `LicenseFeatureService::userLimit()`
   and will be enforced when user management is built (M4).
3. Upgrade path (`labeldesk:upgrade` command + update docs) to be added in M10; migrations are
   already forward-only and seeders idempotent.
4. `php artisan test` occasionally hangs when piped in this shell; `vendor/bin/phpunit` is reliable.

---

## Milestone 4 — Authentication & administration ✅

**Files created**
- Auth: `Http/Controllers/Auth/{PasswordResetLinkController,NewPasswordController}.php`,
  views `auth/{forgot-password,reset-password}.blade.php`
- Livewire: `Profile/Edit`, `Users/{Index,Form}`, `Roles/{Index,Form}`, `Settings/{General,Mail}`,
  `Activity/Index` + views under `resources/views/livewire/{profile,users,roles,settings,activity}`
  (settings tab bar `settings/_tabs.blade.php`, also shown on the License page)
- Services: `UserService`, `RoleService`, `SettingsApplier`, `Activity/ActivityLogger`
- `Policies/{UserPolicy,RolePolicy}`, `Exceptions/UserManagementException`
- `Support/PermissionCatalog` (permission list + labels), `Support/Format` (display dates)
- Model `ActivityLog` (MassPrunable); migration `2026_11_20_110000_create_activity_logs_table`
- Tests: `Feature/Auth/PasswordResetTest`, `Feature/Profile/ProfileTest`, `Feature/Users/UserManagementTest`,
  `Feature/Roles/RoleManagementTest`, `Feature/Settings/SettingsTest`, `Feature/Activity/ActivityLogTest`

**Files modified**: `routes/{admin,auth,console}.php`, `app/Providers/AppServiceProvider.php` (policies,
settings applier, auth event logging, `password-reset` limiter, permission-only `Gate::before`),
`LoginController` (sign-in/out logging), `LicenseMiddleware` (profile allowed in read-only mode),
`SettingsService` (cache holds stored form; encrypted values never cached decrypted),
`RolesAndPermissionsSeeder` (uses the catalogue; never re-grants removed permissions),
`config/livewire.php` (Bootstrap pagination), `partials/topbar` (My profile), `license/show`
(settings tabs), `_components.scss` (tabs), `tests/TestCase.php` (`makeUser()`).

**Database changes**: new `activity_logs` table (migrated on the dev MariaDB). New settings keys
(see DATABASE_SCHEMA.md). No changes to existing tables.

**Packages installed**: none.

**Commands executed**: `php artisan migrate`, `npm run build`, `vendor/bin/pint`, `vendor/bin/phpunit`.

**Tests**: `vendor/bin/phpunit` → **114 passed, 523 assertions** (42 new). Covers password reset
(generic responses, inactive accounts, expired/invalid tokens), profile and password change (also in
restricted mode), user CRUD, license user allowance, self-protection, last Super Admin, privilege
escalation, restore, sign-out on deactivation, read-only mode, role CRUD, `custom_roles` gating,
immutable Super Admin, seeder not undoing edits, settings validation/application, encrypted SMTP
password, test email, activity logging/redaction/filters/pruning, and permission boundaries on
every new page.

**Browser check (MariaDB, dev server)**: forgot-password request → generic confirmation and the
reset email written to the log; sign in; Users list shows "1 of 25 active users"; added an
Accountant with an emailed set-password link → toast and "2 of 25"; Roles list → created custom role
"A&R Scout" with the group select-all; Settings → General and Email; "Send test email" (log mailer);
Activity log shows every action with expandable details; My profile; Users list at phone width
(table scrolls inside its card, no page overflow).

**Outstanding issues**
1. MusicDesk.tech licensing server still pending (unchanged from M3).
2. Laravel's password-reset links expire after 60 minutes, so invitation links do too; admins can
   resend from the user list. A longer-lived invitation flow could be added later if needed.
3. With file-based sessions (no database), a deactivated user is signed out on their next request
   rather than instantly; with the default database sessions it is immediate.
4. Only English is bundled; the language setting lists installed locales.

---

## Milestone 5 — Artist management ✅

**Files created**
- Migration `2026_11_21_100000_create_artist_tables` (artists, artist_contacts, artist_links,
  artist_contracts, artist_documents)
- Models `Artist`, `ArtistContact`, `ArtistLink`, `ArtistContract`, `ArtistDocument`; factory `ArtistFactory` (tests only)
- Enums `ArtistType`, `ArtistStatus`, `ContactRole`, `LinkPlatform`, `ContractType`, `ContractStatus`,
  `DocumentCategory`, trait `Enums/Concerns/HasOptions`
- Services `ArtistService`, `ContractService`, `DocumentService`, `Media/ImageProcessor`
- `Policies/ArtistPolicy`, `Http/Controllers/Artists/ArtistFileController` (photo + document download)
- Livewire `Artists/{Index,Form,Show,Contacts,Links,Contracts,Documents}`, `Contracts/Index` and views;
  partials `artists/partials/{overview,activity}`; component `x-artist-avatar`
- `Support/Countries` (bundled ISO list), `Support/Format` additions (bytes, decimal, money, percent)
- Tests `Feature/Artists/{ArtistManagementTest,ArtistDetailsTest,ContractsAndDocumentsTest}`, `Unit/FormatTest`

**Files modified**: `routes/admin.php`, `config/navigation.php` (feature gates), `config/labeldesk.php`
(`uploads`), `config/livewire.php` (temporary upload size + throttle), `AppServiceProvider` (ArtistPolicy),
`Http/Controllers/Controller.php` (AuthorizesRequests), `ActivityLogger` (new events), dashboard
(artist/contract figures), `_components.scss` (avatar), `docs/installation.md` (backup note).

**Database changes**: five new tables (migrated on the dev MariaDB). No changes to existing tables.

**Packages installed**: none (GD, fileinfo and BCMath are already required extensions).

**Commands executed**: `php artisan migrate`, `npm run build`, `vendor/bin/pint`, `vendor/bin/phpunit`.

**Tests**: `vendor/bin/phpunit` → **138 passed, 704 assertions** (24 new). Covers permission boundaries
for every role on every artist page and action, create/update/delete/restore with logging, photo
re-encoding and private serving, validation, search/filters, plan gating (`artists`, `documents`) and
read-only mode, contacts (single primary, cross-artist access refused), links (javascript: URLs and
look-alike hosts refused), exact DECIMAL storage and formatting, effective expiry and the 60-day
filter, document upload/download/delete, a real .docx, refused PHP files including a PHP file
renamed to .pdf, path-free file names, and download through another artist's URL refused.

**Browser check (MariaDB)**: empty roster state; created "Nova Lights" (band, India) → profile page;
added a primary contact, a Spotify link and an active contract (65 %, 250,000.00 INR, ending
31 Dec 2026) — tab counts update immediately; Contracts overview warns "1 active contract ends within
60 days"; dashboard shows Artists 1 / Active contracts 1 / Ending within 60 days 1; Activity tab lists
every change; phone width has no horizontal page scroll.

**Not checked in the browser**: photo and document uploads (the built-in browser cannot attach local
files); both are covered by automated tests with real image, PDF and .docx files.

**Outstanding issues**
1. MusicDesk.tech licensing server still pending (unchanged).
2. Uploaded files are not yet part of an in-app backup — the backup tool is M9; the install guide now
   says to back up `storage/app/private`.
3. Deleting an artist does not yet check for releases (releases arrive in M6, which adds that guard).
4. On Windows, Defender may quarantine test files whose content resembles a web shell; the tests use
   harmless PHP content.

---

## Milestone 6 — Music catalog ✅

**Files created**
- Migration `2026_11_21_200000_create_catalog_tables` (genres, languages, contributors, releases,
  release_artists, tracks, track_artists, track_contributors, release_status_histories)
- Models `Genre`, `Language`, `Contributor`, `Release`, `Track`, `TrackContributor`, `ReleaseStatusHistory`;
  factory `ReleaseFactory` (tests only); seeder `CatalogReferenceSeeder` (added to `DatabaseSeeder`)
- Enums `ReleaseType`, `ReleaseStatus`, `ArtistRole`, `ContributorRole`; `Exceptions/CatalogException`
- Services `Catalog/{ReleaseService,ReleaseWorkflow,TrackService,ContributorService}`, `Media/AudioInspector`;
  `Support/Identifiers`
- Policies `ReleasePolicy`, `TrackPolicy`; `Http/Controllers/Catalog/CatalogFileController` (artwork, audio stream, master download)
- Livewire `Releases/{Index,Form,Show,Tracks}`, `Tracks/{Index,Form}`, `Contributors/Index`,
  concern `ManagesArtistCredits`; views incl. `catalog/partials/artist-credits`, `releases/partials/{overview,history}`,
  `artists/partials/releases`, component `x-release-artwork`
- Tests `Feature/Catalog/{ReleaseManagementTest,TrackManagementTest,ReleaseWorkflowTest}`, `Unit/CatalogUnitTest`,
  helper `tests/Support/CreatesCatalog` (builds real WAV/FLAC bytes)

**Files modified**: `routes/admin.php`, `config/navigation.php`, `config/labeldesk.php` (`catalog`),
`config/livewire.php` (upload size/time), `AppServiceProvider` (policies), `ActivityLogger` (events),
`Models/Artist` (releases/tracks, `isCredited()`), `ArtistService` + artist screens (delete guard, Releases
tab), `ImageProcessor` (`dimensions()`), dashboard (release figures), `_components.scss` (artwork),
`docs/installation.md` (PHP/Nginx upload limits for masters).

**Database changes**: nine new tables; reference data seeded (42 genres, 63 languages) on the dev MariaDB.

**Packages installed**: none.

**Commands executed**: `php artisan migrate`, `php artisan db:seed --class=CatalogReferenceSeeder`,
`npm run build`, `vendor/bin/pint`, `vendor/bin/phpunit`.

**Tests**: `vendor/bin/phpunit` → **163 passed, 878 assertions** (25 new). Covers ISRC/UPC rules, WAV and
FLAC header parsing and rejection of low-quality/non-audio files, page and action permissions per role,
release creation with ordered credits and artwork (square/minimum size), UPC/catalogue duplicates,
primary-artist rule, delete/restore, credited-artist protection, search/filters, plan gating, track
creation with inherited artists, audio-derived duration, ISRC uniqueness incl. compilation exception,
publishing shares ≤100%, master streaming/download permissions with range headers and logging,
reordering and deletion (file removed, positions renumbered), contributors (inline creation, IPI
validation, credited ones protected), the full workflow with locking, comment-required steps, stale
transitions refused, and approved releases not deletable.

**Browser check (MariaDB)**: New release from the artist page (artist pre-selected) → lands on the
Tracks tab; added "Glass City" with ISRC, length and a songwriter created inline; submit refused with
the exact missing items (artwork, audio); after attaching a generated 3000 px cover and a WAV through
the same services (the built-in browser cannot pick local files), checklist 9/9 → submit → request
changes with comment (shown on the release) → resubmit → approve → ready for delivery; status history
and activity complete; audio route answers range requests with 206; dashboard shows release figures.

**Outstanding issues**
1. MusicDesk.tech licensing server still pending (unchanged).
2. Masters are limited by PHP's `upload_max_filesize`/`post_max_size` (40 MB on this XAMPP); the form
   shows the effective limit. Livewire uploads are not chunked, so very large masters need hosting
   that allows large POST bodies.
3. There is no low-bitrate preview: playback streams the master and is limited to users who may
   download masters (generating previews would need ffmpeg, unavailable on shared hosting).
4. Genres/languages have no management screen yet (seeded lists); planned for Settings in M9.

---

## Milestone 7 — Distribution ✅

**Files created**
- Migration `2026_11_21_300000_create_distribution_tables` (dsps, release_deliveries, delivery_events)
- Models `Dsp`, `ReleaseDelivery`, `DeliveryEvent`; enum `DeliveryStatus`; seeder `DspSeeder` (added to `DatabaseSeeder`)
- Services `Distribution/{DistributionService,DspService}`
- Livewire `Releases/Distribution` (+ `releases/partials/delivery-panel`), `Distribution/{Index,Dsps}` and views
- Tests `Feature/Distribution/DistributionTest`

**Files modified**: `routes/admin.php`, `Models/Release` (deliveries), `ReleaseWorkflow` (reopen hidden and
refused while in stores), release page (Distribution tab, locked-state message, "Release checklist"
heading), `ActivityLogger` (events), dashboard (distribution figures).

**Database changes**: three new tables; 27 stores seeded on the dev MariaDB.

**Packages installed**: none.

**Commands executed**: `php artisan migrate`, `php artisan db:seed --class=DspSeeder`, `npm run build`,
`vendor/bin/pint`, `vendor/bin/phpunit`.

**Tests**: `vendor/bin/phpunit` → **174 passed** (11 new). Covers seeding, permission boundaries per role
(view vs manage vs stores), ready-only distribution, duplicate/inactive stores, the full lifecycle
(back-dated delivery, live with validated link, takedown with required reason, history order), refused
invalid steps and future dates, bulk delivery, removing only pending stores, reopen blocked until taken
down everywhere, store management (unique name, URL validation, no delete with deliveries,
deactivation), overview filters and "ready but not sent", and license gating.

**Browser check (MariaDB)**: on the ready release "Midnight Static": added Spotify and Apple Music →
bulk "Mark delivered (2)" with today's date → Spotify "Mark live" with a store link → table, badges and
tab count update; the release page no longer offers "Reopen" and explains why; Distribution overview
shows counts and rows; dashboard shows Live 1 / On the way 1.

**Outstanding issues**
1. MusicDesk.tech licensing server still pending (unchanged).
2. Distribution is tracked manually; there is no automated DDEX/API delivery to stores (out of scope
   for a self-hosted, shared-hosting product).
3. Activity entries written by services use the signed-in user as the actor; actions run from the
   command line (none exist yet) would be attributed to "System".

---

## Milestone 8 — Royalties ✅

**Files created**
- Migration `2026_11_21_400000_create_royalty_tables` (royalty_periods, royalty_import_profiles,
  royalty_imports, royalty_lines, royalty_earnings, artist_ledger_entries)
- Models `RoyaltyPeriod`, `RoyaltyImportProfile`, `RoyaltyImport`, `RoyaltyLine`, `RoyaltyEarning`,
  `ArtistLedgerEntry`; enums `RoyaltyImportStatus`, `LedgerEntryType`
- `Support/Decimal`; services `Royalties/{StatementReader,RoyaltyImportService,RoyaltyCalculator,
  RoyaltyPeriodService,ArtistAccountService,StatementService}`; job `ProcessRoyaltyImport`
- Livewire `Royalties/{Index,PeriodShow,Imports,Unmatched,Balances}`, `Artists/Earnings` and views;
  `Http/Controllers/Royalties/StatementController` + `royalties/statement.blade.php`
- Tests `Unit/DecimalTest`, `Feature/Royalties/{RoyaltyImportTest,RoyaltyAccountTest,RoyaltyLockTimingTest}`

**Files modified**: `routes/admin.php`, artist profile (Earnings tab), `ActivityLogger` (events), dashboard
(royalty figures), `_layout.scss` (print styles for statements).

**Database changes**: six new tables (migrated on the dev MariaDB).

**Packages installed**: none (OpenSpout was installed in M1).

**Commands executed**: `php artisan migrate`, `npm run build`, `vendor/bin/pint`, `vendor/bin/phpunit`.

**Tests**: `vendor/bin/phpunit` → **195 passed** (21 new). Covers amount parsing (thousands separators,
decimal comma, brackets, trailing minus, currency symbols, spreadsheet floats), exact rounding/percentages,
CSV import with matching by ISRC and UPC, skipped sub-total rows, 1,000 × 0.001 summing to exactly 1,
semicolon + decimal-comma + exchange rate, XLSX (0.1 + 0.2 = 0.30 exactly), duplicate files, mapping
validation, manual matching / not ours / revert, split between primary artists (featured not paid),
three-way split keeping every cent, default vs contract share, contract in force on the sale date,
overlapping periods, locking (ledger posting, frozen period, waiting for unmapped imports, not before the
period ends), advance recoupment, payment limit, reversals, statement balances and CSV/XLSX export,
permissions per role and plan gating.

**Browser check (MariaDB)**: created "2026 Q4"; a USD distributor CSV (semicolons, decimal commas, a
sub-total row) uploaded through the import service (the built-in browser cannot pick local files) → in
the UI every column was guessed, set "decimal comma", preview showed 41,2575 → 41.2575, imported via
"Process now" (dev has no queue worker): 4,262.47 INR matched at 83.50, 2,770.60 to the artist (65 %
contract), 1,491.86 label, 225.45 unmatched → "Not ours" → locked → statement (on screen, CSV, XLSX)
correct → payment of 3,000 refused (balance 2,770.60), 2,000 recorded → balance 770.60 on the artist page,
balances page and dashboard.

**Fixed during the check**: the column guesser matched "Country" as units ("count"); date comparisons
now use `whereDate` (SQLite stored dates with a time part); periods can no longer be locked before they
end (the dev period "2026 Q4" was locked before this rule existed).

**Outstanding issues**
1. MusicDesk.tech licensing server still pending (unchanged).
2. Locking is permanent by design; corrections go through adjustments. An "unlock" for administrators
   could be added later if customers need it.
3. Statement files must be UTF-8 (or plain ASCII); other encodings are not converted yet.
4. Featured artists, producers and songwriters are not paid from master revenue automatically (only
   primary artists); publishing splits are recorded on tracks but not paid out by this module.

**Next task**: Milestone 9 — reports, backups and configuration. Plan in DEVELOPMENT_PLAN.md.

---

## Milestone 9 — Reports, backups, configuration, redesign and live licensing ✅

**Licensing (live musicdesk.tech API)**
- Rewrote the client for `https://musicdesk.tech/api/license/v1` (`/activate`, `/check-in`, `/deactivate`):
  Ed25519 JWT verification with the shipped production key (kid `75338fabc5122988`), issuer and domain
  checks, server messages, `support_until`, `max_version`, `check_in_requested`, staging domains,
  automatic re-activation, offline activation (`ldreq-1` request file → `.ldlic`), new License page.
- Migration `2026_11_22_100000_add_license_server_fields_to_license_activations`.
- Verified against production: an invalid key returns `invalid_key` with LabelDesk's message.

**Redesign and themes**
- New tokens / themes / layout / components SCSS, role-aware dashboard, global search (Ctrl/Cmd+K),
  Settings → Appearance (8 colour themes, 3 sidebar styles, light/dark policy with default mode).
- Migration `2026_11_22_110000_make_user_theme_nullable` (NULL = not chosen → admin default applies).

**Reports**: `app/Reports/{Report,ReportRegistry,ReportExporter}` and 8 definitions; Livewire
`Reports/{Index,Show}`; `ReportExportController`; migration `2026_11_22_120000_create_export_logs_table`;
model `ExportLog`.

**Backups / catalogue / system**: `Services/Backup/{DatabaseDumper,BackupService}`, commands
`labeldesk:backup` and `labeldesk:upgrade`, `Services/System/UpgradeService`, Livewire `Backups/Index`,
`Settings/Catalog`, `System/Info`, `BackupDownloadController`, `Support/CatalogDefaults`,
`docs/backups.md`. Scheduler: nightly backup (02:15, when enabled) and a heartbeat for the System page.

**Files modified**: routes (`admin.php`, `console.php`), `config/{labeldesk,licensing,navigation}.php`,
release form (catalogue defaults), reference seeders (seed once), `Genre` (releases relation),
settings tabs, user factory / `UserService` / installer (nullable theme), SCSS.

**Commands executed**: `php artisan migrate` (3 migrations on MariaDB), `npm run build`, `vendor/bin/pint`,
`vendor/bin/phpunit`.

**Tests**: `vendor/bin/phpunit` → **235 passed, 1,342 assertions** (40 new). New:
`Settings/AppearanceTest` (permissions, save + render for everyone, invalid values, fixed mode locks the
switch and the endpoint, global search permissions, dashboard sections per role),
`Reports/ReportsTest` (index per role, plan-locked reports, filters, catalogue gaps, exact revenue by
store / territory / artist, balances as of a date, store summary, contracts ending, CSV export content
and export log, XLSX workbook, export permission, unknown filter values), `Settings/BackupsAndSystemTest`
(ZIP contents, `.env` and temp uploads excluded, the dump restores with identical row counts and
Unicode/quotes intact, retention, screen create/download/delete, path traversal refused, schedule +
command, permissions, catalogue defaults used by new releases, genre/language management, seeders not
re-adding deleted entries, System page and upgrade). Licensing tests were rewritten for JWT tokens.

**Browser check (MariaDB)**: dashboard, Appearance (live preview of theme and sidebar), License page,
Appearance saved (Ocean + dark sidebar applied after reload), Reports index, revenue by store (3 stores, totals 18,430 units / 4,487.92 INR / 100 %), Backups,
System and Catalogue pages render correctly. A real backup (43 tables, 565 rows, 6 files) was imported into
a scratch MariaDB database: 43 tables, 0 row-count mismatches.

**Fixed during the milestone**: the "default mode" setting never applied because `users.theme`
defaulted to `system` for everyone; deleted genres/languages/stores would have come back on upgrade.

**Outstanding issues**
1. A full online activation with a real purchased key has not been done (only the invalid-key path was
   tested against production); planned for M10.
2. Report previews show the first 250 rows (exports contain all rows). Very large royalty tables may need
   extra indexes for the store / territory groupings — part of the M10 performance review.
3. Restore is intentionally manual (documented); there is no in-app restore button.

**Next task**: Milestone 10 — commercial release (see DEVELOPMENT_PLAN.md).
