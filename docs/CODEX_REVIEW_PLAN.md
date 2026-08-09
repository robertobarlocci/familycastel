# Codex Review of the Development Plan

Reviewer: Codex (gpt-5.6-sol, fresh thread, read-only). Plan under review:
`docs/DEVELOPMENT_PLAN.md`.

## Round 1 — 2026-08-09

Checks run by Codex: concurrency, authorization/authentication, CSRF/rate limiting,
zip-slip/updater recovery, subdirectory/shared-hosting behavior, XSS, PWA caching,
migrations, permissions, backup/restore. Structure check passed: `## Teilaufgaben`
present, scope surgical, INV-004 naming correct.

`SEVERITY-COUNTS: CRITICAL=2 HIGH=7 MEDIUM=2 LOW=0` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.1 |
|---|-----|------|---------------------|--------------------------|
| 1 | CRITICAL | release security | "Strip dotfiles" in the release build would remove the `.htaccess` files that protect credentials/backups and provide routing (INV-003 break). | Release builder switched to an explicit **allowlist manifest** (never a blocklist); `.htaccess` files are first-class release artifacts; clean-install CI asserts their presence and that protection probes pass. |
| 2 | CRITICAL | updater recovery | Renaming live `index.php`/`app/` away can leave no executable code to serve the next step; JSON journal is not an atomic lock; 10-min maintenance auto-expiry can expose mixed code/schema. | Swap is executed by a **standalone self-contained `update.php`** (no autoload/app dependency) which — with `storage/` — is never part of the swapped set; new `update.php` applied last after success. Lock = atomic `mkdir()`; JSON journal only records state. Maintenance auto-expiry applies **only when the journal is idle/terminal**; mid-swap it persists and `update.php` offers resume/rollback. |
| 3 | HIGH | sidequest concurrency | `recurrence_bucket` missing from schema; proposed unique key yields one winner per *child*, not one global first-come winner; `NOT EXISTS` races. | Added `recurrence_bucket` column; added operational **`sidequest_claim_slots`** table with `UNIQUE(sidequest_id, recurrence_bucket, scope_key)` (`scope_key='g'` for first-come, `child_id` otherwise); slot inserted with the claim under a `SELECT … FOR UPDATE` on the sidequest row (also guards `max_completions`); slot freed when a claim is released (slots are operational, claims keep history — INV-001 intact). Documented global lock order. |
| 4 | HIGH | reservations | Approval status flip, ledger post and cache update not explicitly one transaction with consistent lock order; deductions ignoring reservations can make pending requests unfundable (INV-002 risk). | Plan now states: every approval is ONE DB transaction (status-guard UPDATE → child `FOR UPDATE` → ledger insert → cache update → commit) under a documented lock order (sidequests → claims/requests → children → transactions). Deductions check **available** balance (balance − reservations) by default; overriding shows a warning; reward approval re-validates funds and fails gracefully if a forced deduction consumed them. |
| 5 | HIGH | ledger integrity | "Never UPDATE except status of reservations" contradicts append-only; reversals lack exactly-once constraint. | `transactions` is strictly append-only — **no UPDATE ever** (reservations are pending `reward_requests`, not ledger rows; wording fixed). Added `reversal_of BIGINT NULL UNIQUE` column → exactly-once reversal linkage. |
| 6 | HIGH | installer security | First visitor can claim a freshly uploaded install (shipped `CAN_INSTALL` has no secret); protection probes must hard-fail. | Installer step 1 now requires a **filesystem-ownership proof**: the installer writes a random setup code to `storage/setup-token.txt` (HTTP-denied) and the user must read it via their hosting file manager/FTP and enter it. Config/storage HTTP-exposure probes are **blocking (red)**, not warnings. |
| 7 | HIGH | restore semantics | Restoring an older dump removes newer history from active state (INV-001 tension); "transactional per table batch" not atomic across MariaDB DDL. | Restore redefined as an explicit disaster-recovery **point-in-time replacement**: typed confirmation, automatic **retained** emergency backup first (so no history is ever lost — it lives in that archive; UI says exactly that), maintenance mode, full drop/recreate from dump, failure path = re-apply emergency backup. Misleading transactional wording removed. |
| 8 | HIGH | PWA privacy | Network-first caching of authenticated HTML can leak parent/child pages after logout/user switch. | SW caches **only static shell assets + a public offline page**. Navigations/JSON are never cached (network with static-offline fallback only). |
| 9 | HIGH | PHP compatibility | CI/tests cover PHP 8.3 only while claiming 8.2+ support; missing shared-hosting configuration tests. | PHPUnit pinned to **11.5** (supports PHP 8.2) so the whole suite runs on a **CI matrix: PHP 8.2 + 8.3**; clean-install E2E also runs on 8.2; added no-rewrite (`?r=`) routing test and subdirectory-install (`/family/`) E2E job. |
| 10 | MEDIUM | app security | Hashing story inconsistent; single `e()` insufficient across HTML/attr/URL/JS contexts; CSP/security headers and QR-token referrer protection missing. | `password_hash(PASSWORD_DEFAULT)` + `password_needs_rehash()` upgrade on login (no Argon2id assumption). Contextual escapers `e()/eattr()/ejs()/eurl()`. Global security headers: CSP (`default-src 'self'`, no inline script), X-Content-Type-Options, X-Frame-Options, `Referrer-Policy: same-origin`. QR login immediately exchanges token → session → redirect (token never lingers in browsing context). |
| 11 | MEDIUM | testing gaps | Missing: updater interruption at every boundary, concurrent migrations, ZIP-bomb/manifest cases, authorization matrix, stored XSS, logout cache isolation, no-rewrite routing, subdirectory E2E. | All added to §14 of the plan verbatim. |

