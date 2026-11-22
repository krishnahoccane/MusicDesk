# LabelDesk — Architecture

## Overview

LabelDesk is a modular monolithic Laravel 12 application. One installation serves one music label,
with its own database, users, catalog, royalty data, files and license activation.

```
Browser ──► Blade + Livewire 3 (server-rendered UI, Bootstrap 5 + custom design tokens)
              │
              ▼
        Routes (web / auth / admin / installer)
              │  middleware: auth, active, permission, license (M3), installed (M2)
              ▼
        Controllers & Livewire components  ──►  Form Requests (validation)
              │                                Policies (authorization)
              ▼
        Services (business logic, transactions)
              │
              ▼
        Eloquent models  ──►  MySQL 8 / MariaDB 10.6+  (SQLite for tests)

        Licensing service layer ──HTTPS──► MusicDesk.tech licensing API
```

## Technology

| Concern | Choice |
|---------|--------|
| Framework | Laravel 12, PHP ≥ 8.2 (8.3 preferred) |
| UI | Blade, Livewire 3, Bootstrap 5.3 (compiled from SCSS), vanilla JS |
| Icons | Lucide, rendered **server-side** by `<x-icon>` from `resources/icons/lucide` |
| Charts | ApexCharts, lazy-loaded only on pages that declare a `data-chart` element |
| Font | Inter (bundled via `@fontsource-variable/inter`, no external font requests) |
| Auth/roles | Laravel session auth + `spatie/laravel-permission` |
| Spreadsheets | `openspout/openspout` (CSV/XLSX import and export, streaming, low memory) |
| Queue/cache/session | Database drivers (no Redis required) |

Node.js is a **build-time** dependency only. The distributed package contains `vendor/` and
`public/build/`, so customers need neither Composer nor Node.js.

## Directory layout

```
app/
  Enums/                    Backed enums (ThemePreference, statuses, roles …)
  Http/Controllers/{Admin,Auth,Installer,License}
  Http/Middleware/          SecurityHeaders, EnsureUserIsActive, (Installed, License — later)
  Http/Requests/            Form Requests per module
  Livewire/{Dashboard,Artists,Releases,Tracks,Distribution,Royalties,Reports,Settings}
  Models/
  Policies/
  Services/{Artist,Release,Track,Distribution,Royalty,License,Installation,Backup,Navigation}
  View/Components/          Class-based Blade components (Icon)
config/labeldesk.php        Product and display configuration
config/navigation.php       Sidebar definition (route + permission + feature per item)
resources/scss/             Design system (tokens, layout, components, auth)
resources/js/modules/       theme, sidebar, toasts, charts
resources/views/components/ Reusable Blade components and layouts
routes/{web,auth,admin}.php (installer.php in Milestone 2)
scripts/sync-icons.mjs      Copies Lucide SVG paths into resources/icons/lucide
```

## Conventions

- **Business logic lives in services**, never in Blade or controllers. Multi-step and financial
  operations run inside `DB::transaction()`.
- **Authorization is enforced server-side** in routes (`permission:` middleware), policies and
  Livewire actions. The sidebar hides items the user can't access, but that is cosmetic only.
- **Navigation is config-driven.** `NavigationBuilder` omits items whose route is not registered,
  so unfinished modules never appear as dead links.
- **Super Admin** passes every gate via `Gate::before`.
- **Strict models** (`Model::shouldBeStrict()`) outside production to catch lazy loading,
  silently discarded attributes and missing attributes.
- **Money** uses `DECIMAL(18,6)` columns and string/BCMath arithmetic — never floats.
- **Time**: stored in UTC (`config/app.php` timezone). `labeldesk.display_timezone` controls display.

## Theme system

- Design tokens are CSS custom properties in `resources/scss/_tokens.scss`, defined for
  `[data-bs-theme='light']` and `[data-bs-theme='dark']`. Bootstrap's runtime variables are mapped
  onto the tokens, so one stylesheet serves both modes.
