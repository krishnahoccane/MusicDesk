# LabelDesk — Licensing (MusicDesk.tech API v1)

LabelDesk is licensed only through MusicDesk.tech. This document describes how LabelDesk uses the
production licensing server. The server's own reference is the MusicDesk licensing documentation
(`MusicDesk-Frontend/docs/licensing.md`); where the two differ, the server is authoritative.

## Endpoints

Base URL: `https://musicdesk.tech/api/license/v1` — a constant in `App\Services\License\LicenseAuthority`, not configurable (no `.env` setting).

| Method | Path | Sent by LabelDesk | Success |
|---|---|---|---|
| POST | `/activate` | `{key, domain, version, php_version, instance_id}` | `200` token envelope |
| POST | `/check-in` | `{token, version, php_version}` | `200` token envelope |
| POST | `/deactivate` | `{token}` | `204` |
| GET | `/public-key` | — | signing key (informational; LabelDesk ships the key) |
| POST | `/report` | `{domain, reason, key, version, php_version, instance_id, message, details}` after a failed license check, at most once an hour per reason | `202 {received, action}`; `action: lock` sets the activation to `blocked` (Locked state) until a later check succeeds |

Requests are JSON over HTTPS only, with `Accept: application/json`, `User-Agent: LabelDesk/<version>`
and an `X-Request-Id`. Connection errors and 5xx responses are retried (2 retries, 750 ms apart).
The server allows 30 requests per minute per IP; `429` becomes the `rate_limited` error.

### Token envelope

```json
{
  "token": "<header>.<claims>.<signature>",
  "status": "active | expiring | expired | pending",
  "support_until": "2027-11-22",
  "max_version": "1.9.99",
  "check_in_requested": false,
  "messages": ["Shown to the administrator"]
}
```

Only the **signed token** is trusted. The unsigned fields `messages` and `check_in_requested` are
used for display and scheduling only (messages are reduced to plain strings).

### Errors

`{"error": {"code": "...", "message": "...", "field": "..."}}`. LabelDesk maps each code to its own
administrator-friendly message (`App\Exceptions\LicenseException::fromApi`):

`invalid_key`, `invalid_domain`, `domain_limit_reached`, `domain_in_use`, `license_pending`,
`license_suspended`, `license_expired`, `license_revoked`, `activation_not_found`,
`activation_deactivated`, `invalid_token`, `token_expired`, `rate_limited`, `validation_failed`.
Local codes: `not_configured`, `unreachable`, `invalid_response`, `invalid_signature`,
`domain_mismatch`, `wrong_issuer`, `provider_unavailable`.

## License keys and domains

- Keys look like `LDSK-XXXX-XXXX-XXXX-XXXX`. They are stored encrypted (APP_KEY) and only ever shown
  masked (`LDSK-••••-••••-••••-7F2K`). Keys are never written to logs.
- Domains are normalised exactly like the server: scheme, `www.`, path and port are removed and the
  host is lower-cased (`ActivationContext::normalizeDomain`).
- Staging domains (localhost, `*.test`, `*.local`, IP addresses, `staging.` / `dev.` / `test.`
  sub-domains) do not use a production domain slot. The token's `env` claim is shown on the License page.

## Tokens

Ed25519-signed JWTs (`alg: EdDSA`), valid 30 days (365 days for offline files). Claims:

| Claim | Meaning |
|---|---|
| `iss` | must equal `LICENSE_ISSUER` (default `https://musicdesk.tech`) |
| `iat`, `exp` | issued / expires (Unix time) |
| `lic`, `act` | license id, activation id |
| `dom` | normalised domain — must equal this installation's domain |
| `env` | `production` or `staging` |
| `plan` | `starter`, `business` or `enterprise` |
| `status` | `active`, `expiring`, `expired`, `pending` (all usable) |
| `support_until` | end of updates & support (Unix time) |
| `max_version` | newest LabelDesk version the license covers |
| `expires` | license expiry (Unix time, annual licenses; `null` for lifetime) — drives the read-only window and lockout |

