# Family Castel — Development Plan

Version: 1.11 (2026-08-09) · Lead: Claude · Second reviewer: Codex
Status: revised after Codex rounds 1–11 (36 findings addressed in total — see
docs/CODEX_REVIEW_PLAN.md); awaiting round-12 approval

> **RULE #1 — Family Castel is a game first.** If a screenshot looks like business
> software, the design is wrong.

**Surgical scope:** limited to exactly the owner's brief — a self-hosted single-family
PHP 8.2+/MySQL reward game deployable on shared hosting, with the feature set, installer,
updater, backups, themes, PWA, tests, docs and local demo the brief enumerates.

**Out of scope (tempting but excluded):** SaaS/multi-tenancy, external accounts or
telemetry, push-notification delivery (architecture-ready only), sibling leaderboards,
loot-box randomness, real-money anything, character customization beyond per-theme
characters + level visuals, custom theme upload, email sending, REST API for third
parties, Docker-based production deployment.

---

## 1. Environment & ground rules

- Dev machine: Ubuntu 26.04, **no host PHP/MySQL, no passwordless sudo → everything runs
  in Docker** (user is in docker group). Production never needs Docker (INV-003).
- Local demo: `http://localhost:8090` (8080 is taken by another project).
- Git: never commit to main; work on `feat/*` branches; PR-based merges.
- History is never deleted (INV-001); Coins/XP only via ledger (INV-002); name is
  exactly "Family Castel", quests are "Sidequests" (INV-004).

## 2. Technology decisions

**Research & Reuse verdict (4 parallel research agents, 2026-08-09):** no maintained
open-source PHP/MySQL gamified family-reward app exists (closest PHP candidates are dead
since 2014 or trivial; the good ones — Habitica, Donetick, HabitTrove, Pointsy — are
Node/Go/Python and mostly GPL/AGPL/CC-NC, so code porting is off the table). We build
fresh and adopt proven *patterns*: append-only Coin ledger, reserve-then-approve
redemption, dual-currency XP/Coins at independent rates, two parent approval queues,
kid PIN+avatar login without email accounts, positive-only streaks (no Habitica-style
punishment loops), and per-chore anti-farming awareness. Architecture reference points:
Kanboard and FreshRSS (hand-rolled router, PHP-array i18n, versioned migration runner).

| Concern | Decision | Why |
|---|---|---|
| Language/runtime | PHP 8.3 (target ≥8.2), strict_types | Brief; 8.3 is what Docker `php:8.3-apache` ships and cyon offers |
| Web server (dev) | Apache + mod_php via `php:8.3-apache` image | Mirrors shared hosting incl. `.htaccess`/mod_rewrite |
| DB | MariaDB 10.11 LTS (MySQL 8 compatible SQL), InnoDB, utf8mb4 | Brief; cyon runs MariaDB. Note: MySQL/MariaDB DDL is non-transactional → migrations kept small + idempotent, version recorded only after success |
| Framework | **None** — custom front controller + regex router + thin controllers/services/repositories (~a few hundred lines of core) | Zero vendor lock, tiny release ZIP, no Composer needed at runtime, full control over subdirectory installs |
| Templates | Plain PHP templates with mandatory **contextual escaping helpers** (`e()`/`eattr()`/`ejs()`/`eurl()`) + layout/partial system | No dependency; XSS discipline enforced by convention + code review + stored-XSS tests |
| DB access | PDO (prepared statements only), one thin `Db` wrapper; repositories per aggregate | Simple, testable |
| Migrations | Hand-rolled versioned runner: `app/Database/Migrations/NNN_name.php`, each with `up()` + `down()`, tracked in `schema_migrations`, executed in a transaction where DDL allows | Runs identically from installer, updater and tests; no Phinx dependency |
| i18n | PHP array files `lang/{de,en,fr,it}.php` + `t(key, params)` helper; German default | gettext is unreliable on shared hosting; arrays are trivially bundle-able |
| Sessions/auth | PHP native sessions (`use_strict_mode=1`, cookie: httponly, samesite=Lax, secure when HTTPS — set via `session_start([$opts])`, php.ini not controllable on shared hosting), regeneration on login/privilege change; `password_hash(PASSWORD_DEFAULT)` + `password_needs_rehash()` upgrade on login (no Argon2id assumption — often absent from shared-hosting builds) | Standard, dependency-free |
| CSRF | Synchronizer token per session, hidden field + header for fetch(); constant-time compare | Hand-rolled, ~40 lines |
| Login throttling | DB table `login_attempts` (per user + per IP windows), no Redis | Shared hosting |
| Frontend | Vanilla ES modules + hand-written CSS (design tokens per theme); no build step required to run | Shared hosting, performance, PWA simplicity |
| Confetti/FX | Vendor `canvas-confetti` 1.9.x (**ISC license**, ~13 kB min) as a static file + hand-rolled CSS keyframe FX (Duolingo-style hard-edge buttons, gel XP bars, FLIP coin-fly); everything gated by `prefers-reduced-motion` (incl. `disableForReducedMotion: true`) | Tiny, proven, no CDN |
| Fonts | Self-hosted **Fredoka** (display) + **Nunito** (body) — both SIL OFL 1.1 confirmed; WOFF2, latin subset, `font-display: swap` | Game feel, no external requests |
| Sounds | Small synthesized WebAudio chimes (no audio assets), parent-toggleable, played only after user gesture | Autoplay policy, zero payload |
| Unit/integration tests | PHPUnit **11.5** (dev-only, supports PHP 8.2 → the whole suite runs on the compatibility floor, not just 8.3) inside the PHP container; integration against real MariaDB (never SQLite) | CI matrix must prove PHP 8.2 works, so the test tool must run there; user rule "never SQLite" applied as: always the real target DB, MariaDB |
| E2E | Playwright (host Node) against `localhost:8090`; also generates `docs/screenshots/*` | Brief |
| CI/Release | GitHub Actions: lint+tests on PR; on tag `v*`: build production ZIP + SHA256 + GitHub Release | Brief |

