# MusicDesk.tech changes needed for the PDL distribution add-on

**PDL distribution** is a new licensed LabelDesk add-on: it sends releases (metadata, artwork, audio)
to Phonographic Digital Limited through PDL's COSMOS ingestion API and follows their status
(see `addons/pdl-distribution/README.md`). The license server at MusicDesk.tech
(`E:\Claude Applications\MusicDesk-Frontend`) needs a new add-on product and its first release.
This document describes the changes; nothing in that repository has been edited.

| | |
|---|---|
| Add-on code | `pdl_distribution` (the `addon` value in `plugin.json`) |
| Plugin slug | `pdl-distribution` |
| Name | PDL distribution |
| First release | **1.1.0**, requires LabelDesk **4.3.6** (1.0.0 was built for 4.3.5 but is superseded; publish 1.1.0 only) |
| Package | `build/plugins/pdl-distribution-1.1.0.zip` (signed with the plugin signing key, verified; contains `CHANGELOG.md` and `CHANGELOG.txt`) |

The add-on API (`/api/license/v1/addons/activate|check-in|deactivate`) and the package checks need no
code change: they work for any add-on code.

## 1. New add-on product `pdl_distribution`

Create it in **Pricing plans › Licensed add-ons**:

- **Code:** `pdl_distribution`
- **Name:** PDL distribution
- **Description:** Send releases to Phonographic Digital (PDL) through COSMOS and follow their review and release status.
- **Icon:** `send` (or the closest icon the portal offers)
- **Prices / plans:** a business decision, not set by this change. For comparison: DDEX delivery is
  590 annual / 1490 lifetime (Business, Enterprise), Bulk upload 190 / 490.

So that fresh and demo databases have it too, add a row to the LabelDesk-plugin block of
`database/seeders/DatabaseSeeder.php` → `licenseAddons()` (after `incoming_webhooks`):

```php
['pdl_distribution', 'PDL distribution', 'Send releases to Phonographic Digital (PDL) through COSMOS and follow their review and release status.', 'send', <annual>, <lifetime>, [<plans>]],
```

and update the comment above `licenseAddons()` (it lists the plugin codes).

Update the add-on code list in `docs/licensing.md` (section "Add-ons", bullet **LabelDesk plugins**):
`bulk_upload`, `rest_api`, `incoming_webhooks`, `sso` (and `media_storage`, if not yet added) **and
`pdl_distribution`**.

## 2. Release 1.1.0

**Releases › New**, Product *PDL distribution*, version `1.1.0`, channel stable, upload
`pdl-distribution-1.1.0.zip`. It is already signed; the portal's `PluginPackager` verifies it and checks
that `plugin.json` says `addon: pdl_distribution`, `version: 1.1.0`. Set the LabelDesk requirement to
**4.3.6** (`requires_labeldesk` in `/updates/check`). Suggested notes:

- *new* — Send releases (metadata, artwork and WAV masters) to PDL through the COSMOS ingestion API, in
  the background, with retries.
- *new* — PDL review and release status per release (submitted, issue reported with PDL's comment,
  approved, delivered to stores, live), checked hourly and on demand.
- *new* — Settings → PDL distribution: encrypted credentials, test connection, PDL label, genre and
  language mapping.
- *new* — Each track's mood and CRBT cut (LabelDesk 4.3.6) are sent to PDL; draft tracks and tracks
  without a composer, lyricist or mood are caught before sending.

Publish it together with LabelDesk 4.3.6, not before (it will not install on 4.3.5). The full notes
are in the zip's `CHANGELOG.md` / `CHANGELOG.txt`.

## 3. Product page / customer information

Customers need a COSMOS account from PDL (email, password and their PDL label id) before they can use
the add-on; the add-on does not create PDL accounts. Mention this on the product page.

## 4. LabelDesk core (this repository, not the portal)

`docs/plugins.md` ("Codes in use") should list `pdl_distribution` too; it is outside the files this
change was allowed to edit.
