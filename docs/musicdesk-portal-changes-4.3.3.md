# MusicDesk.tech changes needed for LabelDesk 4.3.3

LabelDesk 4.3.3 installs updates of itself and of its add-ons **over the air** (Administration →
Updates), like WordPress. For that, the license server at MusicDesk.tech (`E:\Claude Applications\MusicDesk-Frontend`)
needs two new API endpoints and signed LabelDesk release packages. Until they are live, LabelDesk shows
"Could not check for updates" on the Updates page and keeps working as before. Manual updates (upload files
by FTP) keep working and now finish the database update on their own.

The other 4.3.3 changes (add-on tables created on install, automatic database upgrade) need nothing from
the portal.

Base URL: `https://musicdesk.tech/api/license/v1` (JSON, same error format and 30/minute per-IP limit as
the license API). LabelDesk calls `/updates/check` every 12 hours per install and when an administrator
opens the Updates page or clicks *Check again*.

---

## 1. `POST /updates/check`

```json
{
  "token": "<current license token of this install>",
  "version": "4.3.3",
  "php_version": "8.3.12",
  "instance_id": "…",
  "addons": [
    { "addon": "bulk_upload", "slug": "bulk-upload", "version": "1.0.0", "token": "<add-on token>" }
  ]
}
```

`addons` lists every installed add-on; `token` is missing for an add-on that has no key yet.

Response `200`:

```json
{
  "labeldesk": {
    "version": "4.3.4",
    "released_at": "2026-12-10",
    "changelog": "### Added\n- …",
    "requires_php": "8.2",
    "size": 31457280,
    "sha256": "<sha256 of the ZIP>",
    "downloadable": true,
    "reason": null
  },
  "addons": {
    "bulk_upload": {
      "version": "1.1.0", "released_at": "…", "changelog": "…",
      "requires_labeldesk": "4.3.3", "size": 23000, "sha256": "…",
      "downloadable": false, "reason": "Your updates period for Bulk upload ended on 1 Dec 2026. Renew it to get this version."
    }
  }
}
```

Rules:

- Verify `token` like `/check-in` (signature, activation exists and is active; expired tokens accepted).
  Errors as for `/check-in`.
- `labeldesk`: the **newest published LabelDesk release** whose version is greater than `version`
  (compare with `version_compare`, not as text). `null` when there is none.
- `downloadable` is `false`, with `reason` (shown to the administrator as is, so write it for customers),
  when the release was published after the license's `support_until` (updates period ended), or the
  license may not be used (suspended, revoked, expired). The release is still returned so the admin knows it exists.
- `addons`: for each add-on in the request, the newest release of that add-on newer than its `version`,
  keyed by add-on code; leave out add-ons without a newer release. `downloadable` follows the add-on key
  (`token`): within its updates period and not suspended/revoked/expired; `false` with a reason when the
  add-on has no token or the key may not be used.
- `requires_php` / `requires_labeldesk`: from the release form (minimum PHP; for add-ons the minimum
  LabelDesk version, the `requires` of its `plugin.json`). LabelDesk refuses to install when they are not met.
- `changelog`: Markdown, at most 20,000 characters. HTML is stripped by LabelDesk.
- `size` and `sha256` of the stored package. LabelDesk compares the download with `sha256`.
- Record "update check" per activation (last time and the version it reported) so the admin license page
  can show which version each domain runs.

## 2. `POST /updates/download`

```json
{ "token": "<license token>", "product": "labeldesk", "version": "4.3.4", "instance_id": "…" }
{ "token": "<license token>", "product": "bulk_upload", "version": "1.1.0", "addon_token": "<add-on token>", "instance_id": "…" }
```

Response `200` with the ZIP as the body (`Content-Type: application/zip`, `Content-Length`,
`Content-Disposition: attachment`). Stream it; packages can be 30–60 MB.

- Same token checks as `/updates/check`, and the same `downloadable` rules: refuse with
  `403 {"error":{"code":"updates_expired","message":"…"}}` when the release is outside the updates period,
  `license_suspended` / `license_revoked` / `license_expired` as usual, `release_not_found` (404) for an
  unknown or unpublished version.
