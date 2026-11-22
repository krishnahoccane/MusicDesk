# LabelDesk

Self-hosted music label management software by [MusicDesk](https://musicdesk.tech): artists,
releases, tracks, manual DSP distribution, royalties, contracts and reports for a single label.

> Status: in development (Milestone 1 of 10 complete). See `DEVELOPMENT_PROGRESS.md`.

## Server requirements (production)

- PHP 8.2+ (8.3 recommended) with: bcmath, ctype, curl, fileinfo, gd, json, mbstring, openssl,
  pdo_mysql, sodium, tokenizer, xml, zip (intl recommended)
- MySQL 8.0+ or MariaDB 10.6+
- Apache (mod_rewrite) or Nginx; works on cPanel shared hosting
- No Node.js, Composer, Redis or Docker required on the server — the release package ships with
  `vendor/` and compiled assets.

Customer installation will use the browser-based installation wizard (Milestone 2).

## Development setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build            # or: npm run dev
php artisan db:seed --class=LocalDevelopmentSeeder   # local env only
php artisan serve
```

Sign in at http://127.0.0.1:8000 with the local development account defined in
`database/seeders/LocalDevelopmentSeeder.php`.

### Useful commands

| Command | Purpose |
|---------|---------|
| `php artisan test` | Run the test suite (SQLite in-memory) |
| `vendor/bin/pint` | Format PHP code |
| `npm run build` | Compile SCSS/JS to `public/build` |
| `npm run icons:sync` | Refresh `resources/icons/lucide` after upgrading `lucide-static` |

## Project documents

- `DEVELOPMENT_PLAN.md` — milestones and next steps
- `DEVELOPMENT_PROGRESS.md` — what has been built, tested and what is outstanding
- `ARCHITECTURE.md` — structure, conventions, theme and security design
- `DATABASE_SCHEMA.md` — tables and columns
- `LICENSE_API_SPEC.md` — MusicDesk.tech licensing API contract (draft)
- `CHANGELOG.md`

## License

Commercial software. Use requires a valid license purchased from https://musicdesk.tech.
Bundled third-party components keep their own licenses (e.g. Lucide icons: ISC).