Decisions where Claude adjusted rather than adopted verbatim: none — all 11 findings
accepted as stated (finding 6's remedy chosen: filesystem-ownership setup code, the
strongest of the options Codex's finding implied).

## Round 2 — 2026-08-09

All 11 round-1 resolutions traced and confirmed; 8 new/residual findings.
`SEVERITY-COUNTS: CRITICAL=0 HIGH=4 MEDIUM=3 LOW=1` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.2 |
|---|-----|------|---------------------|--------------------------|
| 1 | HIGH | installer | §3 still said probe "warns" (contradicting §8); ownership proof ran before the protection probe so the setup token could be HTTP-readable; Host-header pinning is unauthenticated trust. | §3 now blocking-RED; wizard reordered: system check (probes pass) **then** ownership proof; Host pinning removed — relative/base-path URLs only, derived from `SCRIPT_NAME`. |
| 2 | HIGH | updater rollback | Rolling back a partially applied migration via `down()` can still lose data even when `down()` "succeeds". | Migration failure now **always restores the pre-update DB backup** (exact snapshot); `down()` retained for dev/manual use only, update rollback never depends on it. |
| 3 | HIGH | backup consistency | Table-by-table dump without a consistent InnoDB snapshot; restore quiesced writes only after the emergency backup. | Dump runs under `START TRANSACTION WITH CONSISTENT SNAPSHOT` on one connection (mysqldump `--single-transaction` equivalent); restore flow reordered: **maintenance mode first**, then emergency backup. |
| 4 | HIGH | restore retention | `backup_history.kind` had no 'emergency'; restoring an older DB deletes the emergency backup's own history row → the promised retained archive wasn't implementable. | Added `kind='emergency'` (never auto-pruned; manual typed-confirm deletion only). `backup_history` demoted to a cache — **filesystem (`storage/backups/*/meta.json`) is the source of truth**, re-synced after restore, so the emergency backup is always listed. |
| 5 | MEDIUM | auth consistency | §5 still mandated Argon2id vs §2's `PASSWORD_DEFAULT`. | §5 aligned: `PASSWORD_DEFAULT` + `password_needs_rehash()` upgrade on login. |
| 6 | MEDIUM | ZIP extraction | Tests mentioned a bomb guard but the design had no concrete limits. | Explicit limits in §9 step 6: ≤10 000 entries, ≤50 MB/entry, ≤500 MB cumulative, ratio ≤100:1, abort pre-write. |
| 7 | MEDIUM | testing | Interruption tests covered only swap/migration boundaries. | §14 now kills at **every** journal step: preflight, download, verify, backup, maintenance-on, extract, each swap rename, migrate, health-check, finish — resume AND rollback proven at each. |
| 8 | LOW | ledger wording | §6 said reversals link via `source_id` while the schema uses `reversal_of`. | §6 now references `reversal_of` (UNIQUE) consistently. |

Decisions where Claude adjusted rather than adopted verbatim: none — all 8 accepted.

## Round 3 — 2026-08-09

All 8 round-2 resolutions traced and confirmed; 3 new findings, all in the update flow.
`SEVERITY-COUNTS: CRITICAL=1 HIGH=1 MEDIUM=1 LOW=0` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.3 |
|---|-----|------|---------------------|--------------------------|
| 1 | CRITICAL | updater/history | Pre-update backup ran before maintenance mode → a ledger write landing between snapshot and maintenance would be lost by a rollback restore (INV-001). | Steps reordered: **maintenance on (step 4) before the pre-update backup (step 5)**; maintenance stays on continuously until success or completed rollback, so the snapshot is provably exact. |
| 2 | HIGH | updater rollback | Undefined failure handling after migrations succeed (health check / update.php replacement / finish). | §9 step 9 now defines rollback per phase; any failure during **or after** migrations restores **both** the DB snapshot and the files together — code and schema never move independently. |
| 3 | MEDIUM | manual updates | "FTP replace + migrations-on-boot" had no maintenance, backup, or schema-mismatch gating. | Schema mismatch now forces maintenance behavior on all routes (503) except a parent "update pending" flow that runs the same journaled machine: backup → migrate → health check, same rollback rule. |

Decisions where Claude adjusted rather than adopted verbatim: none — all 3 accepted.

## Round 4 — 2026-08-09

Round-3 resolutions verified; 3 new findings (updater finalization + recovery ops).
`SEVERITY-COUNTS: CRITICAL=1 HIGH=2 MEDIUM=0 LOW=0` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.4 |
|---|-----|------|---------------------|--------------------------|
| 1 | CRITICAL | updater finalization | §9.10 lifted maintenance before recording success/pruning → a ledger write in that gap could be lost if a finish-step failure triggered rollback (INV-001). | Finish reordered: everything (health check, update.php swap, success record, prune) happens **under maintenance**; **maintenance-off is the last action and the defined point of no return** — after it, no automatic rollback can ever run; post-commit hiccups are logged only. |
| 2 | HIGH | manual FTP updates | Schema-version gating misses partial uploads and schema-neutral releases; no old-file snapshot for rollback. | Gate now compares schema version AND recorded app version vs code `VERSION`; the update-pending flow first **verifies code against the release.json per-file SHA256 manifest** (partial upload = blocked), then backup → migrate → health → record version. Rollback honesty documented: exact DB restore + guided re-upload of previous release (app can't snapshot files it never had); gate stays closed until manifest+schema+version agree. |
| 3 | HIGH | recovery concurrency | Updater lock covered only updater instances; update/restore/backup could interleave. | One global exclusive recovery lock (`mkdir(storage/ops.lock)`) shared by updater, restore, backup creation and manual-update flow. |

Decisions where Claude adjusted rather than adopted verbatim: none — all 3 accepted.

## Round 5 — 2026-08-09

Round-4 resolutions verified as landed; 2 HIGH + 1 LOW residual.
`SEVERITY-COUNTS: CRITICAL=0 HIGH=2 MEDIUM=0 LOW=1` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.5 |
|---|-----|------|---------------------|--------------------------|
| 1 | HIGH | manual FTP updates | If `VERSION` uploads last, partially replaced PHP executes while versions still match — the gate can't act retroactively once files mutate under the interpreter. | Manual path redefined as **maintenance-first by design**: UI "Prepare manual update" turns maintenance ON before any upload; `update.php` then verifies manifest → backup → migrate → health → record → maintenance-off-last. Version/schema gate kept as defense-in-depth; docs state plainly that only the prepared procedure closes the window completely and the built-in updater is primary. |
| 2 | HIGH | lock reentrancy | Update/restore hold `ops.lock` and call backup creation which acquires the same non-reentrant lock → deadlock. | Lock gets an owner token; `BackupService::createBackup()` (acquires) vs `createBackupLocked()` (asserts caller ownership, used inside update/restore). Never nested. |
| 3 | LOW | finish-step wording | Pruning failure was describable as both rollback-triggering and log-only. | §9.10 now explicit: rollback-triggering failures end at health-check/update.php swap; pruning is non-fatal, logged only; maintenance-off remains last. |

Decisions where Claude adjusted rather than adopted verbatim: none — all accepted.

## Round 6 — 2026-08-09

Round-5 resolutions verified; 1 MEDIUM + 1 LOW residual.
`SEVERITY-COUNTS: CRITICAL=0 HIGH=0 MEDIUM=1 LOW=1` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.6 |
|---|-----|------|---------------------|--------------------------|
| 1 | MEDIUM | manual FTP updater | Overlay uploads never delete files in `release.json.removed[]` — a retired PHP entry point could stay executable after a security update. | `update.php` manifest verification now also **deletes every `removed[]` file** as part of the manual-update flow. |
| 2 | LOW | finish wording | §9.9 "any finish-step failure rolls back" still contradicted §9.10's non-fatal pruning. | §9.9 now scopes rollback to health check + `update.php` replacement and explicitly excludes pruning. |

Decisions where Claude adjusted rather than adopted verbatim: none — both accepted.

## Round 7 — 2026-08-09

Round-6 resolutions verified; 1 MEDIUM residual.
`SEVERITY-COUNTS: CRITICAL=0 HIGH=0 MEDIUM=1 LOW=0` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.7 |
|---|-----|------|---------------------|--------------------------|
| 1 | MEDIUM | built-in updater | `removed[]` handling existed only in the manual FTP path; the normal swap could leave a retired executable entry point live. | §9 step 7: after the entry swaps, every `removed[]` path is **moved into the backup dir** — never left live, never deleted outright (rollback restores it). |

## Round 8 — 2026-08-09

Round-7 resolution verified; 1 new CRITICAL.
`SEVERITY-COUNTS: CRITICAL=1 HIGH=0 MEDIUM=0 LOW=0` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.8 |
|---|-----|------|---------------------|--------------------------|
| 1 | CRITICAL | recovery quiescence | Maintenance blocks new requests but not in-flight writes that already passed the front controller — one could commit after the snapshot and be lost by rollback/restore (INV-001). | New §9 step 4b, three layers: (a) mutating services re-check the maintenance/ops flag immediately before opening their DB transaction; (b) journaled 60 s drain step after maintenance-on; (c) straggler verification — `MAX(transactions.id)` + mutable-table row counts recorded at snapshot start and re-checked before the swap; any drift re-runs the backup. Restore (§10) references the same barrier+drain. |

## Round 9 — 2026-08-09

Round-8 fix judged insufficient (timer drain is not a hard barrier; stalled mutators
and UPDATE-only writes escape counter verification).
`SEVERITY-COUNTS: CRITICAL=1 HIGH=0 MEDIUM=0 LOW=0` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.9 |
|---|-----|------|---------------------|--------------------------|
| 1 | CRITICAL | recovery quiescence | §9 4b's flag re-check + 60 s drain + MAX(id)/row-count verification still allows a stalled mutator (>60 s) or an UPDATE-only write to commit after the snapshot. | Replaced with a **DB-coordinated shared/exclusive gate**: `ops_state(id=1, write_locked)` row; every mutating transaction's first statement is `FOR SHARE` on that row (held to COMMIT); updater/restore sets `write_locked=1` then acquires `FOR UPDATE` on it — acquisition **is** the drain (blocks until all in-flight writers finish), and the same transaction continues as the consistent snapshot. No timing window exists; guarantee persists across the multi-request step machine because the state lives in the DB. Gate added to §4 schema (table 22) and to §6's global lock order. MAX(id) check kept as a defense-in-depth assertion only. |

## Round 10 — 2026-08-09

Gate design confirmed sound; 1 MEDIUM + 1 LOW residual.
`SEVERITY-COUNTS: CRITICAL=0 HIGH=0 MEDIUM=1 LOW=1` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.10 |
|---|-----|------|---------------------|--------------------------|
| 1 | MEDIUM | gate lifecycle | Cleanup/cancel paths never explicitly reset `write_locked=0` — a pre-swap failure could leave a healthy-looking site where every mutation aborts. | §9 4b: gate release now explicit on EVERY terminal path (success, rollback, pre-swap cleanup, cancel, "clear stuck update"), with an integration test asserting each. |
| 2 | LOW | MariaDB syntax | `FOR SHARE` is MySQL 8 spelling; MariaDB 10.11 uses `LOCK IN SHARE MODE`. | Wording fixed to `LOCK IN SHARE MODE`. |

## Round 11 — 2026-08-09

`SEVERITY-COUNTS: CRITICAL=0 HIGH=1 MEDIUM=0 LOW=1` → `PLAN-VERDICT: CHANGES-REQUESTED`

| # | Sev | Area | Finding (condensed) | Resolution in plan v1.11 |
|---|-----|------|---------------------|--------------------------|
| 1 | HIGH | manual updater | "Prepare manual update" enabled maintenance but didn't acquire/drain the DB write gate before uploads begin. | Prepare now sets `write_locked=1` AND completes exclusive gate acquisition (drain); UI declares "safe to upload" only after writers provably finished. |
| 2 | LOW | syntax remnants | `FOR SHARE` still appeared in §4/§6/§9 prose. | All occurrences now `LOCK IN SHARE MODE` / "shared lock". |

## Round 12 — 2026-08-09 — APPROVED ✅

No findings. Both round-11 resolutions verified.
`SEVERITY-COUNTS: CRITICAL=0 HIGH=0 MEDIUM=0 LOW=0` → `PLAN-VERDICT: APPROVED`

**The plan gate is open.** Plan v1.11 is the approved architecture (36 findings fixed
across 12 rounds). Any future edit to docs/DEVELOPMENT_PLAN.md re-arms this gate and
requires a fresh Codex review.
