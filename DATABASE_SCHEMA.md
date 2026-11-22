# LabelDesk — Database Schema

Conventions: `BIGINT UNSIGNED` auto-increment primary keys, foreign key constraints, `created_at` /
`updated_at` on every business table, soft deletes on business records, `DECIMAL(18,6)` for money,
UTC timestamps. Tables are created only by Laravel migrations.

Legend: ✅ implemented · ⏳ planned (milestone)

## System

### users ✅ (M1)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| name | varchar(255) | |
| email | varchar(255) | unique |
| email_verified_at | timestamp null | |
| password | varchar(255) | bcrypt hash |
| theme | varchar(10) null | `light` \| `dark` \| `system`; NULL = not chosen, the Appearance default applies (M9) |
| is_active | boolean | default true, indexed; not mass-assignable |
| last_login_at | timestamp null | |
| last_login_ip | varchar(45) null | IPv4/IPv6 |
| remember_token | varchar(100) null | |
| created_at, updated_at, deleted_at | timestamps | soft deletes |

### Framework tables ✅ (M1)
`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

### Access control ✅ (M1, spatie/laravel-permission)
`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.
Seeded by `RolesAndPermissionsSeeder` (idempotent; never removes permissions, and on upgrades only
grants permissions that are new in that version, so administrator edits to built-in roles survive).
The permission catalogue and labels live in `App\Support\PermissionCatalog`:
6 roles (Super Admin, Label Manager, Artist Manager, Distribution Manager, Accountant, Viewer) and
41 permissions named `module.action` (e.g. `artists.create`, `royalties.import`, `license.manage`).

### settings ✅ (M2)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| group | varchar(50) | default `general` |
| key | varchar(100) | unique with `group` |
| value | text null | serialized per `type` |
| type | varchar(20) | `string`, `int`, `bool`, `json`, `decimal`, `encrypted` |
| created_at, updated_at | timestamps | |

Installer writes: `general.app_name`, `general.timezone`, `general.currency`, `general.locale`,
`system.installation_id`, `system.installed_version`.

Settings screens (M4) add: `general.date_format`, `general.time_format`, `activity.retention_days`,
`mail.mailer`, `mail.host`, `mail.port`, `mail.scheme`, `mail.username`, `mail.password` (type
`encrypted`), `mail.from_address`, `mail.from_name`. `SettingsApplier` applies them over `.env` at boot.
The settings cache stores values in their stored form, so encrypted values stay encrypted in the cache.

### activity_logs ✅ (M4)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| user_id | FK users null, null on delete | who did it (null = system/guest) |
| event | varchar(64), indexed | e.g. `auth.login`, `user.created`, `role.updated`, `settings.updated` |
| subject_type, subject_id | nullable morph, indexed together | the record acted on |
| description | varchar(255) | human-readable summary |
| properties | json null | changes as `[old, new]`; sensitive keys (password, secret, token, license_key, api_key) are stripped |
| ip_address | varchar(45) null | |
| user_agent | varchar(255) null | |
| created_at | timestamp, indexed | append-only (no `updated_at`) |

Pruned nightly by `model:prune` after `activity.retention_days` (default 365; 0 = keep forever).

## Licensing ✅ (M3)

### license_activations
| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| provider | varchar(30) | `musicdesk`, `mock`, (`envato` future) |
| sales_channel | varchar(30) | `musicdesk_direct`, `envato`, `authorized_reseller` |
| license_key | text | **encrypted** (APP_KEY) |
| license_key_hash | char(64) | SHA-256, indexed; raw key never queried |
| activation_id | varchar(100) null | issued by MusicDesk.tech |
| activation_secret | text null | **encrypted**; HMAC key for signed requests |
| domain, application_url | varchar(255) | bound installation |
| installation_fingerprint | char(64) | HMAC(installation_id, domain) |
| status | varchar(20) | local: `active`, `deactivated` (indexed) |
| license_status | varchar(20) null | remote: `active`, `expired`, `suspended`, … |
| plan, license_type | varchar null | from the signed token |
| features | json null | from the signed token |
| activation_limit, activations_used, user_limit | int unsigned null | |
| expires_at, support_until, updates_until | timestamp null | |
| activated_at, last_verified_at, last_attempt_at, deactivated_at | timestamp null | |
| last_error_code | varchar(50) null | |
| created_at, updated_at | timestamps | |


