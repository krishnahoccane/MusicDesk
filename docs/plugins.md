# LabelDesk add-ons (plugins)

Add-ons are optional modules sold on MusicDesk.tech. Administrators download the signed `.zip`
from their MusicDesk.tech account and install it in **Administration → Add-ons**:

1. **Upload** the zip. LabelDesk checks the MusicDesk.tech signature and every file hash before
   extracting anything into `plugins/<slug>/`. Unsigned, changed or foreign packages are refused.
2. **Activate** with the add-on's own **license key** (issued by MusicDesk.tech for that add-on;
   the main LabelDesk license key is not used). LabelDesk verifies the key with MusicDesk.tech for
   this domain and stores the signed add-on token, then runs the add-on's migrations, creates its
   permissions and calls its `activate()` hook. Keys are re-checked every hour.
3. **Deactivate** at any time (data is kept). **Delete** removes the files of an inactive add-on;
   its `uninstall()` hook decides whether data is removed.

An add-on runs only while it is **active and its key is valid** (signed token for this add-on and
domain, status active, not older than its expiry + 14 days offline grace). If the key is revoked,
suspended or expires, the add-on pauses (routes answer 404, menu items disappear) and resumes when
the key is valid again. **Remove key** (inactive add-ons) frees the domain on the key.
Uploading a newer version of an installed add-on updates it in place (older versions are refused).

## Source layout

Add-on sources live in this repository under `addons/<slug>/` (they are not part of the LabelDesk
release package):

```
addons/bulk-upload/
  plugin.json
  src/BulkUploadServiceProvider.php      PSR-4: namespace in plugin.json → src/
  src/…                                  Livewire components, services, controllers, models
  routes/web.php                         signed-in pages
  routes/guest.php                       optional: public pages with a session (SSO)
  routes/api.php                         optional: stateless /api routes (REST API, webhooks)
  resources/views/…                      Blade views, used as "<slug>::name"
  database/migrations/…                  run on activation and on update while active
  lang/…                                 optional JSON translations
  tests/…                                not packaged
  README.md
```

### plugin.json

```json
{
    "slug": "bulk-upload",
    "name": "Bulk upload",
    "version": "1.0.0",
    "addon": "bulk_upload",
    "description": "Import artists and releases with tracks from Excel or CSV templates.",
    "author": "MusicDesk",
    "requires": "1.0.0",
    "namespace": "LabelDesk\\Addons\\BulkUpload\\",
    "provider": "LabelDesk\\Addons\\BulkUpload\\BulkUploadServiceProvider"
}
```

- `slug`: folder name, lowercase letters, digits and dashes.
- `addon`: the license add-on code. Codes in use: `bulk_upload`, `rest_api`, `incoming_webhooks`, `sso`.
- `namespace` must be `LabelDesk\Addons\<Name>\`; the provider must be inside it.
- `requires`: lowest LabelDesk version.

### The service provider

Extend `App\Plugins\AddonServiceProvider`:

```php
namespace LabelDesk\Addons\BulkUpload;

use App\Plugins\AddonServiceProvider;

class BulkUploadServiceProvider extends AddonServiceProvider
{
    public function boot(): void
    {
        $this->views();                       // "bulk-upload::…"
        $this->migrations();
        $this->webRoutes('routes/web.php');   // web + auth + active + license middleware
        $this->permissions('bulk_upload', 'Bulk upload', ['catalog.bulk_upload' => 'Bulk upload artists and releases']);
        $this->navigation('Catalog', ['label' => 'Bulk upload', 'icon' => 'upload', 'route' => 'bulk-upload.index',
            'active' => 'bulk-upload.*', 'permission' => 'catalog.bulk_upload'], after: 'tracks.index');
        $this->settingsTab('bulk-upload.settings', 'Bulk upload', 'upload');
    }

