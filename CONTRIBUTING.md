# Contributing to Family Castel

Thanks for your interest! A few ground rules keep this project healthy.

## Ground rules

1. **RULE #1:** the child experience must feel like a game. UI changes that make
   a screenshot look like business software will not be merged.
2. **History is never deleted.** No PR may hard-delete ledger, journal or
   history rows, or orphan snapshots.
3. **Shared hosting is the target.** No runtime dependencies on Composer,
   Node, Docker, cron, Redis or shell access. Dev tooling may use anything.
4. Coins/XP flow only through `LedgerService`. Never write balances directly.
5. The product is spelled **"Family Castel"**, quests are **"Sidequests"**.

## Workflow

- Branch from `main`; never commit to `main` directly. Small, focused PRs.
- Tests first (PHPUnit lives in `tests/`; run inside the dev container).
  New behavior needs tests; 80% coverage on `app/Domain` + `app/Core`.
- Migrations need an `up()` **and** a working `down()`.
- Follow the existing code style: strict types, small files, immutable
  patterns, contextual escaping (`e()`, `eattr()`, `ejs()`, `eurl()`).

## Dev environment

```bash
docker compose -f docker/compose.yaml up -d     # app: http://localhost:8090
docker compose -f docker/compose.yaml exec app php vendor/bin/phpunit
docker compose -f docker/compose.yaml exec app bash tests/e2e-updater.sh
```