Added in M9 (`2026_11_22_100000`): `environment` (production/staging from the token), `max_version`,
`check_in_requested` (bool), `messages` (json, server notices), `offline` (bool, activated with a `.ldlic` file).

### license_cache
Signed tokens for offline verification (re-verified with the Ed25519 public key on every read).
`license_activation_id` FK (cascade), `payload` longtext, `signature` varchar(255), `issued_at`,
`valid_until` (indexed), timestamps. Rows older than 30 days are pruned.

### license_events
Audit trail: `license_activation_id` FK null, `user_id` FK null, `event` (activated, verified,
verify_failed, deactivated, transferred), `result` (success/failure), `code`, `message` (≤500),
`ip_address`, `created_at`. Never contains the license key.

## Artists ✅ (M5)

### artists
| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| name | varchar(150), indexed | credited name |
| legal_name | varchar(200) null | for contracts/statements |
| type | varchar(20) | `solo`, `band`, `duo`, `producer`, `dj`, `composer`, `ensemble` |
| status | varchar(20), indexed | `active`, `prospect`, `inactive` |
| country | char(2) null | ISO 3166-1 alpha-2 |
| primary_genre | varchar(80) null | free text until the M6 genre list |
| email, phone | varchar null | |
| bio, notes | text null | notes are internal |
| photo_path | varchar null | private disk, re-encoded JPEG (≤1000 px, metadata stripped) |
| created_by | FK users null, null on delete | |
| created_at, updated_at, deleted_at | timestamps | soft deletes |

### artist_contacts
`artist_id` FK cascade, `name` (150), `role` (`artist`, `manager`, `booking_agent`, `lawyer`, `publicist`,
`accountant`, `other`), `email` null, `phone` null, `notes` (500) null, `is_primary` bool (one per artist,
enforced in `ArtistService`), timestamps.

### artist_links
`artist_id` FK cascade, `platform` (spotify, apple_music, youtube, soundcloud, bandcamp, deezer, instagram,
tiktok, facebook, x, website, other), `url` (500; http/https only, host checked per platform), timestamps.
Index (`artist_id`, `platform`).

### artist_contracts
| Column | Type | Notes |
|--------|------|-------|
| artist_id | FK artists, cascade | |
| title | varchar(200) | |
| type | varchar(30) | recording, distribution, licensing, publishing, management, other |
| status | varchar(20), indexed | draft, active, expired, terminated (an active contract past `end_date` is shown as expired) |
| start_date, end_date | date null | end_date indexed; start required when active |
| artist_share | DECIMAL(7,4) null | artist royalty share in percent (0–100); label share = 100 − artist_share |
| advance_amount | DECIMAL(18,6) null | never stored as float |
| currency | char(3) null | required with an advance |
| territory | varchar(150) null | |
| notes | text null | |
| created_by | FK users null | |
| timestamps, deleted_at | | soft deletes |

### artist_documents
`artist_id` FK cascade, `artist_contract_id` FK null (null on delete), `title` (200), `category` (contract,
identity, tax, press, artwork, correspondence, other), `disk` (default `local` = storage/app/private),
`path` (random UUID file name under `artists/{id}/documents/`), `original_name` (sanitised), `mime_type`
(sniffed from content), `size` bytes, `uploaded_by` FK users null, timestamps. Deleting a row deletes the file.

## Catalog ✅ (M6)

### genres / languages (reference data)
`genres`: `name` (80, unique), `is_active`. `languages`: `code` (ISO 639-1, or `zxx` = no lyrics; unique),
`name`. Seeded by `CatalogReferenceSeeder` (42 genres, 63 languages; idempotent, never renames or removes).

### contributors
`name` (150, indexed), `legal_name` null, `ipi_number` (9–11 digits) null, `pro` (performing rights
organisation) null, `email` null, `notes` (500) null, `created_by` FK users null, timestamps.
Cannot be deleted while credited on a track.