- **Colour themes** (`_themes.scss`): aurora, ocean, emerald, sunset, rose, midnight, graphite, sand.
  Each sets accent colours (as RGB triplets, so soft tints and gradients derive from them) for light and
  dark; midnight, graphite and sand also tint the neutrals. Selected with `<html data-ld-theme>`.
- **Sidebar style**: `data-ld-sidebar` = light (clean), tinted or dark.
- **Mode policy** (Settings → Appearance, `App\Support\Appearance`): `appearance.modes` = both / light /
  dark. With a fixed mode the `<html>` gets `data-theme-locked`, the top-bar switch is hidden and
  `PATCH /preferences/theme` returns 403. `appearance.default_mode` applies to users who never chose
  (`users.theme` is NULL).
- The user's preference (`light`, `dark`, `system`) is stored in `users.theme`.
  `partials/theme-script` resolves it in `<head>` before first paint (no flash of wrong theme).
- Switching mode updates the DOM immediately and `PATCH /preferences/theme` persists it.
  Guests (login page) fall back to `localStorage`.
- Charts (`resources/js/modules/charts.js`) read token colours and re-render on the
  `labeldesk:theme-changed` event.
- **Dashboard** (`Livewire\Dashboard\Overview`) is role-aware: KPIs, charts, upcoming releases and the
  activity timeline only appear for areas the user may see; "Needs attention" collects work items;
  administrators also see license, user allowance and system health.
- **Global search** (`Livewire\GlobalSearch`, Ctrl/Cmd+K or "/"): artists, releases, tracks,
  contributors and users, each filtered by permission and license feature.

### MusicDesk design layer

- `resources/scss/_musicdesk.scss` ports the MusicDesk UI/UX guide (Tailwind there) onto LabelDesk's
  Bootstrap/SCSS: ink tokens, glass `.card`, gradient `.btn-primary`, `.btn-ghost`, tinted `.ld-badge--*`,
  mono table headers, `.eyebrow`, `.display`, `.serif-accent`, `.text-gradient`, `.glass`, `.grid-bg`,
  `.glow-border`, `.eq-bar`. `_auth.scss` holds the five sign-in designs.
- White-label: `Appearance::themeCss()` prints validated `#rrggbb` brand colours (and their RGB
  triplets) after the stylesheet; `Support\Branding` stores logo / dark logo / favicon / sign-in image on
  the public disk (`branding/`), served by `BrandingController` (strict name pattern, `nosniff`, sandbox CSP).
- `<html data-ld-font>` switches between Inter Tight and the system font; `data-ld-area` (label / artist)
  picks the page glow colour.

## Security baseline (Milestone 1)

- CSRF on all forms and the theme endpoint; Blade escaping by default; toasts use `textContent`.
- Login throttled per email+IP (`labeldesk.login.*`) plus a route-level throttle.
- Session regenerated on login and invalidated on logout. Deactivated users are signed out by
  `EnsureUserIsActive` on their next request.
- `SecurityHeaders` middleware: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy`, `COOP`, and HSTS over HTTPS.
- Icon names are validated against `^[a-z0-9-]+$` before any file is read.
- `is_active` and login metadata are excluded from mass assignment.

## Installation (Milestone 2)

- `bootstrap/app.php` calls `EnvironmentBootstrapper::ensure()` before Laravel boots: on a fresh
  upload it copies `.env.example` (file-based drivers) to `.env` and generates `APP_KEY`.
- `EnsureApplicationInstalled` (first in the web group) redirects everything to `/install` until
  `storage/app/private/installed.lock` exists; `RedirectIfInstalled` 404s the installer afterwards.
- `InstallationService` order: progress=installing → migrate (forward-only) → seed (idempotent) →
  admin/settings/license → write `.env` → write lock. A failure before the lock leaves a resumable state.

## Licensing (Milestone 3)

```
Business code ──► LicenseService ──► LicenseValidationService ──► LicenseCacheService ──► LicenseSignatureVerifier
                    │                       │ refresh()                                   (Ed25519 public key)
                    └► LicenseFeatureService └► LicenseManager ─► LicenseProviderInterface
                                                                   ├─ MusicDeskLicenseProvider ─► LicenseApiClient ─HTTPS─► musicdesk.tech
                                                                   ├─ EnvatoLicenseProvider (future, fails closed)
                                                                   └─ MockLicenseProvider (local/testing only)