Composer is used in development only (PHPUnit, dev tooling). The **runtime has zero
Composer dependencies** except bundled `canvas-confetti` (a static JS asset) — the
release ZIP is upload-and-run.

## 3. Repository layout

```
familycastle/
├── index.php                # front controller (serves from app root — most portable)
├── update.php               # standalone self-contained updater executor (never swapped by updates)
├── .htaccess                # rewrite to index.php; deny sensitive paths; no dir listing
├── app/
│   ├── Core/                # Router, Config, Db, Session, Csrf, Auth, I18n, View, Migrator, Clock
│   ├── Http/Controllers/    # thin; parent/, child/, install/, api/
│   ├── Domain/              # LedgerService, SidequestService, RewardService, MilestoneService,
│   │                        # AchievementService, LevelService, SuggestionService, BackupService,
│   │                        # UpdateService, AuditService
│   ├── Repository/          # PDO repositories
│   └── Database/Migrations/ # NNN_*.php with up()/down()
├── views/                   # PHP templates: layouts/, parent/, child/, install/, emails? (no)
├── public-assets/           # css/, js/, themes/{fantasy,football}/, fonts/, icons/  (web-readable by design)
├── lang/                    # de.php, en.php, fr.php, it.php
├── config/                  # config.sample.php + shipped CAN_INSTALL marker; config.php + installed.lock are RUNTIME-GENERATED (never in ZIP/git)
├── storage/                 # logs/, backups/, updates/, cache/, uploads/  (.htaccess deny all)
├── install/                 # not a separate app — just docs; installer lives in app/Http/Controllers/Install
├── tests/                   # Unit/, Integration/
├── e2e/                     # Playwright specs + screenshot specs
├── docker/                  # dev-only: compose.yaml, Dockerfile, php.ini
├── scripts/                 # dev/CI only: build-release.php, seed-demo.php wrapper
├── docs/                    # DEVELOPMENT_PLAN.md, CODEX_*.md, INSTALLATION.md, UPDATES.md, BACKUPS.md,
│                            # ARCHITECTURE.md, DEVELOPMENT.md, screenshots/
├── .github/workflows/       # ci.yml, release.yml
├── VERSION                  # single source of the semver
└── README.md CHANGELOG.md LICENSE SECURITY.md CONTRIBUTING.md
```

Serving from the app root (not `public/`) is deliberate: on shared hosting users drop the
ZIP contents into `htdocs/` or a subfolder and it works — no docroot reconfiguration.
Defense: `.htaccess` deny rules for `app/ config/ storage/ lang/ tests/ vendor/ docs/`,
plus each of those dirs ships its own `.htaccess` (`Require all denied`) and a guard
constant check in every PHP file. The installer's system check verifies the protection
by self-requesting probe files in `config/` and `storage/` over HTTP — **HTTP-readable
config/storage is a blocking RED failure** (consistent with §8): installation cannot
proceed until the host protects those paths.

**Subdirectory installs:** all URLs generated via `url($path)` using the auto-detected
base path; cookies scoped to base path; service worker scope-relative. No hardcoded URLs.

## 4. Database schema (MariaDB, utf8mb4, InnoDB; all times UTC DATETIME)

Conventions: `id BIGINT UNSIGNED AUTO_INCREMENT PK`; `created_at`/`updated_at`; soft
delete via `archived_at` where history references the row; FKs `ON DELETE RESTRICT`
(history must survive); optional table prefix from installer applied by the Db layer.

1. **settings** — key (PK), value (JSON), updated_at. Family name, timezone, language,
   negative-balance policy, animation/sound toggles, backup retention, XP curve params,
   milestone spend-mode default.
2. **users** (parents) — name, username, email NULL, password_hash, role ('parent'),
   is_active, last_login_at, failed-login bookkeeping lives in login_attempts.
3. **children** — name, avatar/character key, theme key, pin_hash NULL, level (cached),
   xp_total (cached, monotonic), coin_balance (cached, authoritative source = ledger),
   allowed_themes (JSON), sound_enabled, archived_at.
4. **auth_tokens** — child QR login: child_id, token_hash (sha256 of 32 random bytes),
   label, created_by, created_at, revoked_at, last_used_at. Regeneration revokes prior.
5. **login_attempts** — subject_type ('user'|'child'|'ip'), subject_key, attempted_at,
   success. Sliding-window throttle queries + periodic pruning (only of *attempt* rows —
   not history; this table is operational, not Journal data).
6. **transactions** (the ledger; **strictly append-only — no UPDATE or DELETE ever**;
   reservations are pending `reward_requests`, NOT ledger rows) — child_id, coins_delta
   INT (may be ±), xp_delta INT UNSIGNED (≥0, CHECK), type ENUM
   ('award','deduction','sidequest','suggestion','reward_spend','milestone_spend',
   'adjustment','reversal'), title VARCHAR(190) **snapshot**, description, comment,
   actor_user_id NULL, source_type/source_id NULL, idempotency_key VARCHAR(64) NULL
   UNIQUE, **reversal_of BIGINT NULL UNIQUE** (FK transactions.id — exactly-once
   reversal linkage), created_at. Reversal = compensating row, never DELETE.
7. **point_templates** — title, coins_delta, xp_delta, scope ('all'|'selected'),
   child_ids JSON NULL, is_favorite, sort, requires_confirm, archived_at.
8. **sidequests** — title, description, coins_reward, xp_reward, type
   ('once'|'daily'|'weekly'|'repeating'), ownership ('first_come'|'assigned'|'per_child'),
   assigned_child_ids JSON NULL, expires_at NULL, available_from NULL, max_completions
   NULL, status ('active'|'archived'), created_by, archived_at.
9. **sidequest_claims** (history — never deleted) — sidequest_id, child_id,
   **recurrence_bucket VARCHAR(16)** (''=one-time, '2026-08-09' daily, '2026-W32'
   weekly), status ('accepted'|'completed_pending'|'approved'|'rejected'|'cancelled'|
   'expired'), title/coins/xp **snapshots** at claim and at approval (parent may
   modify), accepted_at, completed_at, decided_at, decided_by, parent_comment,
   transaction_id NULL FK.
