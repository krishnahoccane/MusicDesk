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
| 10 | Commercial release: security & performance review, artist accounts, bulk upload, sales imports, docs, packaging | **Complete** (see open items) |

## Before the first public release (open items)

1. Activate with a real purchased key against musicdesk.tech (online and offline file) — only the
   invalid-key path has been verified against production.
2. Install the packaged ZIP on a real cPanel account (PHP 8.2 and 8.4) and on MySQL 8 (development uses
   MariaDB 10.11).
3. Load test reports and imports with a large catalogue (100k+ royalty lines).
4. Set the version to 1.0.0, date the CHANGELOG entry, build with `scripts/package-release.php`.

Possible later work: bulk audio/artwork upload (ZIP matched by ISRC/UPC), artist self-service invitations,
in-app restore, statement e-mails to artist accounts.

## Open decisions / external dependencies

- **Licensing server**: live at `https://musicdesk.tech/api/license/v1`; LabelDesk ships its Ed25519
  public key. An invalid-key activation against production returned `invalid_key` as expected; a full
  activation needs a real purchased key and is to be verified in M10.
- **Local database**: resolved — MariaDB 10.11 is used for development; the installer was verified
  end-to-end against it.