```

- Only signed tokens are trusted; local rows are re-verified on every read, so editing the database
  cannot upgrade a plan. Business modules call `LicenseService` / `canUse()` only.
- `LicenseMiddleware` (on the authenticated group) implements the read-only policy; `feature:x`
  gates plan features; Livewire actions use the `EnforcesLicense` trait.
- Production server: `https://musicdesk.tech/api/license/v1` (`/activate`, `/check-in`, `/deactivate`).
  Tokens are Ed25519 JWTs (30 days online, 365 days for offline `.ldlic` files) verified with the
  shipped public key. Offline activation: `OfflineRequestFile` (ldreq-1) → MusicDesk portal → `.ldlic`.
- See `LICENSE_API_SPEC.md` for the wire contract and offline/grace rules.

## Administration (Milestone 4)

- **Users** — `UserService` holds the rules that apply whoever acts (including Super Admins): license
  user allowance (`LicenseFeatureService::userLimit()`, active users only), at least one active Super
  Admin, no self-deactivation/deletion/re-role, and no privilege escalation (a role may only be
  assigned if its permissions are a subset of the actor's). `UserPolicy` decides who can manage whom.
- **Roles** — `RoleService` + `RolePolicy`: Super Admin immutable, built-in roles keep their names,
  custom roles need the `custom_roles` feature, nobody grants permissions they lack.
  `App\Support\PermissionCatalog` is the single list of permissions and their labels.
- **Gate::before** short-circuits only permission names (`users.manage`), not policy abilities.
- **Settings** — `SettingsService` (typed key/value, cached in stored form) and `SettingsApplier`
  (applies general/mail settings over config at boot, only once installed).
- **Activity log** — `ActivityLogger::log(event, description, subject, properties)`; sensitive keys are
  stripped and logging failures never break the action. Auth events (`Failed`, `Lockout`) are wired in
  `AppServiceProvider`; sign-in/out are logged by `LoginController`.
- **Sessions** — `auth.session` (AuthenticateSession) is on the authenticated group; password changes
  rotate the remember token and delete database sessions, keeping only the current one.
- Livewire full-page components set the title and breadcrumbs with `->title()->layoutData()`.

## Artists (Milestone 5)

- `ArtistService` (artists, photos, contacts, links), `ContractService`, `DocumentService`; `ArtistPolicy`
  (`manageContracts` / `manageDocuments` abilities). The profile page (`Artists\Show`) hosts one child
  Livewire component per tab; children emit `artist-updated` so tab counts refresh.
- **Private files**: everything goes to the `local` disk (`storage/app/private`), under random names.
  `ArtistFileController` serves photos and documents after auth, permission, license-feature and
  ownership checks, with `nosniff` and private cache headers. Nothing is web-accessible directly.
- **Uploads**: validated by extension and size, then the type is sniffed from the file's first bytes
  (`DocumentService::detectMime`) — a renamed script is refused. Photos are re-encoded by
  `Media\ImageProcessor` (GD) with a pixel-count guard, which strips metadata and appended payloads.
- **Money and percentages** are DECIMAL strings, formatted by `Support\Format::decimal/money/percent`
  with BCMath (no floats, no intl dependency).
- Plan gating: `feature:artists` on artist routes, `feature:documents` on contracts and downloads;
  Livewire actions call `ensureFeature()` and `ensureLicenseAllowsWrites()`.

## Catalog (Milestone 6)

- Services in `App\Services\Catalog`: `ReleaseService` (metadata, artist credits, artwork),
  `TrackService` (tracks, credits, audio, ordering, ISRC rules), `ContributorService`, and
  `ReleaseWorkflow` — a table of actions (from → to, permission, comment required, checklist
  required). Transitions lock the release row, re-check its status (stale pages cannot act), write
  `release_status_histories` and the activity log.
- **Locking**: `ReleaseStatus::isEditable()` (draft / changes requested) gates every content change in
  the services and policies (`ReleasePolicy::update`, `TrackPolicy::create/update/delete`).
- **Identifiers**: `Support\Identifiers` (ISRC format, GTIN check digit).
- **Audio**: `Media\AudioInspector` parses WAV (RIFF chunks) and FLAC (STREAMINFO) headers in PHP — no
  ffmpeg needed on shared hosting. Masters stream through `CatalogFileController` as
  `BinaryFileResponse` (HTTP range support) only for `tracks.download_master`; there is no separate
  low-quality preview because generating one would need external tools.
- `Livewire\Concerns\ManagesArtistCredits` + `catalog/partials/artist-credits` provide the ordered
  artist editor shared by release and track forms.
- Feature gates: `feature:releases` (releases, contributors), `feature:tracks` (tracks, audio).

## Distribution (Milestone 7)

- Manual tracking: LabelDesk records what staff did at each store; it does not push files to DSPs.
- `Distribution\DistributionService` holds the delivery state machine (action → from/to, comment
  required, date field), adds/removes stores, bulk transitions and `hasActiveDeliveries()`, which
  `ReleaseWorkflow` uses to hide and refuse "reopen" while a release is in stores. Transitions lock the
  delivery row and write `delivery_events` + the activity log (subject = the release).
- `Distribution\DspService` manages the store list (delete only without deliveries).
- UI: `Releases\Distribution` (tab child component with an inline action panel),
  `Distribution\Index` (overview), `Distribution\Dsps` (stores). Gate: `feature:distribution`;
  permissions `distribution.view`, `distribution.manage`, `dsps.manage`.

## Royalties (Milestone 8)

```
upload (RoyaltyImportService::upload) → map columns (configure) → ProcessRoyaltyImport job
   → StatementReader (OpenSpout, streamed) → royalty_lines (matched by ISRC / UPC)
   → RoyaltyCalculator::recalculate → royalty_earnings
lock (RoyaltyPeriodService::lock) → artist_ledger_entries (earnings) → ArtistAccountService (payments …)
StatementService → statement page / CSV / XLSX
```

- **Money**: `Support\Decimal` (BCMath strings, half-up rounding, parsing of statement amounts in any
  common notation). Sums are done in PHP so results never depend on the database's SUM implementation.
- **Allocation rules** (documented in `RoyaltyCalculator`): primary artists of the track (else of the
  release) share revenue equally, the last one absorbing the rounding remainder; the artist share comes
  from the contract in force on the sale date, otherwise `royalties.default_artist_share`; label amount =
  revenue − artist amount, so totals always reconcile to the cent.
- **Queue**: imports run on the database queue (scheduler runs `queue:work --stop-when-empty` every
  minute); "Process now" runs a queued import inline for hosts without cron.
- **Integrity**: locked periods refuse imports, reverts, matching and recalculation; ledger entries are
  append-only (reversals are opposite adjustments); payments lock the artist row and cannot exceed the
  balance.
- Gate: `feature:royalties`; permissions `royalties.view/import/manage`, `payments.view/manage`.

## Reports (Milestone 9)

```
Livewire Reports\Index ─► ReportRegistry ─► Report definitions (app/Reports/Definitions)
Livewire Reports\Show  ─► Report::rows(filters) (generator) ─► table preview (250 rows) + totals
ReportExportController ─► ReportExporter ─► OpenSpout CSV / XLSX streamed to the browser + export_logs
```

- A report declares its filters (select / date; unknown values fall back to defaults), columns
  (text, int, money, percent, date, badge), totals, license features and extra permissions.
- Availability = `reports.view` + the report's permissions; plan-locked reports are listed but locked.
  Export needs `reports.export` and is throttled.
- Money columns are BCMath strings. Aggregates use SQL `SUM` on `DECIMAL` columns (exact on MySQL /
  MariaDB) and are normalised to 6 decimals; exports write numbers as numeric cells.

## Backups, catalogue settings and system (Milestone 9)

- `Services\Backup\DatabaseDumper` writes an SQL dump in pure PHP (cursor + batched INSERTs; MySQL /
  MariaDB and SQLite), so no `mysqldump` or `exec()` is needed on shared hosting. Session, cache, job and
  password-reset rows are not dumped.
- `BackupService` zips `database.sql`, `manifest.json` and uploaded files (private and public storage)
  into `storage/app/private/backups` (also protected by `.htaccess`), applies retention and logs
  activity. `.env` is never included. Restore is manual by design (`docs/backups.md`).
- `labeldesk:backup [--database-only] [--scheduled]`; nightly at 02:15 when enabled.
- Settings → Catalogue (`Livewire\Settings\Catalog`, `Support\CatalogDefaults`): label name, © / ℗ line
  formats with `{year}` / `{label}`, default language, genres (hide or delete when unused) and languages.
  Reference seeders seed these lists only on an empty table, so upgrades never re-add deleted entries.
- `Services\System\UpgradeService` / `labeldesk:upgrade`: forward-only migrations, idempotent seeders,
  cache clear, `system.installed_version`. Administration → System shows versions, server, database,
  storage, scheduler heartbeat, queue and the upgrade button when new files are uploaded.

## Artist accounts (Milestone 10)

- Users with the system role **Artist** are artist accounts, linked to profiles through `artist_user`.
  The role's permissions are fixed (`RolesAndPermissionsSeeder::ARTIST_PERMISSIONS`, synced on every
  seed; the role cannot be edited).
