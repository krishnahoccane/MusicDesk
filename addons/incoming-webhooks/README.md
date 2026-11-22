# Incoming webhooks (LabelDesk add-on)

Slug `incoming-webhooks`, license add-on code `incoming_webhooks`. See `../../docs/plugins.md` for
how add-ons are built, signed and installed.

Administrators with the **Manage incoming webhooks** permission (`webhooks.manage`) create
endpoints under **Administration → Webhooks**. Each endpoint gets:

- an unguessable URL `https://<labeldesk>/api/hooks/<48 characters>`;
- a signing secret (`whsec_…`) shown **once** (stored encrypted). **Rotate** creates a new one; the
  old one keeps working for 24 hours (or stops at once if chosen);
- a replay window (60–3600 s, default 300 s) and an optional IP allow-list (addresses or CIDR ranges);
- an action: **Store delivery status** or **Log only**. Enable/disable at any time (disabled → 404).

## Sending a webhook

```
POST /api/hooks/<token>
Content-Type: application/json
X-Webhook-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<unix time>.<raw body>" with the secret>
X-Webhook-Id: <unique event id>        (optional; or an "id" field in the body)
```

```php
$body = json_encode($event);
$t = time();
$signature = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
```

Responses: `202 accepted` (processed right after the response), `200 duplicate` (event id already
processed; not applied again — failed events may be resent), `400` body is not a JSON object,
`401` signature missing/invalid/outside the window, `403` IP not allowed, `404` unknown or disabled,
`413` body over 512 KB, `429` over 120 requests/minute, `503` LabelDesk is read-only (retry later).
Without an event id, an identical body counts as the same event.

## Store delivery status

```json
{"id": "evt_1001", "upc": "0123456789012", "store": "Spotify", "status": "live",
 "date": "2026-11-22", "store_release_id": "4uLU6hMC", "store_url": "https://open.spotify.com/album/4uLU6hMC",
 "reason": "optional comment"}
```

- Release: by `upc` (12/13-digit forms match), otherwise by track `isrc` (if the ISRC is on several
  releases, the one set up for that store).
- Store: by name, ignoring case, spaces and punctuation (`apple_music` = "Apple Music"), or the
  endpoint's default store.
- Status: `delivered`, `live`, `rejected`, `takedown_requested`, `taken_down` (plus aliases such as
  `published`, `removed`, `failed`). The release must already be added to that store in
  Distribution. The change runs through core `DistributionService` transitions (up to two steps,
  e.g. pending → delivered → live), recorded in the delivery history and activity log under the
  endpoint's **Acts as** user (needs "Manage deliveries and takedowns"). The same status again only
  updates the store id/link.
- Every field path can be changed per endpoint (dot notation, e.g. `data.state`).

## Delivery log

Every request to a known endpoint is logged (secret-looking headers redacted, body up to 64 KB),
with status (queued, processing, processed, failed, rejected, duplicate), HTTP answer, result and
error. Verified, complete deliveries can be **replayed** (a new log entry; the action makes no change
when data is already up to date). Entries are kept 30 days.

Processing runs after the response is sent (no cron, no queue worker); deliveries left queued
(e.g. the PHP process stopped) are picked up after later webhook requests.

## Tables

`webhook_endpoints`, `webhook_deliveries`, `webhook_events` (idempotency ledger). Data is kept when
the add-on is deactivated or deleted.

## Tests

```
php vendor/bin/phpunit addons/incoming-webhooks/tests
```