### releases
| Column | Type | Notes |
|--------|------|-------|
| title | varchar(200), indexed | |
| version | varchar(100) null | e.g. "Deluxe Edition" |
| type | varchar(20) | single, ep, album, compilation |
| status | varchar(20), indexed | draft, submitted, in_review, approved, rejected, ready |
| upc | varchar(14) null, unique | GTIN-12/13, checksum validated, stored without spaces |
| catalog_number | varchar(50) null, unique | |
| label_name, c_line, p_line | varchar null | © and ℗ lines |
| release_date | date null, indexed | |
| original_release_date | date null | must not be after release_date |
| genre_id, language_id | FK null, null on delete | |
| explicit | bool | derived: true when any track is explicit |
| artwork_path, artwork_thumb_path | varchar null | private disk; re-encoded JPEG (≤4000 px) + 600 px thumbnail |
| notes | text null | |
| created_by | FK users null | |
| submitted_at, approved_at | timestamp null | |
| timestamps, deleted_at | | soft deletes (only while draft/changes requested) |

### release_artists / track_artists
`release_id`/`track_id` FK cascade, `artist_id` FK **restrict** (credited artists cannot be removed),
`role` (primary, featuring, remixer), `position`. Unique (owner, artist). At least one primary artist.

### tracks
| Column | Type | Notes |
|--------|------|-------|
| release_id | FK cascade | |
| disc_number, position | smallint | positions kept consecutive per disc |
| title, version | varchar | |
| isrc | char(12) null, indexed | validated `CCXXXYYNNNNN`; unique except re-use on compilations |
| duration_seconds | int null | read from the audio header when audio exists |
| explicit | bool | |
| language_id | FK null | lyrics language |
| audio_path | varchar null | private disk, random name; never serialised |
| audio_original_name, audio_mime, audio_size | | |
| audio_sample_rate, audio_bit_depth, audio_channels | | from the WAV/FLAC header (≥ 44.1 kHz / 16-bit, mono/stereo) |
| created_by | FK users null | |

### track_contributors
`track_id` FK cascade, `contributor_id` FK restrict, `role` (composer, lyricist, songwriter, arranger,
producer, mixing_engineer, mastering_engineer, performer, publisher), `share` DECIMAL(7,4) null — only for
publishing roles (composer, lyricist, songwriter, publisher); shares per role may not exceed 100.
Unique (track, contributor, role).

### release_status_histories
`release_id` FK cascade, `from_status` null, `to_status`, `user_id` FK null, `comment` text null (required
for "request changes" and "reopen"), `created_at`. Append-only.

## Distribution ✅ (M7)

### dsps
`name` (100, unique), `website` null, `lead_time_days` null (deliver this many days before release
date), `delivery_notes` (500) null, `is_active` (indexed), timestamps. Seeded by `DspSeeder` (27 stores;
idempotent, never changes existing rows). Cannot be deleted once it has deliveries — deactivate instead.

### release_deliveries
| Column | Type | Notes |
|--------|------|-------|
| release_id | FK cascade | |
| dsp_id | FK restrict | unique with release_id |
| status | varchar(30), indexed | pending, delivered, live, rejected, takedown_requested, taken_down |
| delivered_on, live_on, taken_down_on | date null | set by the matching step (may be back-dated, never future) |
| store_id | varchar(100) null | the store's own ID for the release |
| store_url | varchar(500) null | http/https only |
| notes | varchar(1000) null | |
| created_by | FK users null | |

A release can only be added to stores when it is **ready for delivery**; it cannot be reopened while any
delivery is pending, delivered, live or takedown requested. Pending deliveries can be removed.

### delivery_events
`release_delivery_id` FK cascade, `from_status` null, `to_status`, `user_id` FK null, `comment` (required
for rejections and takedown requests), `created_at`. Append-only. (Takedowns are tracked as statuses with
their reason in this history rather than a separate table.)

## Royalties ✅ (M8)

All money is DECIMAL and calculated with BCMath strings (`App\Support\Decimal`); sums are done in PHP,
not with SQL `SUM`, so results are identical on MySQL, MariaDB and SQLite.

