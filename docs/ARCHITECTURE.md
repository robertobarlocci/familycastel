# Family Castel — Architecture

A hand-rolled PHP 8.2 application in the Kanboard/FreshRSS tradition: no
framework, no runtime Composer, everything the release needs is in the ZIP.
The full design rationale lives in [DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md);
this is the orientation map.

## Request flow

```
Apache (.htaccess) ──► index.php
                        ├─ boot gate: code VERSION vs DB app.version → 503 unless parent update path
                        ├─ maintenance flag → friendly 503
                        ├─ security headers + CSP (no inline scripts)
                        └─ Router (regex, subdirectory-safe, ?r= fallback)
                             └─ controller closure → service(s) → plain PHP view
```

- **Escaping is contextual and mandatory**: `e()` HTML, `eattr()` attributes,
  `ejs()` JS strings, `eurl()` URL segments.
- **BasePath** is detected per request; nothing hardcodes the install path.

## Economy invariants

- `transactions` is **append-only**; child `coin_balance`/`xp_total` are
  derived aggregates updated in the same DB transaction by `LedgerService`.
  Corrections are compensating `reverse()` entries (`reversal_of` UNIQUE) —
  never edits, never deletes (INV-001/INV-002).
- Spending uses **atomic reservations**: reserve on request (balance checked
  under row lock), settle or release on decision; idempotency via
  status-guard UPDATEs (0 rows = already decided) + UNIQUE idempotency keys.
- Sidequest claiming is race-free through `sidequest_claim_slots`
  (UNIQUE(sidequest_id, recurrence_bucket, scope_key)) — first-come family
  quests use scope `g`, per-child quests their child id. Slots free on
  reject/cancel always; on approval only for `repeating` quests.
- **WriteGate**: every domain mutator starts with
  `SELECT ... FROM ops_state LOCK IN SHARE MODE`; backup/restore/update take
  the row `FOR UPDATE`, which drains all writers before maintenance work.
  Lock order everywhere: gate → definitions → claims/requests → children →
  transactions.

## Ops subsystem

- **update.php** is standalone (never swapped, loads no app classes during
  swap/rollback). Journaled step machine, resumable; per-entry swap intent +
  `added[]` bookkeeping; rollback restores files AND the pre-update DB
  snapshot, checked — anything unprovable fails CLOSED in maintenance.
- **Lock protocol**: one blocking flock mutex (`storage/ops.lock.mutex`)
  serializes EVERY mutation of `storage/ops.lock` (acquire, re-entry, stale
  reclaim, release) across update.php and the app. Cross-request ownership
  lives in the lock dir's owner token (per-run id); journal writes are fenced
  per run under `storage/updates/state.lock`.
- **BackupService**: consistent-snapshot SQL dump (REPEATABLE READ +
  CONSISTENT SNAPSHOT, keyset pagination, hex literals for NUL/binary),
  uploads + config in one ZIP; the filesystem (`storage/backups/*/meta.json`)
  is the source of truth; emergency backups are never pruned.
- **UpdateChecker**: GitHub latest-release, ETag + 24h cache, strict
  validation (semver, GitHub hosts, size) on every return path.

## Theming (RULE #1)

`ThemeService` is data-driven: titles per level band, five world tiers, CSS
tokens, and a world partial (`views/kid/_world_*.php` — layered inline SVG).
Adding a theme is content, not engine work. Kid pages never render admin
furniture; celebrations (level-up, achievements) are queued in the DB and
consumed atomically so they fire exactly once.

## Security posture

CSRF tokens on every POST; sessions locked to purpose (parent vs child);
login throttling per subject AND per IP (DB-backed, advisory-locked);
child QR tokens are 256-bit, shape-validated, revocable; installer is
double-gated (CAN_INSTALL marker + setup token) and self-probes `.htaccess`
protection with a nonce control; CSP without inline script; the updater
accepts only SHA256-verified GitHub packages and never lets a manifest
delete outside the swapped code entries.
