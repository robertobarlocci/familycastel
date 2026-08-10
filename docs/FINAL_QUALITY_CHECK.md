# Final Quality Check — Family Castel v0.1.0

The brief's ten questions, answered independently by Claude (builder) and
Codex (reviewer, gpt-5.6-sol, fresh thread). Date: 2026-08-10.

| # | Question | Claude | Codex |
|---|---|---|---|
| 1 | Architecture — deployable on normal PHP shared hosting? | **YES** | **YES** |
| 2 | Installation — can a non-developer install this? | **YES** | **YES** |
| 3 | Updater — can an ordinary parent safely update from the admin UI? | **YES** | **YES** (after fix, round 2) |
| 4 | Security — are obvious security risks addressed? | **YES** | **YES** |
| 5 | UX — can a parent award Coins within seconds? | **YES** | **YES** |
| 6 | Game — does the child experience actually feel like a game? | **YES** | **YES** |
| 7 | Visual design — does the interface avoid looking like business software? | **YES** | **YES** |
| 8 | Data integrity — can historical events accidentally disappear? | **NO** (as required) | **NO** |
| 9 | Transactions — can Coins accidentally be duplicated or spent twice? | **NO** (as required) | **NO** (after fix, round 2) |
| 10 | Releases — can GitHub reliably produce installable update packages? | **YES** | **YES** |

Codex round 1 returned FAIL with exactly two blockers, both fixed the same
day: (Q3) the updater could never update `update.php` itself — the finish
step now self-installs the verified staged executor post-commit; (Q9)
coin-bearing forms were replayable — every such form now carries a one-time
nonce that becomes a scoped idempotency key (migration 005). Codex round 2:
**VERDICT: PASS**, no remaining blocking issues.

## Claude's evidence per question

1. **Shared hosting.** Runtime = Apache + PHP 8.2 + MySQL/MariaDB only; no
   Composer/Node/cron/Redis/shell (INV-003). Verified by installing the BUILT
   ZIP on stock `php:8.2-apache` and `php:8.3-apache` containers — including
   a subdirectory install with mod_rewrite entirely absent (auto-detected
   `?r=` link mode). `.htaccess` rewrite blocks are `<IfModule>`-guarded;
   protection also holds without the module (per-dir `Require all denied` +
   `FilesMatch`). Subdirectory installs use no hardcoded absolute URLs.
2. **Installation.** Upload-ZIP → browser wizard: system checks with a real
   HTTP self-probe of `storage/` protection (content-nonce control, port
   fallback), ownership proof via `storage/setup-token.txt`, DB test, family
   + parent setup, optional demo family. Blocking failures stop the wizard.
   Walked end-to-end in a real browser from the built ZIP.
3. **Updater.** One click from `System → Updates`: SHA256-verified GitHub
   download, automatic pre-update backup, journaled resumable steps, staged
   file verification against `release.json`, health checks, and rollback
   that restores files AND the DB snapshot — every failure path has a
   decided end-state (reopen / reverted / fail-closed). All maintenance
   serialized through one mutex-guarded lock (INV-005). The finish step
   SELF-UPDATES `update.php` from the verified staging copy (post-commit,
   non-fatal), so updater fixes reach existing installations — Codex round-1
   blocker, fixed and asserted in the updater system test. Proven by the
   3-scenario test (happy path incl. executor self-update, checksum abort,
   mid-migration rollback with byte-exact restore) and 8+2 review rounds.
4. **Security.** CSRF everywhere, contextual escaping (stored-XSS round-trip
   tests), prepared statements only, per-subject AND per-IP login throttling
   (child PINs included), 256-bit revocable QR tokens, double-gated installer,
   CSP without inline scripts, HttpOnly updater token (consumed at finish,
   replay = 403), https-only GitHub-host-pinned update downloads, redacted
   diagnostics. ~40 security findings from 20+ Codex review rounds all fixed.
5. **Parent speed.** Dashboard → child card → one-tap template award; the
   quick-award E2E test completes the flow in well under two seconds of
   interaction (three taps from login).
6/7. **Game feel & visuals.** Themed worlds that grow with level (fantasy
   camp→kingdom, football street→championship), title lines, coin/XP pills,
   progress bars, celebrations, sounds, PWA install; screenshot QA of both
   themes at both viewports; kid pages contain no admin tables (asserted in
   E2E). RULE #1 was a review gate at every UI milestone.
8. **History.** Append-only `transactions` ledger with reversal-only
   corrections (INV-001/002); templates/quests/rewards archive instead of
   delete; snapshots keep titles/amounts; backups retain emergency copies
   forever; restore always creates a retained emergency backup first.
9. **Double-spend.** Balances derive from the ledger inside row-locked DB
   transactions; spending uses atomic reservations; decisions are
   status-guard UPDATEs (0 rows = already decided) + UNIQUE idempotency
   keys; first-come quests use UNIQUE claim slots. Every coin-bearing FORM
   (template award, custom award, reward redemption, custom wish) now
   carries a one-time operation nonce that becomes a scoped idempotency
   key — an accidental double-submit maps onto the SAME ledger entry /
   pending request (Codex round-1 blocker, fixed; migration 005 +
   FormReplayIdempotencyTest). Dedicated concurrency integration tests
   (reservation race, double-approve, claim race, form replay) pass.
10. **Releases.** Tag-triggered pipeline: tag==VERSION==CHANGELOG gate, full
   tests, allowlist builder (symlink-proof incl. parents), and a
   clean-install gate that actually installs the built ZIP on both PHP
   versions/hosting variants and logs in before anything is published.

## Verdict

**PASS** — all ten questions answered in the required direction by BOTH
reviewers (Codex thread verdicts recorded in docs/CODEX_REVIEWS.md, T20 row).
