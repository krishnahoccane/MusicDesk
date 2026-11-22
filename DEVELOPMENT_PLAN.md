# LabelDesk — Development Plan

LabelDesk is a self-hosted music label management application sold and licensed through
MusicDesk.tech. Each customer runs one independent installation for one label (not multi-tenant).

Work proceeds one milestone at a time. A milestone is complete only when its migrations run,
its automated tests pass and the result has been checked in a browser.

| # | Milestone | Status |
|---|-----------|--------|
| 1 | Foundation: Laravel 12, packages, directory structure, auth foundation, layouts, theme system | **Complete** |
| 2 | Installation wizard: requirements, database, app config, admin, migrations/seeders, install lock | **Complete** |
| 3 | Licensing integration: provider interface, MusicDesk provider, API client, signed cache, grace period, mock provider | **Complete** (live musicdesk.tech API since M9) |
| 4 | Authentication & administration: password reset, users, roles/permissions, settings, activity log | **Complete** |
| 5 | Artist management: artists, contacts, links, contracts, documents | **Complete** |
| 6 | Music catalog: releases, tracks, contributors, audio/artwork uploads, approval workflow | **Complete** |
| 7 | Distribution: DSPs, release deliveries, status history, takedowns | **Complete** |
| 8 | Royalties: periods, CSV/XLSX imports, allocation, commission, payments, statements | **Complete** |
| 9 | Reporting & settings: reports, CSV/XLSX exports, backups, catalogue settings, themes, upgrade groundwork | **Complete** |
| 10 | Commercial release: full testing, security & performance review, docs, demo data, packaging | Next |

## Milestone 10 — Commercial release (next)

1. Full regression pass on MariaDB and MySQL 8, PHP 8.2–8.4; installer on a real cPanel account.
2. Security review: permissions matrix per route and Livewire action, file access, headers, rate limits,
   dependency audit (`composer audit`, `npm audit`), secrets never logged.
3. Performance: indexes for report queries on large catalogues (100k+ royalty lines), N+1 checks,
   caching of the license snapshot and settings.
4. Customer documentation (installation, upgrade, backups, licensing, roles), in-app help links.
5. Optional demo data seeder (never run in production), screenshots.
6. Release packaging: production `composer install --no-dev`, built assets, ZIP layout for cPanel,
   version bump, CHANGELOG release entry.

## Open decisions / external dependencies

- **Licensing server**: live at `https://musicdesk.tech/api/license/v1`; LabelDesk ships its Ed25519
  public key. An invalid-key activation against production returned `invalid_key` as expected; a full
  activation needs a real purchased key and is to be verified in M10.
- **Local database**: resolved — MariaDB 10.11 is used for development; the installer was verified
  end-to-end against it.
