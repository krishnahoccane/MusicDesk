# Changelog

All notable changes to LabelDesk are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses Semantic Versioning.

## [Unreleased]

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
