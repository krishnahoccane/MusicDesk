# PDL distribution (LabelDesk add-on)

Sends releases (metadata, artwork and audio) to **Phonographic Digital Limited (PDL)** through the
**COSMOS data ingestion API (v2.0)** and follows their review and release status.

| | |
|---|---|
| Slug / add-on code | `pdl-distribution` / `pdl_distribution` |
| Version | 1.0.0, requires LabelDesk 4.3.5 |
| Namespace | `LabelDesk\Addons\PdlDistribution\` |
| Pages | Business → **PDL distribution** (`/distribution/pdl`), one page per release (`/distribution/pdl/releases/{id}`), Settings → **PDL distribution** (`/settings/pdl-distribution`) |
| Permissions | `pdl.view` (see deliveries), `pdl.deliver` (send, retry, check status), `pdl.manage` (settings) |
| Background work | task `pdl`, every minute (no cron or queue worker needed); `php artisan labeldesk:pdl:run [--seconds=240]` does the same from cron |
| Tables | `pdl_deliveries`, `pdl_delivery_events` (created only if missing; never dropped, `uninstall()` keeps them) |

Sources: PDL's *COSMOS data ingestion API Guide* (the `.md` export and the Google Doc, whose outline is
identical; the Doc's body could not be read as text, so the `.md` was used) and PDL's Postman collection.

## Setup

Settings → PDL distribution (permission `pdl.manage`):

1. **API base URL** (default `http://pdl.gaonaweb.com`, the host in the guide; `/v2.0` is added), **account
   email** and **password**. The password is stored encrypted (core settings, type `encrypted`), is never
   sent back to the browser, logged or written to the activity log; leave the field empty to keep it.
   **Test connection** signs in for real with the values in the form (nothing is saved).
2. **PDL label id** (24-character COSMOS id, used when PDL verifies each upload) and **PDL label name**
   (sent as `label`; empty = each release's own label name).
3. **Genres**: map every LabelDesk genre to one of PDL's approved *genre › sub-genre* pairs (the guide's
   list of 24/08/2026). Releases with an unmapped genre cannot be sent.
4. **Languages**: languages used in the catalog; same-name languages (Hindi → hindi) match automatically,
   `zxx` is sent as instrumental. Override any of them.
5. **Release defaults**: where to release (`platforms_to_release`), *allow any go-live date*, default mood,
   album type (Album; Film is blocked, see below), album category, content type per release type
   (Single → Single, EP → Album, Album → Album, Compilation → Compilation), publisher fallback, CRBT start
   second, and the date format (DD-MM-YYYY as in the Postman collection, or YYYY-MM-DD as in the guide).
6. Switch **Enabled** on.

## Sending a release

Business → PDL distribution lists approved / ready releases not sent yet and everything sent, with its
PDL status. A release's page shows what is missing (problems block sending; warnings do not), the send
form (release on, mood, allow any go-live date) and the history. **Send to PDL** only queues: the
background task then runs, step by step, each step saved before the next:

1. Sign in (`POST /user/system/signin`), token cached encrypted (50 minutes), renewed once on 401/403.
2. `POST /album/add/meta` with the full album (sizes and MD5 of the artwork and every master).
3. One streamed `PUT` per file to the presigned S3 URLs (no multipart, `Content-Length` set, no COSMOS
   token sent to S3). S3's ETag is compared with the file's MD5.
4. `GET /album/verify/meta?token=…&label_id=…` → COSMOS album id (looked up by ISRC through
   `/album/page` when the answer has none).
5. `POST /album/pdl/submit` with `platforms_to_release` and `allow_any_go_live_date`.

Only a successful submit answer marks a delivery **Submitted**; *approved*, *delivered to stores*, *live*,
*store delivery error* and *taken down* come only from PDL's album status. Uploads already done are not
repeated when a delivery resumes.

**Errors.** Network errors, HTTP 429/5xx, COSMOS "System Error" (code 5) and S3 5xx/checksum mismatches
are retried after 1, 5, 15, 60 and 180 minutes. Everything else (e.g. *Duplicate ISRC*, an expired upload
link, a refused submit) stops the delivery as **Needs attention** with PDL's own message; **Retry**
resumes at the step that failed, **Send to PDL** again starts a new ingestion when PDL has no album yet.
Nothing is deleted on failure.

**Status.** Albums are checked hourly (live ones daily) and on **Check status**: `submitted`, `verified`
and `reportissue_flag` give *Submitted / Resubmitted, PDL to review / Issue reported / Approved*; the
per-platform `release_status` gives *Delivered to stores / Live / Store delivery error / Taken down*.
Issues reported by PDL (`/reportissue/getbyid`) are shown on the release page.

**Updates.** While PDL holds the album but has not accepted it (not submitted yet, or an issue was
reported), **Send update to PDL** re-reads the album (`/album/page`), applies the current LabelDesk
metadata to it (songs matched by ISRC, COSMOS ids kept), uploads new artwork through
`/album/media/signedurl?type=Artwork` when it changed, sends `PUT /album/media/update` and submits again.
After submission the API accepts no changes (the guide: "not yet available"); the page says so.

Activity log: `pdl.queued`, `pdl.retried`, `pdl.submitted`, `pdl.failed`, `pdl.status_changed`,
`settings.updated`.

## Field mapping