9b. **sidequest_claim_slots** (operational, NOT history — rows freed when a claim is
   released) — sidequest_id, recurrence_bucket, **scope_key VARCHAR(24)** ('g' for
   first-come = one global winner; the child_id for assigned/per-child modes),
   claim_id FK. **UNIQUE(sidequest_id, recurrence_bucket, scope_key)** is what makes
   claiming race-free: claim = one DB transaction doing `SELECT … FOR UPDATE` on the
   sidequest row (validates status/expiry/`max_completions` under the lock) + INSERT
   claim + INSERT slot; a duplicate-key error means someone was faster. Slot row is
   deleted when its claim is rejected/cancelled/expired (claims themselves are kept —
   INV-001 intact).
10. **suggestions** (child activity proposals) — child_id, title, suggested_coins,
    comment, status ('pending'|'approved'|'rejected'), approved_coins/xp, parent_comment,
    decided_by/decided_at, transaction_id NULL.
11. **rewards** — title, description, cost_coins, duration_minutes NULL, icon, status,
    archived_at.
12. **reward_requests** — reward_id NULL (NULL = custom request), child_id, title/cost
    **snapshots**, duration_minutes, status ('pending'|'approved'|'rejected'|
    'cancelled'), decided_by/decided_at, parent_comment, transaction_id NULL.
    Pending requests **reserve** coins (see §6).
13. **milestones** — child_id, title, target_coins, image/icon, spend_mode
    ('spend'|'progress_only'), status ('active'|'claimed'|'archived'), claimed_at,
    transaction_id NULL.
14. **milestone_requests** (child wishlist) — child_id, title, suggested_coins, status,
    decided_by/decided_at, parent_comment, milestone_id NULL.
15. **achievements** — code (unique), title_key, description_key, icon, rarity, rule
    JSON (metric + threshold), is_active.
16. **child_achievements** — child_id, achievement_id, unlocked_at, seen_at.
    UNIQUE(child_id, achievement_id).
17. **notifications** — recipient_type ('user'|'child'), recipient_id, type, payload
    JSON, read_at, created_at.
18. **audit_log** — actor_type/actor_id, action, subject_type/subject_id, details JSON,
    ip, created_at. Append-only.
19. **schema_migrations** — version (PK), name, applied_at, execution_ms.
20. **update_history** — from_version, to_version, status ('success'|'failed'|
    'rolled_back'), started_at, finished_at, log TEXT, backup_file.
21. **backup_history** — filename, size_bytes, kind ('manual'|'pre_update'|
    'emergency'), status, created_by, created_at. Cache only — re-synced from
    `storage/backups/*/meta.json` (filesystem is the source of truth, see §10).
22. **ops_state** — single row (id=1): write_locked TINYINT. The hard write gate for
    update/restore/backup quiescence (§9 step 4b); every mutating transaction opens
    with a `LOCK IN SHARE MODE` read of this row.

Level curve: computed from settings (default: XP for level n = round(50·n^1.6), tunable),
implemented once in `LevelService`; **no levels table** (config-driven per brief's
"configurable curve, not hardcoded in multiple places").

Indexes: transactions(child_id, created_at), sidequest_claims(sidequest_id, status),
reward_requests(child_id, status), notifications(recipient_type, recipient_id, read_at),
audit_log(created_at), login_attempts(subject_type, subject_key, attempted_at).

## 5. Roles, auth & permissions

- **Parent:** username/email + password, `password_hash(PASSWORD_DEFAULT)` (+ rehash
  upgrade on login, see below — consistent with §2). Session regeneration at login.
  Throttle: max 5 failures / 15 min per account and per IP → incremental delay + lockout
  message (no user enumeration). Multiple parents supported (equal rights, v1).
- **Child:** profile picker → PIN (4-6 digits, hashed, throttled) **or** QR token login
  (`/kid/<token>`): 32 random bytes base64url, stored hashed, revocable/regenerable by
  parents, no privileges beyond that child's game. QR shown in parent settings for
  printing.
- **Authorization:** central `Auth` + `requireParent()` / `requireChild($childId)` guards
  in the front controller route table (not per-controller ad hoc). Children can never
  mutate another child's data or approve anything (server-side enforced). A dedicated
  **authorization-matrix test** asserts every route × role combination.
- **CSRF:** all POSTs; SameSite=Lax; token rotated at login.
- Session cookie `Secure` flag auto-set when HTTPS; child and parent sessions are the
  same PHP session mechanism with distinct principal types; logging in as parent from a
  child session forces re-auth.
- **Hash upgrades:** `password_needs_rehash()` checked at every successful login —
  hashes transparently upgrade if PHP's default cost/algorithm changes.
- **QR login flow:** the token URL is exchanged immediately for a session and answered
  with a redirect to `/kid` — the token never persists in the browsing context.
- **Security headers (global):** `Content-Security-Policy: default-src 'self'` (no
  inline scripts — all JS in files; inline-style-free where practical),
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: same-origin`.
- **Output escaping is contextual:** `e()` (HTML text), `eattr()` (attributes),
  `ejs()` (safe JSON into script context via `json_encode` with hex flags), `eurl()`
  (`rawurlencode`). Template review rule: no raw echo of any variable, ever.

## 6. Economy correctness (the heart)

All Coin/XP mutations go through `LedgerService::post()` inside a DB transaction:

```
BEGIN;
SELECT * FROM children WHERE id=? FOR UPDATE;         -- per-child mutex
-- validate: balance + delta >= floor (0 or configured negative floor for deductions)
INSERT INTO transactions (...);                        -- with optional idempotency_key
UPDATE children SET coin_balance = coin_balance + :d,
                    xp_total = xp_total + :xp,
                    level = :computed;