Verification (`LicenseSignatureVerifier`): 32-byte public key present (else fail closed), header
`alg` is EdDSA, signature verifies over `header.claims`, `iss` matches, `dom` matches, not expired.
The production public key ships in `config/licensing.php`
(`EH9AZETQgvr7ssPdu71Z5eCpxvhDaj8jVwiopVPe+d8=`, kid `75338fabc5122988`) as a constant in
`LicenseAuthority`; a key rotation ships as a LabelDesk update. The token is stored in `license_cache` (signing input +
signature) and re-verified on every load, so editing the database cannot unlock anything.

### Plans and features

If a token carries a `features` claim it is used; otherwise features come from `config('licensing.plans')`:

| Plan | Features |
|---|---|
| starter | artists, releases, tracks, distribution, royalties, documents, reports, financial_reports, backups |
| business | everything |
| enterprise | everything |

`advanced_reports` (catalogue gaps, distribution by store) and `custom_roles` therefore need Business
or Enterprise.

## Hard-wired authority

`LicenseAuthority` holds the server address, issuer, public key and production provider. There is no
`LICENSE_DRIVER`, `LICENSE_API_URL`, `LICENSE_PUBLIC_KEY` or `LICENSE_ISSUER` any more; values left in
an old `.env` are ignored. The development provider (test keys `MD-LD-TEST-…`) only runs inside the
PHPUnit suite, or under `php artisan serve` / the CLI with `APP_ENV=local` — never under Apache,
LiteSpeed or PHP-FPM. Each customer's own `LDSK-…` key is entered at installation and stored encrypted.

## Expiry policy

| Period | LabelDesk |
|---|---|
| 30 days before expiry | "License expires on …" ribbon on staff dashboards |
| From the expiry date | Read-only. Everyone can still sign in and see everything. A ribbon on every page (and the sign-in page) shows the lock date; staff dashboards show a countdown |
| From expiry + 11 days, 00:00 (label time zone) | Locked: every page shows "This application is suspended", users are signed out and cannot sign in. Data is untouched |
| After renewal | The next check (hourly, on page loads, or "I have renewed — check again") unlocks it |

Example: expiry on 31 Dec 2026 → read-only, sign-in allowed until 10 Jan 2027 → locked from 11 Jan 2027.
The expiry date comes from the signed `expires` claim; when the server only reports `expired` (or refuses
with `license_expired`), LabelDesk uses the day it first learned this.

## Lifecycle in LabelDesk

1. **Activate** (installer or Settings → License) with the key → token stored.
2. **Check in** every hour (`labeldesk:license:verify`, minute 17) and opportunistically when the last
   check is older than an hour, or every 15 minutes while the server asks for `check_in_requested`.
3. If the server answers `activation_not_found`, `invalid_token`, `token_expired` (or the stored token
   fails verification) and the key is stored, LabelDesk re-activates automatically.
4. Definitive refusals (`license_*`, `activation_deactivated`) put LabelDesk in **Restricted** mode.
5. **Offline grace**: if the server cannot be reached, a token that expired less than 7 days ago
   (`LICENSE_OFFLINE_GRACE_DAYS`) keeps working. After that LabelDesk becomes read-only.
6. **Deactivate** frees the domain (`204`; an already-deactivated activation is treated as success).
7. **Transfer** to a new domain = deactivate the old domain, activate the new one with the stored key;
   if activation fails the old domain is restored locally.

Restricted / read-only mode never deletes or changes customer data — it only blocks writes.
There is no setting, environment variable or administrator option that bypasses validation.

## Offline activation

For servers without outbound internet access:

1. LabelDesk produces a request file (installer or Settings → License):
   `{"format":"ldreq-1","product":"LabelDesk","domain":"…","instance_id":"…","version":"…","php_version":"…"}`.
2. The customer uploads it in their MusicDesk account and downloads a `.ldlic` file:
   `{"format":"ldlic-1","license":"…","domain":"…","token":"<JWT valid 365 days>"}`.
3. LabelDesk verifies the token exactly like an online one (signature, issuer, domain, expiry) and stores
   it with `offline = true`. Offline activations cannot be transferred; create a new request file instead.

## Development and tests

`LICENSE_DRIVER=mock` uses `MockLicenseProvider`, which signs tokens in the same format with a local
test key. It is refused when `APP_ENV=production`. Test keys: `MD-LD-TEST-<CODE>-XXXX-XXXX` where CODE is
`ACTV` (business), `STRT` (starter), `PEND` (pending), `EXPD` / `SUSP` / `REVK` (refused),
`LIMT` (`domain_limit_reached`).
