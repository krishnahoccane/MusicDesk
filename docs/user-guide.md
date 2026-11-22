# LabelDesk user guide

## Roles

| Role | For | Can |
|---|---|---|
| Super Admin | Label owner / IT | Everything, including users, roles, settings, backups and the license |
| Label Manager | Day-to-day running of the label | Everything except users, roles, settings, backups and license management |
| Artist Manager | A&R | Artists, releases, tracks, contributors, contracts and documents |
| Distribution Manager | Delivery | Reviews releases, manages store deliveries and the store list |
| Accountant | Finance | Royalties, sales report imports, payments, financial reports |
| Viewer | Read-only staff | Views the catalogue, distribution and basic reports |
| **Artist** | Artists themselves | Only their own artist profiles (see below) |

Administrators can create more roles in **Administration → Roles** (Business and Enterprise plans).

## Artist accounts

An artist account is a login for an artist. It sees **only** the artist profiles it is linked to.

- **Create one:** Administration → Users → Add user, then choose the role **Artist** and the artist
  profiles. One account can manage several profiles (for example a solo name and a band).
- **What artists can do:**
  - edit their profiles, contacts and links;
  - create and edit releases and tracks (as drafts) and submit them for review;
  - add credits;
  - see where their releases are live;
  - read their contracts and documents;
  - see their earnings and download statements.
- **What artists never see:** other artists, label-wide revenue, sales imports, users, settings,
  reports, backups or the license.

  Pages for other artists' records answer "Page not found". Duplicate UPC / ISRC checks still cover
  the whole catalogue, without revealing whose release it is.
- Artist accounts count towards the license's user allowance, like any active user.
- The Artist role's permissions are fixed and cannot be edited.

## Bulk upload

**Catalog → Bulk upload** adds many artists or releases at once.

1. Download the Excel or CSV template. Keep its first row (the headings); columns marked * are required.
2. Fill one row per artist, or for releases **one row per track**. Rows with the same UPC (or the same
   release title, version and artists) become one release, with tracks in row order.
3. Upload the file and press **Check file**. LabelDesk lists what it will create and every problem with
   its row number. Nothing is saved yet.
4. Press **Import**. Releases are created as drafts. Add artwork and audio on each release, then submit
   it for review.

Rules:
- Existing artists (same name) are skipped, never overwritten.
- A release is skipped if any of its rows has a problem.
- Unknown artist names are errors unless an administrator or staff member ticks **Create missing
  artists**.
- Up to 2,000 rows per file.

**Who may use it** (Settings → Catalogue → Bulk upload):

| Who | Access |
|---|---|
| Administrators | Always |
| Label team | Staff whose role has "Bulk upload artists and releases" (on by default) |
| Artist accounts | Off by default. When on, artists can upload releases for their own profiles only |

## Importing sales reports

**Reports → Import sales reports** (administrators and accountants):

1. Choose the open royalty period the report covers, or create one, such as "2026 October".
2. Upload the distributor's or store's sales/streaming report (CSV or Excel).
3. Map the columns. LabelDesk guesses them and can save the mapping as a profile for next time.
   Set the currency and exchange rate, then process the report.
4. Lines are matched to tracks by ISRC or UPC. Unmatched lines can be matched by hand in the period.

Imported sales appear in the **Royalty revenue** report (by store, territory, track …). Once the
period is locked, they also appear in artist earnings and statements.

## Reports

**Business → Reports**:

- **Catalogue:** releases by status/date, catalogue gaps.
- **Distribution:** deliveries, distribution by store.
- **Royalties:** revenue, artist balances, payments.
- **Contracts:** contracts ending soon.

Filter on screen, then export to Excel or CSV. Exports are logged.

## Look and sign-in page

**Settings → Appearance** (administrators):

- **Colours:** a colour theme (MusicDesk by default) or your own two brand colours.
- **Light and dark mode:** allow both (with a default), or force light or dark.
- **Sidebar and font:** sidebar style and font.
- **Logo and favicon:** logo, dark-mode logo and favicon.
- **Sign-in page:** pick one of five designs, edit the texts, add a cover image. **Preview** shows the
  saved result.

## Backups, upgrades and system health

See [backups.md](backups.md), plus **Administration → System** for health checks and upgrades.