- **Data boundary**: `App\Support\ArtistAccess::ids()` returns the linked artist ids for an artist
  account (null for everyone else). Models using `Models\Concerns\ScopedToArtistAccount` add a global
  scope `artist-account`: Artist (id), Release (has a linked artist), Track / ReleaseDelivery (release
  visible), ArtistContract / Document / Contact / Link / LedgerEntry, RoyaltyEarning (artist_id),
  TrackContributor (track visible), Contributor (created by the user or credited on a visible track).
  Route model binding uses the same scopes, so other records return 404.
- Catalogue-wide integrity checks (UPC, ISRC) explicitly use `withoutGlobalScope('artist-account')` and
  word their errors without naming other artists' releases. `ReleaseService` / `TrackService::syncArtists`
  call `ArtistAccess::ensureOwned()` so an artist account can only credit its own profiles.
- Staff, console commands and queued jobs are never scoped (no artist-account user).

## Bulk upload (Milestone 10)

```
Livewire BulkUpload\Index ─► BulkUploadService (access, templates, log)
   ├─ BulkSheet (StatementReader: CSV/XLSX → rows keyed by template column; key or label headings)
   ├─ ArtistBulkImporter  (validate → ArtistService::create + links)
   └─ ReleaseBulkImporter (group rows into releases → ReleaseService::create + TrackService::save)
```

- "Check file" analyses without writing; "Import" re-reads and re-validates the uploaded file on the
  server, so the browser's preview is never trusted. Each release is created in its own transaction.
- Access: administrators always; staff with `catalog.bulk_upload` when `bulk_upload.staff` is on;
  artist accounts (releases only, own artists) when `bulk_upload.artists` is on. Gate `bulk-upload`.
- `bulk_uploads` keeps the history (counts and row problems).

## Sales report import (Milestone 10)

`Livewire\Reports\SalesImports` (route `reports.import`, `royalties.import`) chooses or creates an open
period and embeds the existing `Royalties\Imports` component, so mapping, matching, queueing and
reverting are shared with Royalties.

