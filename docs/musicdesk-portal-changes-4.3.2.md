# MusicDesk.tech changes needed for LabelDesk 4.3.2

LabelDesk 4.3.2 needs the following on the MusicDesk.tech license server and customer portal
(`E:\Claude Applications\MusicDesk-Frontend`). Everything else in 4.3.2 (sign-up, labels, backups,
layout fixes) is inside LabelDesk and needs nothing from the portal.

Base URL for all API calls: `https://musicdesk.tech/api/license/v1` (JSON, same error format, same
30/minute per-IP rate limit as the existing license API).

---

## 1. Deactivation needs a code emailed to the license owner

In **Settings → License → Deactivate…**, LabelDesk now asks the administrator for their password,
then asks MusicDesk.tech to email a one-time code to the **email that bought / holds the license**
(the license's organisation owner on MusicDesk.tech, not anyone typed in LabelDesk). The installation
is only deactivated after the code is entered. Offline activations are unchanged (password only).

### 1a. New endpoint: request a code

`POST /deactivate/code`

```json
{ "token": "<current license token of this installation>" }
```

Response `200`:

```json
{ "sent": true, "sent_to": "k•••@a•••.com", "expires_in": 600 }
```

- Verify the token like `/check-in` (signature, activation exists and is active; expired tokens accepted).
- Send the code to the license owner's email (the organisation's account owner / billing email on the
  license). `sent_to` is a **masked** version of that address; never return it in full.
- Code: 6 digits, random (`random_int`), stored **hashed**, valid for `expires_in` seconds (600),
  single use, tied to that activation. A new request replaces the previous code.
- Limits: at most 3 code requests per activation per 15 minutes (`rate_limited`, 429) and 5 wrong
  attempts per code (then the code is void; the next attempt gets `invalid_code`).
- Audit: record "deactivation code sent" on the license (portal: license activity, admin license page)
  with the domain and IP.
- Errors: same codes as `/check-in` (`invalid_token`, `activation_not_found`, `activation_deactivated`, …).

Email (plain and HTML), subject **"Your LabelDesk deactivation code"**:

> Someone with administrator access to **{domain}** asked to deactivate LabelDesk on that domain
> (license {number}). Your code is **{code}**. It expires in 10 minutes.
> If this wasn't you, ignore this email: nothing changes without the code. You can also see and manage
> your domains at {portal link}.

### 1b. Changed endpoint: deactivate

`POST /deactivate`

```json
{ "token": "…", "code": "123456", "reason": "manual" }
```

| `reason` | Sent by LabelDesk when | Code |
|---|---|---|
| `manual` (default when missing) | the administrator clicks Deactivate | **required** |
| `transfer` | "Move to a new domain": the old domain is released and the same installation (`instance_id`) activates the new domain right after | not sent |
| `key_change` | "Change license key": the old key is released after the same installation activated a **different** key | not sent |

- `manual` without a valid code → `422 {"error": {"code": "code_required" | "invalid_code", …}}`.
  LabelDesk shows its own text for both codes.
- `transfer` / `key_change` without a code: accept only when the same installation (`instance_id` of the
  token's activation) has made a successful `/activate` within the last 10 minutes (for `transfer`: on
  another domain of the same license; for `key_change`: with another license key). Otherwise treat as
  `manual` (code required). Record the reason in the license activity.
- Deactivations from the portal (customer or admin) are unchanged and need no code.
- Response unchanged: `204`.

### 1c. Docs

Add both to `docs/licensing.md` (API table, error codes `code_required`, `invalid_code`), and the
"deactivation code sent" event to the activity list.

---

## 2. Change license key (LabelDesk 4.3.1, confirm server behaviour)

LabelDesk can replace its license key on the License page and on the suspension page (the latter asks
for a LabelDesk administrator's email and password). The flow is:

1. `POST /activate` with the **new** key for the same domain and `instance_id`.
2. Only if that succeeds: `POST /deactivate` with the **old** token and `reason: "key_change"`.

Server checks:

- Activating a second key on a domain that is still active on the first key must succeed (it is the
  same installation, `instance_id` matches) — do **not** answer `domain_in_use` in that case.
- After the `key_change` deactivation the old key's domain slot is free again.
- Add-on keys (`LDAD-…`) are tied to the main license: if the new main key belongs to another license,
  the add-ons of the old license stop at their next hourly check-in with `addon_domain_not_licensed`
  (existing behaviour). Mention this in the customer-facing help for changing keys.

---

## 3. Releases

- Publish **LabelDesk 4.3.2** under Admin → Releases (product: LabelDesk) with the 4.3.2 section of
  `CHANGELOG.md` as the changelog. Minimum PHP 8.2 (8.3 recommended), MySQL 8 / MariaDB 10.6+.
- 4.3.2 contains a database update (labels, sign-up columns); customers run **Administration → System →
  Upgrade database** or `php artisan labeldesk:upgrade` after uploading the files.
- Make sure `max_version` for licenses whose updates period has lapsed is compared as a version, not
  as text (4.3.10 is newer than 4.3.9; use PHP `version_compare`).

---

## 4. Customer portal

- License page / Licenses › Domains: show the "deactivation code sent" and "deactivated (manual /
  transfer / key change)" events with domain, IP and time.
- Help text on the license page: "Deactivating LabelDesk on a domain needs a code we email to this
  account's owner. If you did not ask for it, someone with administrator access to that site did —
  check who has access."

---

## Checklist

- [ ] `POST /deactivate/code` (hashed 6-digit code, 10 min, single use, 3 requests / 15 min, 5 attempts)
- [ ] Deactivation code email template
- [ ] `POST /deactivate` accepts `code` and `reason`; `manual` requires the code
- [ ] `transfer` / `key_change` accepted without a code only right after an activation from the same `instance_id`
- [ ] Error codes `code_required`, `invalid_code`
- [ ] Second key on the same domain from the same `instance_id` is not `domain_in_use`
- [ ] Activity events in the portal and admin
- [ ] `docs/licensing.md` updated
- [ ] LabelDesk 4.3.2 release published
