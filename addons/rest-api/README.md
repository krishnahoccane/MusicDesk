# REST API add-on (`rest-api`, add-on code `rest_api`)

Versioned JSON API under `/api/v1` for artists, releases, tracks and contributors, authenticated
with personal access tokens. No Composer packages (no Sanctum).

## For administrators

1. Install and activate the add-on in **Administration → Add-ons** with its license key.
2. Give the roles that may use the API the **REST API → Create and use personal API tokens**
   permission (`api.tokens`). Super Admins have it already. **View and revoke every user's API
   tokens** (`api.tokens.manage`) lets someone audit and revoke everyone's tokens.
3. Users create tokens in **Administration → API tokens**. The token is shown once; only its
   SHA-256 hash is stored. **API reference** on that page documents every endpoint.

## How requests are checked

Every `/api/v1` request goes through, in order:

| Step | Refused with |
|---|---|
| Add-on running (`plugin:rest-api`) | 404 |
| `Authorization: Bearer ldk_…` token exists, not revoked, not expired | 401 `unauthenticated` |
| Owner exists and is active | 401 `unauthenticated` |
| Owner still has `api.tokens` | 403 `api_access_revoked` |
| License not locked | 403 `license_locked` |
| Per-token rate limit (`rest-api.rate_limit`/min) | 429 `rate_limited` (+ `Retry-After`) |
| Token ability `<resource>:read` (GET) / `<resource>:write` (others) | 403 `insufficient_scope` |
| Plan feature (`artists`, `releases`, `tracks`) | 403 `feature_unavailable` |
| Writes while the license is read-only | 403 `license_read_only` |
| Owner's permissions and core policies (ArtistPolicy, ReleasePolicy, TrackPolicy, contributors.*) | 403 `forbidden` |
| Artist-account scope (`App\Support\ArtistAccess`, model global scopes) | 404 `not_found` |
| Validation (same rules as the app forms) | 422 `validation_failed` |
| Catalog rules from core services (locked release, primary artist, shares, credited artist …) | 422 `rule_violation` |

The owner is signed in for the request only (`Auth::setUser`, no session), so core services,
policies, the artist-account scope and the activity log behave exactly as in the app. Writes go
through `ArtistService`, `ReleaseService`, `ReleaseWorkflow`, `TrackService` and
`ContributorService`. Repeated failed authentication from one IP is throttled
(`rest-api.auth_failures_per_minute`).

Errors always use `{"error": {"code", "message", "details"?}}`; lists return `data`, `meta`
(`current_page`, `per_page`, `total`, `last_page`) and `links` (`next`, `prev`).

## Files

```
plugin.json
config/rest-api.php                      rate limits, page sizes, expiry choices
database/migrations/…api_tokens…         api_tokens (hash, prefix, abilities, expiry, last use, revocation)
routes/api.php                           /api/v1 endpoints
routes/web.php                           /api-tokens, /api-tokens/reference
src/RestApiServiceProvider.php
src/Http/Middleware/                     AuthenticateToken, ThrottleToken, AuthorizeResource
src/Http/Controllers/                    Artist, Release, Track, Contributor, Meta
src/Livewire/                            Tokens (management page), Reference (API reference page)
src/Services/ApiTokenService.php         create / find / revoke / touch
src/Support/                             Abilities, ApiException, ErrorRenderer, Presenter
resources/views/                         tokens, reference
tests/                                   not packaged
```

Tests: `php vendor/bin/phpunit addons/rest-api/tests`.
Build: `php scripts/build-plugin.php addons/rest-api --key=<path to plugin-signing.key>`.

## Open points

- **Uploads** (artist photo, release artwork, master audio) are not in v1.
- **Settings UI**: rate limit, page size and expiry choices are in `config/rest-api.php` only.
- **Release updates by artist accounts**: when `artists` is not sent, the current credits are kept
  (all of them, including co-credited artists the account cannot see). If such a hidden artist
  exists, `ReleaseService::syncArtists()` refuses the update for artist accounts with
  `rule_violation`. The app's form instead drops the hidden credits on save.
- **Core bug (strict mode only)**: `TrackService::ensureIsrcAvailable()` loads the conflicting
  release with `select('id', 'title', 'type', 'deleted_at')` and then calls `displayTitle()`, which
  reads `version`. With `Model::shouldBeStrict()` (all non-production environments) a duplicate ISRC
  throws `MissingAttributeException` (500) instead of the "already used" message, in the app and
  the API. Fix in core: add `'version'` to that select.
