# Developing Family Castel

## Dev environment (Docker — dev only, never production)

```bash
docker compose -f docker/compose.yaml up -d
```

| What | Where |
|---|---|
| App | http://localhost:8090 |
| MariaDB | internal service `db` (root: `root-dev-password`) |
| App DB | `familycastel` (user `fc` / `fc-dev-password`) |
| Test DB | `familycastel_test` (wiped by every integration test run) |

First run: install via the wizard at http://localhost:8090 (setup token is in
`storage/setup-token.txt`), tick the demo-family checkbox. If storage writes
fail with empty logs, run
`docker compose -f docker/compose.yaml exec app chmod -R a+w storage config`.

Demo logins: parent `demo-parent` / `Schloss-Demo-2026`, Emma PIN `1234`,
Noah taps his avatar (no PIN).

## Tests

```bash
# Unit + integration (integration needs the MariaDB container)
docker compose -f docker/compose.yaml exec app php vendor/bin/phpunit

# Updater system test: 3 scenarios over real HTTP in a sandbox copy
docker compose -f docker/compose.yaml exec app bash tests/e2e-updater.sh

# Playwright E2E (host Node, against the dev stack incl. demo family)
cd e2e && npm install && npx playwright install chromium
npx playwright test                       # all suites, desktop + mobile
npm run screenshots                       # regenerates docs/screenshots/*
npm run reset-throttle                    # clear login throttling after repeated runs
```

Rules: tests first (RED→GREEN), ≥80% coverage on `app/Domain` + `app/Core`,
integration tests always against MariaDB — never SQLite.

## Release pipeline

- `ci.yml` — on every push/PR: `php -l` sweep, PHPUnit on PHP 8.2 + 8.3
  against MariaDB, the updater e2e scenarios, and a release-ZIP build check.
- `release.yml` — on tag `v*`: validates tag == `VERSION` == CHANGELOG entry,
  full tests, builds the package, runs the **clean-install gate** (unzips the
  built ZIP into fresh php:8.2-apache and php:8.3-apache stacks, asserts all
  `.htaccess` guards shipped and the protection probes return 403, walks the
  installer entry), then publishes the GitHub Release with ZIP + SHA256.
- `scripts/build-release.php` — allowlist-based package builder; generates
  `release.json` (version, min_php, update_from, per-file SHA256, removed[]).
  Retired paths go into `scripts/release-removed.json`.

Release checklist: bump `VERSION`, add the CHANGELOG entry, tag `vX.Y.Z`.

## Repository layout

```
index.php            front controller (+ manual-update boot gate)
update.php           standalone updater executor (never swapped by updates)
app/Core             router, db, session, csrf, i18n, escapers, error handler
app/Database         migrator + numbered migrations (up AND down, always)
app/Domain           services — ALL Coin/XP mutations via LedgerService
app/Http             controllers (Install, Auth, KidLogin, Kid/, Parent/)
app/Install          installer service, state machine, system checks
views/               plain PHP templates (kid/, parent/, install/, layouts/)
lang/                de.php (default), en.php
public-assets/       css, js, fonts, icons, vendored qrcode.js + confetti
scripts/             dev/CI tooling (release builder, demo seeder) — not shipped
tests/               PHPUnit Unit + Integration, e2e-updater.sh
e2e/                 Playwright suites + screenshot generator (host Node)
```

## Non-negotiables

See [CONTRIBUTING.md](../CONTRIBUTING.md): game-first UI (RULE #1), history
is never deleted, ledger-only Coin/XP mutations, shared-hosting runtime
(no Composer/Node/cron/Redis in production), "Family Castel" spelling.