    public function activate(): void {}    // after migrations, on activation
    public function deactivate(): void {}
    public function uninstall(): void {}   // before files are deleted; keep data unless asked
}
```

Helpers:

| Helper | What it does |
|---|---|
| `webRoutes($file)` | Signed-in pages with the same middleware as core pages (`web`, `auth`, `auth.session`, `active`, `license`) |
| `guestRoutes($file)` | `web` middleware only (session + CSRF): sign-in pages, OAuth/SAML callbacks |
| `apiRoutes($file, 'api')` | Under `/api`, `api` middleware: no session, no CSRF. Authenticate every request yourself (tokens, HMAC signatures) |
| `views()`, `migrations()`, `translations()` | Load views as `<slug>::…`, migrations, JSON translations |
| `navigation($section, $item, after:)` | Sidebar item; sections: `Catalog`, `Business`, `Administration` (a new label creates a section). Item keys as in `config/navigation.php` (`permission`, `gate`, `feature`, `active`) |
| `permissions($key, $label, [...])` | Permission group in the roles editor; permissions are created on activation. Super Admin has all |
| `settingsTab($route, $label, $icon, $can)` | Tab in Settings (before "License") |
| `loginButton($view)` | View rendered under the sign-in form (SSO buttons). Receives `$preview` (true in the appearance preview: render, but do not link) |
| `dashboardWidget($view)` | Reserved for dashboard cards |

Every add-on route also gets the `plugin:<slug>` middleware, so it answers 404 while the add-on
is not running.

### Rules

- Use core services, models and Blade components (`x-page-header`, `x-icon`, `x-badge`, …) and the
  MusicDesk design system; Livewire 3 components can be routed directly by class.
- Respect the license: Livewire actions that write call `$this->ensureLicenseAllowsWrites()`
  (`App\Livewire\Concerns\EnforcesLicense`), and check permissions/policies on every action.
- No Composer packages at install time. If a library is unavoidable, bundle it under
  `vendor/` with its own `vendor/autoload.php` (loaded automatically) and prefix its namespace.
- Never log secrets; encrypt stored credentials (`encrypted` / `encrypted:array` casts).
- Tables are prefixed with the add-on name (e.g. `api_tokens`, `webhook_endpoints`, `sso_providers`).
- Don't edit core files from an add-on. If a hook is missing, add it to `App\Plugins\PluginRegistry`
  / `AddonServiceProvider` in core.
- Tests live in `addons/<slug>/tests/` (not packaged). Run them with
  `php vendor/bin/phpunit addons/<slug>/tests` — they install the add-on from source with
  `Tests\Concerns\InstallsAddons`.

## Building a signed zip

```
php scripts/build-plugin.php addons/bulk-upload --key="<path to plugin-signing.key>"
```

Writes `build/plugins/<slug>-<version>.zip` and verifies it like LabelDesk will. The signing key is
the Ed25519 secret key whose public half is `LicenseAuthority::PLUGIN_PUBLIC_KEY`
(kid `7491a41a211116b1`); it stays with MusicDesk.tech and is never committed or shipped.
Upload the zip to MusicDesk.tech so customers with the add-on can download it.

## License server (add-on keys)

Each add-on is its own license with its own key (`LDAD-XXXX-XXXX-XXXX-XXXX`), attached to a main
license. It has no domain slots: it activates only on domains that are live on its main license.
Plans can include add-ons (keys issued automatically). Full server-side description:
MusicDesk-Frontend `docs/licensing.md` ("Add-ons", "Add-on API", "Add-on packages").
LabelDesk calls, under `https://musicdesk.tech/api/license/v1`:

| Method | Path | Body | Success |
|---|---|---|---|
| POST | `/addons/activate` | `{key, addon, domain, version, php_version, instance_id}` | `200 {token, status, expires_at, support_until, addon}` |
| POST | `/addons/check-in` | `{token, version}` (hourly) | `200 {token, status, expires_at, support_until, addon}` |
| POST | `/addons/deactivate` | `{token}` | `204` |

The token is an EdDSA JWT signed with the license server key (same key and `kid` as license tokens)
with claims `iss`, `iat`, `exp`, `typ: "addon"`, `addon`, `dom`, `act`, `lic`, `status`
(`active` / `expiring`), `expires` (Unix time or `null` for lifetime). Unlike LabelDesk itself, an
add-on whose key has expired stops. Errors use the license API
error format and codes (`invalid_key`, `license_revoked`, `license_suspended`, `license_expired`,
`activation_not_found`, `activation_deactivated`, plus `addon_mismatch` (409: key for another add-on,
or a main `LDSK-` key) and `addon_domain_not_licensed` (409: the domain is not live on the main
license). These stop the add-on at the next hourly check; network errors do not.

Development keys `MD-LD-TEST-ADDN-XXXX-XXXX` (and `…-REVK-…`, `…-SUSP-…`, `…-EXPD-…` for refusals)
work with the development license provider in tests and `php artisan serve`.