### royalty_periods
`name` (100, unique), `starts_on`, `ends_on` (periods may not overlap), `status` (`open` / `locked`),
`locked_at`, `locked_by` FK users, `notes`. A period can be locked only after its end date; locking
cannot be undone.

### royalty_import_profiles
Saved column mappings: `name` (unique), `column_map` (field → header name, JSON), `decimal_separator`,
`currency`.

### royalty_imports
`royalty_period_id` FK restrict, `royalty_import_profile_id` FK null, `source_name`, `original_name`,
`file_path` (private disk, hidden), `file_hash` (SHA-256; the same file cannot be imported twice unless
reverted), `status` (uploaded, queued, processing, completed, failed, reverted), `currency`,
`exchange_rate` DECIMAL(18,8) to the label currency, `column_map` (field → column index), `decimal_separator`,
`total_rows`, `matched_rows`, `skipped_rows`, `net_total` / `net_total_base` DECIMAL(18,6), `error_message`,
`created_by`, `processed_at`.

### royalty_lines
One per sales row: `royalty_import_id` FK cascade, `royalty_period_id` FK cascade, `row_number`,
`track_id` / `release_id` FK null, `isrc`, `upc`, `store`, `territory`, `sale_date` (period end when the
file has none), `units` (signed), `net_amount` (statement currency) and `net_amount_base` (label currency)
DECIMAL(18,6), `match_status` (matched, unmatched, ignored). Index (period, match_status).

### royalty_earnings
Rebuilt on every recalculation of an open period: `royalty_period_id`, `artist_id`, `track_id` /
`release_id`, `artist_contract_id` null (null = default share), `units`, `revenue` (artist's portion of net
revenue), `artist_share` DECIMAL(7,4), `artist_amount`, `label_amount` DECIMAL(18,6);
`revenue = artist_amount + label_amount` exactly.

### artist_ledger_entries
The artist account: `artist_id`, `royalty_period_id` null, `type` (earnings, advance, expense, payment,
adjustment), `amount` DECIMAL(18,6) signed (+ owed to artist, − paid or recoupable), `entry_date`,
`description`, `method`, `reference` (`reversal:{id}` for reversals), `created_by`. Never edited or
deleted; mistakes are reversed with an opposite adjustment. Earnings entries are created when a period is
locked (dated the period end). Payments cannot exceed the balance.

## Reports ✅ (M9)

### export_logs
| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| user_id | FK users null, null on delete | who exported |
| report | varchar(60), index | report key, e.g. `revenue` |
| format | varchar(10) | `csv` or `xlsx` |
| filters | json null | filters used (empty values removed) |
| row_count | int unsigned | rows written |
| ip_address | varchar(45) null | |
| created_at | timestamp, index | |

Backups are files (`storage/app/private/backups`), not tables. Backup, catalogue and upgrade settings live
in `settings`: `backups.schedule`, `backups.retention`, `catalog.label_name`, `catalog.c_line_format`,
`catalog.p_line_format`, `catalog.default_language`, `appearance.theme|modes|default_mode|sidebar`,
`system.installed_version`, `system.upgraded_at`.

## Artist accounts and bulk upload ✅ (M10)

### artist_user
| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| artist_id | FK artists, cascade | |
| user_id | FK users, cascade | artist account |
| created_at / updated_at | timestamps | |

Unique (artist_id, user_id).

### bulk_uploads
Owned by the **Bulk upload** add-on (created by its migration when missing; installations that had bulk
upload in core keep the table created by `2026_11_23_100000`).

| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| user_id | FK users null, null on delete | |
| type | varchar(20), index | `artists` or `releases` |
| original_name | varchar(255) | uploaded file name |
| total_rows / created_count / skipped_count | int unsigned | |
| errors | json null | row number → problems (max 500 rows) |
| created_at / updated_at | timestamps | |

Also in M10: indexes `royalty_lines (royalty_period_id, store)` and `(royalty_period_id, territory)`;
settings `bulk_upload.staff`, `bulk_upload.artists`, `bulk_upload.defaults_granted` (add-on).

### Planned
| Table | Milestone |
|-------|-----------|
| expenses, custom_fields | later (not required for 1.0) |

Each table will be documented here in full as its migration is written.
