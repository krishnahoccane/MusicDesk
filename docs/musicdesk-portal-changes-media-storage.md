# MusicDesk.tech changes needed for the Media storage add-on (LabelDesk 4.3.4)

In LabelDesk 4.3.4, cloud **Media storage** (Settings → Media storage: Google Drive, OneDrive, OneDrive
for Business, Dropbox, Zoho WorkDrive, FTP) leaves core and becomes a licensed add-on, like Bulk upload
did. The license server at MusicDesk.tech (`E:\Claude Applications\MusicDesk-Frontend`) needs a new
add-on product, its first release, and keys for the customers who already use the feature.

| | |
|---|---|
| Add-on code | `media_storage` (the `addon` value in `plugin.json`) |
| Plugin slug | `media-storage` |
| Name | Media storage |
| First release | 1.0.0, requires LabelDesk 4.3.4 |
| Package | `build/plugins/media-storage-1.0.0.zip` (already signed with the plugin signing key) |

The add-on API (`/api/license/v1/addons/activate|check-in|deactivate`) and the package checks need no
code change: they work for any add-on code.

---

## 1. New add-on product `media_storage`

Create it in **Pricing plans › Licensed add-ons**:

- **Code:** `media_storage`
- **Name:** Media storage
- **Description:** Copy master audio and original artwork to Google Drive, OneDrive, Dropbox, Zoho WorkDrive or FTP.
- **Icon:** `cloud-upload` (or the closest icon the portal offers)
- **Prices / included in plans:** a business decision, not set by this change. Bulk upload, the other
  feature that moved out of core, is 190 annual / 490 lifetime and included in Business and Enterprise.

So that fresh and demo databases have it too, add a row to the LabelDesk-plugin block of
`database/seeders/DatabaseSeeder.php` → `licenseAddons()` (next to `bulk_upload`, `rest_api`,
`incoming_webhooks`), with the prices and plans decided above, and update the comment above that method.

Update the add-on code list in `docs/licensing.md` (section "Add-ons", bullet **LabelDesk plugins**):
`bulk_upload`, `rest_api`, `incoming_webhooks`, `sso` **and `media_storage`**.

## 2. Release 1.0.0

**Releases › New**, Product *Media storage*, version `1.0.0`, channel stable, upload
`media-storage-1.0.0.zip`. It is already signed; the portal's `PluginPackager` verifies it and checks
that `plugin.json` says `addon: media_storage`, `version: 1.0.0`. Set the LabelDesk requirement to
**4.3.4** (`requires_labeldesk` in `/updates/check`). Suggested notes:

- *new* — Media storage is now an add-on (it was part of LabelDesk before 4.3.4). Destinations, uploads
  and settings of earlier versions are kept.

Publish it together with LabelDesk 4.3.4, not before (it will not install on 4.3.3).

## 3. Keys for customers who already use media storage

Until 4.3.3, media storage was part of every LabelDesk license. After updating to 4.3.4 without the
add-on, an installation:

- stops uploading new masters and artwork to its destinations, and
- **cannot play or download masters whose server copy was removed** (the "remove from this server after
  N days" option): LabelDesk answers 404 and says the Media storage add-on must be active.

Their destinations, OAuth tokens, upload records and settings stay in the database; installing and
activating the add-on with a key brings everything back without reconnecting a drive. The OAuth redirect
URIs they registered with Google, Microsoft, Dropbox and Zoho (`<app URL>/settings/media-storage/callback/<driver>`)
are unchanged.

The portal cannot tell which installations configured media storage (LabelDesk does not report it). So:

- **Recommended:** issue a free `media_storage` key to every license that existed before the 4.3.4
  release date (admin license page › add-ons › issue by hand, free of charge), so nobody loses access to
  files that now live only in their cloud storage. A one-off artisan command or the add-on form's
  *Also issue keys now to existing licenses* option (if the add-on is included in their plans) can do
  this in bulk.
- At least: announce it in the 4.3.4 release notes ("Media storage is now an add-on. If you use it,
  get your free key from your account before updating"), and issue keys on request.

Customers must install the add-on (Administration → Add-ons, or the add-on downloads page) and enter the
key. The add-on is not installed automatically by the 4.3.4 update.

## 4. LabelDesk 4.3.4 release notes (suggested line)

> Media storage (Google Drive, OneDrive, Dropbox, Zoho WorkDrive, FTP) is now the **Media storage**
> add-on. Existing destinations and files are kept; install the add-on with your key to keep uploading
> and to play files that were removed from the server.