| COSMOS | LabelDesk |
|---|---|
| `name`, `album_category` | release title; release version (else the default category) |
| `album_type`, `album_subtype` / `data.content_type` | Settings; content type per release type |
| `label`, `publisher` | Settings label name or release label; first *publisher* credit, else Settings, else label |
| `upc_id`, `catalog_number`, `c_line`, `p_line` | release UPC (empty: PDL creates one), catalogue number, C-line, P-line |
| `data.go_live_date`, `data.original_release_date_of_music` | release date, original release date (else release date) |
| `data.genre`, `data.sub_genre` | release genre through the genre map |
| `data.language`, `is_instrumental` | track language (else release language) through the language map; instrumental / `zxx` → `Yes` |
| `data.parental_advisory` | track or release explicit → `Explicit`, else `Not Explicit` |
| `data.mood` | chosen when sending |
| `data.crbt_cut_name`, `data.time_for_crbt_cut`, `data.track_duration` | track title (64 chars), Settings CRBT start, track duration (H:MM:SS) |
| `album_main/featured/remixer_artist` | release artists by role (primary, featuring, remixer) |
| `track_main/featured/remixer_artist` | track artists by role (release artists when a track has none) |
| `singers` | main and featured track artists (none for instrumentals) |
| `composers`, `lyricists`, `producers` | credits: composer + songwriter, lyricist + songwriter, producer |
| artist `spotify_id`, `apple_id`, `facebook_artist_page_url`, `insta_artist_page_url` | artist links (ids read from Spotify / Apple Music profile URLs) |
| `ipi_number`, `is_iprs_member` | contributor IPI; PRO "IPRS" |
| `inlay`, `songs[].media` | original artwork if JPG/PNG (else the app's JPG copy), checked 1500–6000 px square, RGB; WAV masters (MP3 with a warning), checked against PDL's sample-rate / bit-depth list, named `<ISRC>.wav` |

The payload of a full release is pinned in `tests/fixtures/full-release-payload.json`.

### Not sent (LabelDesk has no data for them)

Star cast, directors, film banner (so album type **Film** is blocked), artist `meta_id` and COSMOS artist
ids, `other_artists` with roles (needs role ids from `/artists/role/list`), lyrics files, Dolby Atmos,
videos, YouTube Content ID options (`/album/platform/details/update`), go-live time.

## Open questions for PDL

1. **Production host and HTTPS.** The guide and Postman use `http://pdl.gaonaweb.com` (plain HTTP: the
   password, token and files travel unencrypted). What are the staging and production base URLs, and is
   HTTPS available?
2. **Sign-in answer.** The guide does not show the `/user/system/signin` response or the token lifetime.
   The add-on reads `data.token` (and a few usual alternatives) and caches it 50 minutes. Which field and
   how long is it valid?
3. **Date format.** The guide says `YYYY-MM-DD`; the Postman request sends `DD-MM-YYYY` (and
   `date_of_expiry` as `2/1/23`). Which does `/album/add/meta` expect? (A setting, default DD-MM-YYYY.)
4. **Verify answer.** "The COSMOS album id is returned": in which field? (The add-on accepts `album_id`,
   `id`, `album.id`, … and otherwise looks the album up by ISRC.)
5. **Album listing shape.** Which field holds the albums in `/album/page` (and `/album/list`), and are
   `submitted`, `verified`, `reportissue_flag` and `releases[]` always included?
6. **Field names.** The Postman request uses `"parental_advisory (EXPLICIT ETC)"` inside `data`; the guide
   says `parental_advisory`. Remixers are `track_remixer_artist` / `album_remixer_artist` in add/meta but
   `track_remixer` / `album_remixer` in the update and listing. Album category: `album_category` (guide)
   or `category` (update)? The add-on sends both spellings where they differ.
7. **Language and genre spellings.** The approved language list is lower-case (`hindi`) but examples send
   `Bengali` / `Haryanvi`; which is accepted? Is there a lookup endpoint for genres, languages and moods
   (the Postman body has a `genreId`)? Note "Partiotic" (sic) in the mood list.
8. **Updates.** Which `type` values does `/album/media/signedurl` accept for audio (only `Artwork` is
   documented), and how does `/album/media/update` link a replaced file? What does `is_update: true` in
   `/album/add/meta` do? When will updates after submission be possible?
9. **Expired upload links.** Presigned URLs expire after 24 h. If uploads cannot finish in time, how do we
   get new links for an ingestion token without a new `/album/add/meta` (which reports *Duplicate ISRC*)?
10. **Label id vs. name.** Verify takes `label_id`; add/meta takes `label` as a name. Must the name match
    the COSMOS label/sub-label exactly? Is there an endpoint listing the account's labels?
11. **Required fields.** Is `mood` really required for every song? Is `album_category` free text?
12. **Artists.** Should we create/search artists (`/artists/add`, `/artists/search`) first and send their
    COSMOS ids, or are names plus Apple/Spotify ids enough for profile linking?
13. **Rate limits** and the maximum file size for a single (non-multipart) audio PUT.

## Open questions for the label / MusicDesk

- Release eligibility: the add-on sends releases that are *Approved* or *Ready for delivery*. Should it be
  *Ready* only?
- PDL deliveries are tracked in the add-on's own tables, not in core Distribution (`release_deliveries`);
  PDL is a distributor with many stores, not one store. Show them in core Distribution too?
- Masters moved to cloud storage by the Media storage add-on (server copy removed) cannot be sent; the
  page asks to upload them again.

## Tests

```
timeout 900 php vendor/bin/phpunit addons/pdl-distribution
```

Settings validation and encrypted storage, test connection, payload snapshot, the full flow with S3
uploads, retries and backoff, PDL errors, token renewal, checksum / expired links, status sync, update
and resubmit, permissions, lifecycle. `UPDATE_SNAPSHOTS=1` rewrites the payload snapshot.