COMMIT;
```

- **One transaction, one lock order.** Every approval/award/claim flow is a single DB
  transaction. Global lock order (always acquired in this sequence, never reversed):
  **ops_state gate (`LOCK IN SHARE MODE`, first statement — see §9 step 4b) →
  sidequests → sidequest_claims/reward_requests/suggestions → children → transactions
  insert**. Approval = status-guard UPDATE on the request row → child
  `FOR UPDATE` → ledger INSERT → cached-balance UPDATE → COMMIT.
- **Available balance** = `coin_balance` − SUM(cost of *pending* reward_requests).
  Reservation check happens under the same `FOR UPDATE` lock → two tabs requesting two
  60-Coin rewards with 60 Coins: second one fails with a friendly "coins already
  reserved" message. **Parent deductions also validate against available balance by
  default**; a parent may override with an explicit warning ("Emma's pending reward
  requests may no longer be fundable"). Reward **approval re-validates funds** under
  the lock and fails gracefully (request stays pending, parent told why) if an
  override consumed them — reserved coins can never go negative silently.
- **Idempotent approvals:** every approval flow does
  `UPDATE <request> SET status='approved', decided_by=... WHERE id=? AND
  status='pending'` first; 0 affected rows → someone already decided → **no** ledger
  post, show current state. `transaction_id` on the request + unique `idempotency_key`
  (`'sidequest_claim:'.$claimId`) is the second safety net (DB-level UNIQUE).
- **First-come Sidequests:** enforced structurally by `sidequest_claim_slots`'s
  UNIQUE(sidequest_id, recurrence_bucket, scope_key) under a `FOR UPDATE` on the
  sidequest row (see §4.9b) — the loser's INSERT hits the unique key and the child
  sees "Someone was faster!".
- **XP never decreases:** CHECK (xp_delta ≥ 0) + service-level guard; deductions carry
  xp_delta = 0.
- **Reversals:** parents can revert a mistaken award → compensating transaction of type
  'reversal' linked via **`reversal_of`** (UNIQUE — exactly-once, consistent with §4.6);
  XP reversal explicitly NOT performed (XP is permanent) —
  documented behavior; `adjustment` type exists for corrections that must touch XP
  upward only.
- Ledger is append-only: no UPDATE/DELETE of transactions rows, ever (code + DB user
  privileges recommendation in docs).

## 7. Routing & app flow (excerpt)

- `/` → role-aware redirect (installer if not installed; parent dashboard; child home).
- Parent: `/parent` (children cards + pending count), `/parent/child/{id}` (quick actions
  + favorite templates, one-tap award), `/parent/approvals`, `/parent/templates`,
  `/parent/sidequests`, `/parent/rewards`, `/parent/milestones`, `/parent/history`,
  `/parent/settings/*` (family, parents, children incl. QR, themes, sounds, backups,
  updates, system, diagnostics, export).
- Child: `/kid` (game home: world, character, level, XP bar, coins), `/kid/sidequests`,
  `/kid/rewards`, `/kid/milestones`, `/kid/achievements`, `/kid/journal`, `/kid/settings`
  (allowed theme switch, sound).
- Install: `/install` (wizard, locked post-install).
- JSON endpoints under `/api/*` for in-page actions (award, accept, approve…) — same
  session auth + CSRF header; JSON envelope {ok, data, error}.

## 8. Installer (wizard, no CLI)

Steps: Welcome → System check (PHP ≥8.2, PDO+pdo_mysql, mbstring, json, session,
openssl, curl, zip; writability of `config/`+`storage/`; HTTPS hint; `.htaccess`
protection self-test — **config/storage HTTP-exposure is a blocking RED failure, never
a warning**; red/yellow/green) → **Filesystem-ownership proof** (only after the
protection probe has passed — so the token can never be HTTP-readable — the installer
writes a random setup code to `storage/setup-token.txt` and the user must read it via
their hosting file manager/FTP and type it in; this closes the "first visitor claims
the fresh install" takeover window) → Database (host/port/name/user/pass/prefix;
live connection + privilege test) → Family (name, language de/en/fr/it, timezone with
intelligent default) → Parent account → Optional demo data → Install (write
`config/config.php` atomically via tmp+rename; run migrations; seed defaults; write
`config/installed.lock`) → Done.

Security (patterns adopted from Nextcloud/Matomo research):
- **Double gate:** installer activates only while `config/installed.lock` is absent
  **and** the `config/CAN_INSTALL` marker (shipped in the ZIP) exists. On success:
  write `installed.lock` (instance id + random app secret), delete `CAN_INSTALL`, warn
  loudly if deletion fails. This kills the "config deleted → reinstall takeover" attack.
- Re-running later requires an authenticated parent generating a time-limited unlock
  token from Settings → Danger zone (or the documented manual recovery via hosting file
  manager: recreate `CAN_INSTALL`, delete `installed.lock`).
- Config written as `<?php return [...];` via `var_export()` to a temp file in the same
  directory + `rename()` (atomic on same filesystem); no eval anywhere.
- Writability checks use a **real `touch()` probe**, never bare `is_writable()`
  (unreliable on NFS); `storage/` HTTP-protection probe fetches a test file over HTTP
  and expects 403/404.
- `installation_in_progress` state in `storage/install-state.json` with expiry (a few
  hours): half-written installs are cleaned up on re-entry. **No Host-header trust:**
  the app generates relative/base-path URLs exclusively (derived from `SCRIPT_NAME`);
  no unauthenticated Host header is ever pinned or persisted.

## 9. Updater (GitHub Releases, staged, no shell)

Source: public GitHub Releases of the project repo. Check: `GET
/repos/{owner}/{repo}/releases/latest` unauthenticated with **ETag/If-None-Match and a
≥24 h cache** (shared-hosting IPs share the 60 req/h quota; 403 = "retry later", never an
error page). Asset downloads don't count against the API quota. Release assets:
`family-castel-vX.Y.Z.zip` + `family-castel-vX.Y.Z.zip.sha256`.

**Step state machine over repeated admin-UI AJAX POSTs** (Nextcloud/phpBB model — PHP
max_execution_time can never bite): each request performs one step within a time budget
of `min(15s, max_execution_time/2)`, journals progress to `storage/updates/state.json`
(step, state, timestamp; resumable), and the browser drives the next step. Every step
is idempotent. **Concurrency: ONE global exclusive recovery lock** — atomic
`mkdir(storage/ops.lock)` with an owner token written inside — shared by the updater,
restore, standalone backup creation and the manual-update flow (the JSON journal only
records state, it is never the lock). **The lock is non-reentrant by design and never
nested:** `BackupService` exposes `createBackup()` (acquires the lock; used for
standalone manual backups) and `createBackupLocked()` (asserts via the owner token that
the *caller* already holds the lock; used inside update/restore). So an update can
never race a restore, a backup can never snapshot mid-swap files, two updater instances
can never interleave, and no flow can deadlock on its own lock. Stale locks (dead
journal heartbeat) are reclaimable from the UI ("clear stuck update").

**Who executes the swap:** a **standalone, self-contained `update.php`** at the app
root — zero dependencies on `app/` or the autoloader, admin-session + update-secret
authenticated. `update.php`, `config/` and `storage/` are **never part of the swapped
set**, so there is always executable code able to resume or roll back, no matter where
an interruption happens. The new release's `update.php` is applied **last**, only after
a successful swap + health check.

1. **Preflight:** admin + CSRF + fresh journal; version compat (`update_from` floor and
   `min_php` from the release manifest); `disk_free_space()` ≥ 3× package size; recursive
   writability probe via real `touch()`; open_basedir sanity.
2. **Download** ZIP + `.sha256` into `storage/updates/` (inside the app root — same
   filesystem, so later `rename()` is truly atomic; never `sys_get_temp_dir()`).
3. **Verify SHA256** of the ZIP against the checksum asset.
4. **Maintenance mode on:** `storage/maintenance.flag` with timestamp; front controller
   checks it before anything else and serves a friendly 503. **Auto-expiry (10 min)
   applies only while the update journal is idle or terminal** (WordPress's
   stuck-".maintenance" lesson) — during an active swap/migration the flag persists and
   `update.php` offers resume or rollback, so mixed code/schema is never publicly
   served. Maintenance stays on continuously from here until success or completed
   rollback.
4b. **Hard write barrier — DB-coordinated gate (INV-001 guard):** maintenance alone
   only stops *new* requests; a hard barrier is needed because an in-flight mutator
   can stall arbitrarily long and UPDATE-only writes move no row counts. The gate is
   a single-row InnoDB table `ops_state(id=1, write_locked TINYINT)` using standard
   row locks (no special privileges — shared-hosting safe):
   - **Mutator contract (part of INV-002's LedgerService discipline):** every mutating
     transaction's FIRST statement is `SELECT write_locked FROM ops_state WHERE id=1
     LOCK IN SHARE MODE` (MariaDB 10.11 spelling of the shared lock); if
     `write_locked=1` → abort with the friendly maintenance message. The lock is held
     to COMMIT, so a mutator mid-flight is *visible* to the gate.
   - **Lock sequence:** the updater sets `write_locked=1` (autocommit), then the dump
     connection does `BEGIN; SELECT … FROM ops_state WHERE id=1 FOR UPDATE` — this
     **blocks until every in-flight writer (holding the shared lock) commits or rolls
     back**: acquiring the exclusive lock IS the drain, with no timer and no gap.
     From that moment every later mutator reads the committed `write_locked=1` and
     aborts; one that raced the check simply waits on the share lock, then reads 1,
     then aborts. **No mutation can commit anywhere between snapshot start and
     `write_locked=0`** — a guarantee that holds across the multi-request step
     machine because the lock state lives in the DB, not in a held connection.
   - The same dump transaction continues as the consistent snapshot (REPEATABLE
     READ): gate acquisition and snapshot start are one atomic act.
   - **Gate release is part of EVERY terminal path** — success finish, completed
     rollback, pre-swap failure cleanup (§9 step 9 first bullet), cancelled runs and
     the admin "clear stuck update" action all end with `write_locked=0` +
     maintenance handling together; an integration test asserts the gate is released
     on each terminal path (a leaked gate = healthy-looking site where every mutation
     aborts). Sanity assertion (defense-in-depth): `MAX(transactions.id)` recorded at
     snapshot start is re-checked before the swap; drift aborts the run.
   Restore (§10) uses the identical gate before its emergency backup.
5. **Automatic pre-update backup** (§10) — abort on failure. Taken **under maintenance
   mode after the drain**, so no write can occur between the snapshot and the update:
   restoring this backup is always an exact restore and can never lose a ledger entry
   (INV-001).
6. **Extract to staging** (`storage/updates/staging/`) entry-by-entry with zip-slip
   guards (reject `..`, absolute paths, backslashes, symlink entries; post-extract
   `realpath` containment check; allowlist of top-level dirs) and **ZIP-bomb limits**
   (max 10 000 entries; per-entry uncompressed cap 50 MB; cumulative uncompressed cap
   500 MB; compression ratio cap 100:1 — any breach aborts before writing). Validate
   `release.json` manifest (version, min_php, update_from, per-file SHA256 list) and
   verify the staged files against it.
7. **Swap (rename, not copy), executed by `update.php`:** for each code entry
   (`index.php`, `.htaccess`, `app/`, `views/`, `public-assets/`, `lang/`, `sw.js`,
   `VERSION`, …): `rename(live → storage/updates/backup-<oldver>/…)` then
   `rename(staging → live)`. `update.php`, `config/` and `storage/` live outside the
   swapped set and are **never touched** (immutable-release vs writable-runtime split
   documented in docs/UPDATES.md); the new release's `update.php` is applied last,
   post-health-check. After the entry swaps, every path in the manifest's `removed[]`
   list (retired files/dirs absent from staging) is **moved into the same backup dir**
   — never left live (an obsolete entry point must not survive a security update) and
   never deleted outright (so file rollback restores them too). If the process dies
   between renames, `update.php` (still intact by construction) reads the journal and
   resumes or rolls back on the next POST.
8. **Migrate in a fresh request** (new code is loaded — avoids Matomo's stale-classes
   mid-update bug); each migration logged. All migrations still ship `down()` (dev +
   manual use, per hard rule), but update rollback never depends on them.
9. **Rollback rules — defined for every phase, all under the still-active maintenance
   window:**
   - Failure at any step before the swap: clean staging, maintenance off, record
     failure. Nothing was touched.
   - Failure during the swap: rename backup dirs back (pure file rollback).
   - Failure during **or after** migrations — up to and including the health check and
     the `update.php` replacement (the finish step's pruning is explicitly excluded —
     non-fatal per step 10): restore
     the database from the pre-update backup dump (an **exact** snapshot restore,
     never down-migrations, since a "successful" `down()` can still lose data
     transformed or deleted by a partial `up()`; exactness is guaranteed because the
     snapshot was taken under maintenance) **and** file-rollback — code and schema
     always move back together, never independently. Then maintenance off, record
     `update_history` = failed/rolled_back with the full log.
10. **Finish — commit point defined:** still under maintenance: clear caches, health
    check (self-request `/health`), apply the new `update.php`, record
    `update_history` = success. **Rollback-triggering failures end here** — a failed
    health check or a failed `update.php` swap rolls back per step 9. Next, still
    under maintenance, prune old update backups per retention (emergency backups
    exempt) — **pruning is explicitly non-fatal: an error there is logged and never
    triggers rollback** (the update itself already succeeded). **Maintenance off is
    the very last action and the point of no return:** after it no automatic rollback
    can ever run again, so no ledger write made after maintenance-off can be lost to a
    snapshot restore (INV-001).

**Manual FTP-update path (gated, maintenance-first by design):** the documented and
UI-guided procedure is: Settings → Updates → **"Prepare manual update"** — this turns
maintenance mode ON, sets `write_locked=1` **and completes the exclusive gate
acquisition (drain) of §9 step 4b**; only after all in-flight writers have provably
finished does the UI declare "safe to upload now" — all *before* any file is
overwritten (the one state no server-side check can create retroactively once an FTP
upload has begun mutating code under the interpreter). Then: upload the release → open `update.php` (standalone, never swapped)
→ it (1) **verifies the uploaded code against its `release.json` per-file SHA256
manifest** — partial/corrupt uploads are detected and block everything until fixed —
**and deletes every file listed in the manifest's `removed[]` list** (an FTP overlay
never removes retired files by itself; a retired PHP entry point must not stay
executable after a security update);
(2) creates a backup; (3) runs migrations via the same journaled step machine;
(4) health-checks; (5) records the new app version; (6) lifts maintenance last.
Defense-in-depth for users who skip the prepared path: every request compares both the
DB schema version and the recorded app version against the code's `VERSION`, and any
mismatch forces maintenance behavior (503 everywhere except the parent update flow) —
this catches every upload where the version markers land early or the schema differs;
docs state plainly that only the prepare-maintenance procedure closes the window
completely, and the built-in updater remains the primary path. Rollback honesty: the
app cannot snapshot files it never had, so manual-path failure = exact DB restore from
the just-made backup **plus** a guided instruction to re-upload the previous release's
files (version number shown); the gate stays closed until manifest + schema + app
version agree, so mixed code/schema is never served past verification. Stuck-state
hygiene: admin UI shows a "clear stuck update" action (Nextcloud's lesson). The manifest format reserves an optional Ed25519 signature field for the
future; v1 integrity = HTTPS + SHA256 (per brief).

The updater is flagged for **deep Codex review** before implementation and after.

## 10. Backups & restore

- Create: ZIP containing `database.sql` (pure-PHP dump: SHOW CREATE TABLE + batched
  INSERTs, FK-order aware, **executed on one connection under `START TRANSACTION WITH
  CONSISTENT SNAPSHOT`** so all InnoDB tables come from a single point-in-time view —
  the equivalent of `mysqldump --single-transaction`; no maintenance mode needed for
  manual backups), `config/config.php`, `storage/uploads/`, `meta.json` (app version,
  schema version, created_at, kind). Stored in `storage/backups/`, downloadable.
  Retention: keep last 5 pre-update + configurable manual; **`kind='emergency'` backups
  are never auto-pruned** (manual deletion only, typed confirmation).
- **The filesystem is the source of truth for backup existence:** `backup_history` is a
  cache re-synced by scanning `storage/backups/*/meta.json` — so a restore that resets
  the database to an older state still lists every backup file that exists on disk
  (including the emergency backup created moments before the restore).
- Restore is **disaster recovery, not undo** — it is an explicit point-in-time
  replacement: upload or pick stored backup → validate structure + meta.json version
  compatibility → big warning + **typed confirmation ("RESTORE")** explaining that
  everything after the backup date moves into the emergency archive → **maintenance
  mode on first, then the same hard DB write gate as §9 step 4b** (`write_locked=1` +
  exclusive gate acquisition = provably drained writers) → create an **automatic
  emergency backup of the current state** (`kind='emergency'`, never auto-pruned,
  listed via the filesystem re-sync above — INV-001: no history is ever lost; anything
  newer than the restored dump lives on in that archive, and the UI says exactly that)
  → full drop/recreate from dump + file restore → migrations up to current code →
  health check. Failure path: re-apply the emergency backup. Zip-slip protected; never
  blind-extract; MariaDB DDL is non-transactional so restore only ever runs behind
  maintenance mode with the emergency backup as the recovery point.

## 11. UI architecture & themes

- **Design tokens** (CSS custom properties) per theme: colors, radii, shadows, fonts.
  Shared component CSS (cards, buttons, bars, badges, toasts) consumes tokens.
- **Theme = data + assets**, no logic: `public-assets/themes/<key>/theme.json`
  (terminology keys, level titles, world stage definitions, character keys, palette,
  sound set, FX intensity) + SVG layers + icons. `ThemeService` validates and exposes.
  Adding a theme later = new folder, zero core changes.
- **World progression:** layered original SVG scenes built from flat-illustration
  primitives — gradient sky → tinted silhouette layers (atmospheric perspective) → hero
  structure with tier groups `<g id="tier-N">` toggled by level class (camp → village →
  castle → kingdom; street pitch → training ground → small stadium → arena →
  championship arena). 4–6 colors per scene derived from the sky hue, consistent light
  direction, CSS time-of-day sky variants. Subtle parallax (10–30 px, one rAF handler);
  fully static under reduced motion. Decorative SVGs `aria-hidden="true"`.
- **Child home** is the flagship screen: world scene, character, level ring, XP bar,
  Coin counter, big game-styled nav buttons (Sidequests, Rewards, Achievements, Journal,
  World). Celebration engine: queue of pending celebration events (level-up, achievement,
  approval since last visit) → confetti/fanfare once, marked seen (no animation fatigue).
- **Parent UI:** same brand tokens, utilitarian layout, mobile-first, ≤2 taps from open
  → child → template award. Approval center with one-tap approve.
- Empty states, game copy (de default) per brief; accessibility: focus states, contrast
  ≥4.5:1 for text, 44px touch targets, reduced-motion, alt text.

## 12. PWA

`manifest` served dynamically (correct base path + theme icons), `start_url: "./"`,
`scope: "./"`, standalone display, maskable icons. **`sw.js` served from the app root**
(SW scope = the directory it is served from → subdirectory installs just work) and
registered with a relative path. The service worker caches **only static shell assets**
(CSS/JS/fonts/theme SVGs/icons, versioned cache name) **and one public offline page**.
Authenticated HTML and JSON are **never cached** — navigations go to the network and
fall back to the static offline page only, so no parent/child content can leak across
logout or device users. POSTs never cached. **No offline mutations.** Offline page:
"The kingdom sleeps — no connection."

## 13. Localization

`lang/de.php` complete from day one (default), `en.php` complete, fr/it stubs falling
back to de… **decision:** ship de + en complete, fr + it machine-translated and marked
for review. All user-facing strings through `t()`; per-theme terminology overrides layer
on top (theme provides translation keys, not hardcoded words).

## 14. Testing strategy

- **Unit (PHPUnit):** LevelService curve, LedgerService math/guards, validation,
  ThemeService, url()/base-path, i18n fallback, updater manifest validation, zip path
  validation, backup meta validation.
- **Integration (PHPUnit + real MariaDB):** ledger atomicity (two concurrent
  connections: reservation race, double-approve, first-come claim slot), migrations
  up/down + **concurrent migration runs**, installer service, auth throttling +
  **authorization matrix** (every route × role), **stored-XSS round-trips** (hostile
  titles/comments rendered escaped in every context), repositories, backup
  dump/restore round-trip, **updater interruption at EVERY journal-step boundary** —
  after preflight, download, verify, backup, maintenance-on, extract, each individual
  swap rename, migrate, health-check and finish (kill at each → resume and rollback
  both proven), **ZIP hostile cases** (zip-slip names, symlink entries, entry-count /
  per-entry / cumulative / ratio bomb limits, manifest mismatch).
- **E2E (Playwright):** installer happy path + DB failure + lock + setup-token gate;
  parent flows (login, quick award, template create/use, sidequest lifecycle,
  suggestion, reward, milestone); child flows (PIN login, QR login, accept/complete
  sidequest, request reward, journal, theme switch); **logout cache isolation** (after
  logout, back button/SW serve no authenticated content); **no-rewrite routing**
  (`?r=` fallback with mod_rewrite disabled); **subdirectory install** (app under
  `/family/`); updater simulated via local fixture release server (checksum fail,
  success, migration fail → rollback). Screenshots job produces all
  `docs/screenshots/*` from polished demo data at consistent viewports (390×844
  mobile, 1280×800 desktop).
- Coverage target ≥80% on `app/Domain` + `app/Core`; CI enforces.
- Visual QA step: I inspect every screenshot against RULE #1 before calling a UI phase
  done; Codex reviews UI implementation too.

## 15. CI & release pipeline

- `ci.yml` (PR/push): php -l over tree, PHPUnit (services: mariadb) on a **PHP matrix:
  8.2 and 8.3** (the 8.2 job is the compatibility gate), Playwright E2E against the
  docker compose stack, plus the no-rewrite and subdirectory E2E variants, artifact
  screenshots.
- `release.yml` (tag `v*`): validate tag == VERSION == CHANGELOG entry, run full tests,
  `scripts/build-release.php` → assembles the package from an **explicit allowlist
  manifest** (never a "strip unwanted" blocklist): `index.php`, `update.php`, **all
  `.htaccess` files as first-class artifacts**, `app/`, `views/`, `public-assets/`,
  `lang/`, `sw.js`, `VERSION`, `config/config.sample.php`, `config/CAN_INSTALL`,
  docs for end users; generates `release.json` manifest (version, min_php,
  update_from, per-file SHA256, removed[] list), ZIP + `.sha256`, creates the GitHub
  Release with both assets + release notes from CHANGELOG.
- **Clean-install gate:** CI job that takes the built ZIP, unzips into fresh
  **php:8.2-apache and php:8.3-apache** + MariaDB stacks, **asserts the `.htaccess`
  files are present and the protection probes pass**, walks the installer via
  Playwright, and smoke-tests parent+child login. A release only exists if the ZIP
  installs on both PHP versions.

## 16. Security summary (threat model)

- **Auth surface:** parent login (credentials), child PIN (low entropy → strict
  throttle + no remote hints), QR tokens (high entropy, hashed at rest, revocable).
- **Untrusted input:** all forms/JSON (validated centrally, fail-fast), uploaded backup
  archives (structure validation, zip-slip guards, size limits), theme/lang keys
  (whitelist), GitHub release payloads (checksum verification; JSON schema check).
- **Data sensitivity:** family activity data + password hashes + DB creds in
  config.php (outside web-readable paths via .htaccess + guard; installer verifies).
- **Blast radius:** single family, self-hosted; worst case = that family's data.
  Mitigations: prepared statements everywhere, output escaping via `e()`, CSRF on all
  writes, session fixation protection, rate limiting, no stack traces in prod (logged to
  `storage/logs/` with rotation), audit_log for admin actions, secrets never in repo,
  diagnostics export sanitized (no creds/tokens/hashes).

## 17. Demo environment & data

`scripts/seed-demo.php` (installer checkbox "Install demo family data" calls the same
seeder): family "Familie Barlocci-Demo", parents Alex + Sam (password documented in
docs/DEVELOPMENT.md, demo-only), children **Emma** (Fantasy, Level 7, 245 Coins, 620 XP,
Switch-2 milestone 340/1000, achievements, pending sidequest approval) and **Noah**
(Football, Level 5, 182 Coins, stadium progression, pending reward request), rich
Journal history (~40 entries over weeks), templates, sidequests in all states, rewards.
Seeder is idempotent, refuses to run on non-empty non-demo DB, never part of the release
unless invoked explicitly in the installer step.

Local demo runs via `docker/compose.yaml` (app on 8090, MariaDB internal) and stays up
after the project completes. Final handover: URL, demo logins, DB config, start command.

## 18. Delivery milestones & Codex checkpoints

Order follows the brief's phases; **Codex reviews at every milestone marked ©**, recorded
in docs/CODEX_REVIEWS.md.

## Teilaufgaben

1. **T1 — Dev environment + app skeleton:** docker compose (php:8.3-apache + MariaDB,
   port 8090), front controller, router, config, View/e(), error handling + logging,
   health route, base .htaccess; PHPUnit wired in container; first tests green. ©(with T2)
2. **T2 — DB layer + migration runner + full initial schema** (§4) with up/down,
   integration-tested against MariaDB. © (schema review)
3. **T3 — Installer wizard** (§8) incl. system checks, lock, atomic config write,
   demo-data hook (seeder arrives T13); E2E: fresh install, bad DB, locked. © (security)
4. **T4 — Auth & permissions:** parent login/logout/session/CSRF/throttling, child
   PIN + QR token login, route guards, audit_log. © (security)
5. **T5 — Family management:** parents CRUD, children CRUD (archive, never delete),
   settings pages skeleton, QR regeneration.
6. **T6 — Ledger + XP + levels** (§6): LedgerService, balance/reservation math, level
   curve, race/idempotency integration tests. © (correctness — critical)
7. **T7 — Templates + quick award:** template CRUD/favorites, one-tap award flow,
   parent child-screen speed path (<5 s), negative templates, optional confirm.
8. **T8 — Sidequests:** CRUD, types/recurrence, ownership modes, atomic claiming,
   child accept/complete, parent approve/modify/reject, expiry sweep (lazy, on-request —
   no cron). © (concurrency)
9. **T9 — Suggestions:** child submit, parent approve/modify/reject, history.
10. **T10 — Rewards:** catalog, redemption with atomic reservation, custom requests,
    time-based metadata, approval center v1. © (reservation correctness)
11. **T11 — Milestones + wishlist:** progress, claim modes, milestone requests.
12. **T12 — Achievements + notifications + Journal:** rule engine (transactional
    metrics), unlock animations queue, in-app notifications, Journal with filters.
13. **T13 — Demo seeder + first-run onboarding** (§17 + short parent onboarding flow).
14. **T14 — Fantasy theme + child game home** (flagship UI): tokens, world SVG scenes,
    character, celebration engine, sounds; screenshots + visual QA. © (UI)
15. **T15 — Football theme** (reusing theme architecture; proves extensibility).
16. **T16 — PWA** (§12) + performance pass (payload budget, query counts).
17. **T17 — Backups/restore** (§10) + system status + diagnostics. © (security)
18. **T18 — Updater** (§9) end-to-end with fixture releases + rollback tests. © (deep)
19. **T19 — CI + release builder + clean-install gate** (§15). © (release safety)
20. **T20 — Full E2E + screenshot suite + docs polish + final quality check** (the
    brief's 10 questions, answered by Claude AND Codex; fix findings; final demo
    handover). ©

Each Teilaufgabe: failing tests first (RED→GREEN), immutability/coding standards,
`/verify`, Codex impl review, Playwright validation where UI, vault docs update, then
commit on the feature branch.

## 19. Risks

| Risk | Mitigation |
|---|---|
| Updater bricks an installation | staged steps, checksum, auto pre-update backup, rollback, clean-install CI gate, deep Codex reviews |
| Race conditions in economy | row locks + status-guard UPDATEs + idempotency keys + dedicated concurrency integration tests |
| Shared-hosting variance (no mod_rewrite, open_basedir, low limits) | `?r=` routing fallback, self-tests in installer/system status, stepped updater, conservative PHP requirements |
| Design drifts into "admin software" | RULE #1 gate: screenshot inspection every UI milestone + Codex UI review |
| Scope explosion | Out-of-scope list above; drive-by findings → GitHub issues, never widen the diff |
| PHP-only DB dump edge cases (views, exotic types) | schema is fully known/owned; dump covers exactly our schema; round-trip restore test in CI |
| Child PIN brute force | per-child + per-IP throttle, parent notification on lockout, QR alternative |

## 20. Acceptance checklist (project-level)

- [ ] All 10 final-quality questions answered YES by Claude and Codex
- [ ] `docker compose up` demo at http://localhost:8090 with demo family, both themes
- [ ] Fresh install from **built release ZIP** passes E2E installer test
- [ ] Update from fixture vN → vN+1 with migration + rollback test passes
- [ ] ≥80% coverage on Domain/Core; all Playwright suites green
- [ ] All `docs/screenshots/*` generated and visually QA'd
- [ ] Full docs set present; CHANGELOG + VERSION consistent; no secrets in repo
