# Building a LabelDesk release (vendor only)

1. Update the version in `config/labeldesk.php` (`product.version`) and add a dated section to `CHANGELOG.md`.
2. Run the checks:
   - `vendor/bin/pint --test`
   - `vendor/bin/phpunit`
   - `composer audit`
   - `npm audit`
3. Build the package: `php scripts/package-release.php`
   (or `--skip-build` to reuse the current `public/build`).
4. The result is `build/labeldesk-<version>.zip`. It contains the application, production-only `vendor/`,
   built assets, docs and an empty `storage/` skeleton.

The script refuses to produce a package that contains `.env`, `node_modules`, tests, logs, backups,
`.git` or dev packages such as PHPUnit. It also removes cached config and routes from
`bootstrap/cache`.

5. Smoke test: unzip the package somewhere, then run `php artisan --version`, then install it with the
   wizard on a test domain (a staging domain does not use a production license slot).

Customers install with `docs/installation.md` and upgrade with the steps in `docs/backups.md` → "Upgrading".