- For an add-on, `addon_token` must be a valid token of that add-on (`addon_mismatch`, 409, otherwise) on a
  domain that is live on the main license (`addon_domain_not_licensed`).
- Limit: 10 downloads per activation per hour (`rate_limited`, 429).
- Log the download (release downloads list, license activity: "Downloaded 4.3.4 over the air").

## 3. Signed LabelDesk release packages

LabelDesk installs a LabelDesk package only if it is signed with the **plugin signing key** (the same
Ed25519 key as add-on packages, kid `7491a41a211116b1`). Layout:

```
labeldesk-4.3.4/signature.json
labeldesk-4.3.4/app/…, bootstrap/…, config/…, database/…, public/…, resources/…, routes/…, vendor/…
```

`signature.json` = `{"alg":"Ed25519","kid":"7491a41a211116b1","signed":"<base64 JSON>","signature":"<base64>"}`;
the signed JSON is `{"product":"labeldesk","version":"4.3.4","files":{"<path>":"<sha256>",…}}` and lists
**every** file in the folder except `signature.json` (paths relative to `labeldesk-4.3.4/`, `/` separators,
sorted). The top folder must be `labeldesk-<version>`.

Two ways, as for add-ons:

- **Signed build:** `php scripts/package-release.php --key=<plugin-signing.key>` in LabelDesk produces a
  signed `build/labeldesk-<version>.zip`. Upload it as is.
- **Signed here:** when an unsigned LabelDesk ZIP is uploaded on Admin → Releases (product LabelDesk),
  check the top folder name, add `signature.json` with the key at `PLUGIN_SIGNING_KEY_PATH`, verify the result
  the way LabelDesk does (`App\Services\Updates\CorePackage::open` is the reference), and store that.
  Refuse ZIPs containing `.env`, `storage/logs`, backups or links.

Same release form fields as today, plus **Minimum PHP** for LabelDesk releases (shown to customers and sent
as `requires_php`).

## 4. Customer portal and admin

- Downloads page: unchanged (manual downloads still work and are the way for offline activations).
- License › Domains: show the LabelDesk version each domain last reported and when it last checked for updates.
- Admin → Releases: count of over-the-air downloads per release.
- Help text (customer portal, LabelDesk release notes): "LabelDesk can install updates itself under
  Administration → Updates. Automatic updates are off until you switch them on, separately for LabelDesk
  and each add-on. If your hosting does not let PHP write to the LabelDesk folder, upload the new files by
  FTP; the database update then runs by itself."

## 5. `docs/licensing.md`

Add both endpoints to the API table, the error codes `updates_expired` and `release_not_found`, and a
section "LabelDesk release packages" with the signature format above.

---

## What LabelDesk does with it (for reference)

- Checks every 12 hours (no cron needed) and on the Updates page; menu badge with the number of updates.
- Installs only what passes the signature and the `sha256`; refuses when `requires_php` /
  `requires_labeldesk` is not met or the license is read-only.
- LabelDesk update: database backup first (when the plan has backups), unpack into
  `storage/app/private/updates`, short maintenance page, replace files with a rollback copy (any error puts every
  file back), then the database upgrade runs on the next page view. `.env`, `storage/`, `plugins/` and an existing
  `.htaccess` are never overwritten.
- Add-on update: same as uploading a newer add-on ZIP (its tables are migrated straight away).
- Offline activations (`.ldlic`) and development test keys are not offered updates.

## Checklist

- [ ] `POST /updates/check` (LabelDesk + add-ons, `downloadable` / `reason`, `version_compare`)
- [ ] `POST /updates/download` (streamed ZIP, updates-period and add-on key checks, 10/hour)
- [ ] LabelDesk releases signed (signed build accepted, or signed on upload)
- [ ] Release form: Minimum PHP
- [ ] Error codes `updates_expired`, `release_not_found`
- [ ] Domain list shows reported version / last update check
- [ ] Download logging
- [ ] `docs/licensing.md` updated
- [ ] Publish LabelDesk 4.3.3 (signed `build/labeldesk-4.3.3.zip`) under Admin → Releases
