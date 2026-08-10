# Persistent login for parents and children, with re-auth for sensitive operations

> Spec 2026-08-10 · issue `000` (direct operator request) · branch `feat/000-persistent-login`
> Base `cf9381e` (v0.1.3)

## Goal

Operator: *"I want cookies, so that parents (and kids) who are logged in are logged in forever
(without always typing in username and password). So make the cookie banner and the cookie
function, so this works forever."*

Two operator decisions were taken before planning (they change the shape of the work, so they
were asked rather than assumed):

1. **Parents stay logged in for everyday use, but Backups, Restore and the Updater re-ask for the
   password.** A permanent parent cookie otherwise means permanent access to irreversible
   operations for anyone holding an unlocked device.
2. **The cookie notice is informational with a dismiss**, not an accept/decline gate. For a
   self-hosted family app whose only cookies are its own login cookies, consent is not legally
   required, and offering a "decline" that would break login would be a false choice.

## Baseline (measured, not assumed)

`.claude/cache/reproduction.md` — status **BASELINE-CONFIRMED**. Login issues exactly one cookie:

```
Set-Cookie: fc_session=…; path=/; HttpOnly; SameSite=Lax
```

No `Expires`, no `Max-Age`. **Three** independent mechanisms end a login, so a fix that addresses
only the cookie would still log people out:

| # | Mechanism | Measured value |
|---|---|---|
| 1 | `session.cookie_lifetime` | `0` — `fc_session` dies when the browser closes |
| 2 | `session.gc_maxlifetime` | `1440` — server-side session collectable after **24 minutes** idle |
| 3 | `session.save_handler` / `save_path` | `files` / default `/tmp` — swept by shared hosts |

Identity is only `$_SESSION['auth_parent_id']` / `['auth_child_id']` (`app/Core/Auth.php:14-15`),
so when the session goes, identity goes. There is no token table for principals: `auth_tokens` is
**child-only** (`child_id BIGINT UNSIGNED NOT NULL`, FK to `children`, no `user_id`, no
`expires_at` — `001_initial_schema.php:58-71`), so it cannot carry parent sessions.

This is why the fix is a **separate credential store**, not a longer session cookie: raising
`cookie_lifetime` alone still loses the session to (2) and (3), both of which are outside our
control on shared hosting.

## Surgical scope

Limited to exactly the operator's request: stay logged in on a device without retyping
credentials, plus the cookie notice, plus the re-auth carve-out the operator chose. No change to
how passwords or PINs are verified, no change to throttling, no new user management.

### Explicitly OUT of scope

- **Changing `session.gc_maxlifetime` / `cookie_lifetime` / `save_path`.** Tempting and useless:
  shared hosts override or sweep them (INV-003). The remember cookie makes session longevity
  irrelevant, which is the point.
- **A "remember me" checkbox.** The operator asked for "logged in forever once they are logged
  in" — always on. A checkbox is a different product.
- **Device/session management UI** (list devices, revoke one). Real follow-up value; not asked
  for. Logout already revokes, and that covers the stated need. → file an issue.
- **Two-factor auth, password change, account recovery.** Not asked for.
- **Re-auth for non-destructive parent actions** (awarding coins, approvals, children,
  templates). The operator explicitly chose to keep those frictionless.
- **Touching `auth_tokens` / the QR flow.** A child's QR login is a separate credential with its
  own lifecycle; this change reuses its *conventions*, not its table.
- **Issue #4 (`?r=` misdetection), #5, #10.** Unrelated; #4 only constrains the cookie `path`.

## Invariants

| Id | Verdict |
|---|---|
| **INV-003** — plain shared hosting | **Preserved, and it drives two decisions.** The token store is MariaDB (no Redis, no files); expiry is pruned **opportunistically in request flow** (the documented `login_attempts` pattern, `AuthService.php:196-202`) because there is no cron. The cookie `path` is derived from `BasePath` exactly as `Session::start()` does (`Session.php:20`), so subdirectory installs work; `?r=` mode is unaffected because cookies are path-based, not route-based. |
| **INV-006** — emitted CSS/JS carries the version token | **Preserved.** The cookie notice adds **no new JS file** (it is a CSRF-protected POST form, which also keeps it working with JavaScript disabled and satisfies the CSP at `index.php:131`, which forbids inline script). Its CSS goes into the existing `public-assets/css/app.css`, already emitted via `asset()`. `tests/Unit/ServiceWorkerShellTest.php` therefore stays green with no shell change. |
| **INV-001** — history is never deleted | **Preserved.** `remember_tokens` is an **operational** table, in the same documented class as `login_attempts` / `audit_log` (`001_initial_schema.php:8-13`, `WriteGate.php:14-15`), so pruning expired rows is explicitly permitted. No history table is touched; no Journal row is affected. |
| **INV-002** — Coins/XP only through the ledger | **Preserved.** No domain/ledger code in the diff. |
| **INV-005** — `ops.lock` mutations go through the flock mutex | **Preserved.** The re-auth guard wraps ops routes *before* the controller runs; no lock code, no `ops.lock` access, and no change inside `OpsController`'s locking methods. |
| **INV-004** — product name | Preserved. |

## Prior issues considered

`.claude/cache/prior-issues-considered.md`. The decisive item is commit `4fec42e` (T4 auth),
whose guarantees this change must not break:

- *"route guards revalidate LIVE eligibility (deactivated parent / archived child lose access
  immediately)"* → **a stored cookie must never resurrect a deactivated parent or an archived
  child.** Enforced in the resolve query itself, not only in the route guard.
- *"One principal per session; regeneration + CSRF rotation on every privilege change"* → cookie
  restore goes through `Auth::loginParent()` / `Auth::loginChild()`, never by writing `$_SESSION`.
- *"QR tokens: 32-byte base64url, sha256 at rest, strict shape check, regeneration revokes
  predecessors"* → the remember token copies this shape exactly.
- *"per-IP window counts failures ONLY and never resets"* → see the throttling decision in the
  threat model; this is the one place the change deliberately does **not** reuse the pattern.

## What ships

### 1. `app/Database/Migrations/006_remember_tokens.php` (new)

Follows the exact file shape of `005_reward_request_key.php` (no namespace, returns
`static fn (Db $db) => new class($db) extends Migration`).

```sql
CREATE TABLE IF NOT EXISTS remember_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    principal_type VARCHAR(10) NOT NULL,          -- 'user' | 'child'
    user_id BIGINT UNSIGNED NULL,
    child_id BIGINT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL,                 -- sha256 of the current validator
    previous_hash CHAR(64) NULL,                  -- accepted during the rotation grace window
    rotated_at DATETIME NULL,
    label VARCHAR(100) NULL,                      -- coarse device hint, never the raw UA
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_remember_token_hash (token_hash),
    KEY ix_remember_previous (previous_hash),
    KEY ix_remember_user (user_id),
    KEY ix_remember_child (child_id),
    KEY ix_remember_expiry (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_remember_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE CASCADE,
    CONSTRAINT ck_remember_principal CHECK (
        (principal_type = 'user'  AND user_id IS NOT NULL AND child_id IS NULL)
     OR (principal_type = 'child' AND child_id IS NOT NULL AND user_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

The `CHECK` (Codex plan round 1, MEDIUM) makes "exactly one principal, and it matches
`principal_type`" a schema guarantee rather than a convention. MariaDB 10.11 enforces `CHECK`,
and a credential table is precisely where a half-populated row must be impossible: a row with
both ids set, or with `principal_type='user'` and only a `child_id`, would be an identity-confusion
bug waiting to be written.

`down()` drops the table. **`ON DELETE CASCADE`, deliberately differing from `auth_tokens`'
`RESTRICT`:** a credential must never outlive its principal or dangle. `RESTRICT` is right for
`auth_tokens` because a QR token is parent-managed content; a remember token is a pure
credential. Neither users nor children are hard-deleted today (`is_active` / `archived_at`), so
this changes no current behaviour — it only makes the wrong outcome impossible later.

`tests/Integration/MigratorTest.php::testDownRollsBackCleanly` asserts that after `rollbackAll()`
only `schema_migrations` remains, so a missing `down()` fails CI automatically.

### 2. `app/Domain/RememberService.php` (new)

```php
public function issue(string $principalType, int $principalId, ?string $label): string
public function resolve(string $token): ?array      // ['type' => 'user'|'child', 'id' => int]
public function revoke(string $token): void
public function revokeAllFor(string $principalType, int $principalId): void
```

- **Token shape** — identical to the reviewed QR convention
  (`AuthService.php:148`): `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')`
  → 43 chars base64url, 256 bits. Stored only as `hash('sha256', $token)`.
- **Strict shape check first** — `preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1` returns null
  before any DB access (`AuthService.php:121`). A garbage cookie costs one regex, not a query.
- **Lifetime — sliding, 400 days** (Codex plan round 1, MEDIUM). A fixed 10-year expiry was
  wrong twice over: Chromium caps persistent cookies at **~400 days** regardless of what the
  server asks for, so the promise would have been silently broken by the browser; and a fixed
  window expires an actively-used device. Instead `expires_at = now + 400 days` and **every
  successful rotation extends it**, re-setting the cookie with a fresh 400-day expiry. A family
  that opens the app at least once every 400 days is logged in indefinitely — which is the
  operator's actual requirement — while a device untouched for over a year falls out on its own.
  The browser cap is documented in `docs/DEVELOPMENT.md` so nobody "fixes" the number later.
- **Eligibility is part of the resolve query**, mirroring the route guards verbatim:
  `JOIN users u ON … u.is_active = 1 AND u.role = 'parent'` / `JOIN children c ON …
  c.archived_at IS NULL`. A deactivated parent's cookie resolves to nothing.
- **Rotation is a compare-and-swap, not a read-then-write** (Codex plan round 1, HIGH). The
  rotating `UPDATE` carries the observed hash in its own `WHERE`:

  **Every precondition rides inside the statement — including the ones that live in other
  tables** (Codex plan round 3, HIGH). Checking only `revoked_at` would leave the *winning* path
  with the same defect the losing path had: a token that expired, or a parent deactivated,
  between the lookup and the update would still match, get its expiry extended by 400 days, and
  authenticate an ineligible principal. MariaDB's multi-table `UPDATE … JOIN` lets one statement
  decide the whole question:

  ```sql
  UPDATE remember_tokens t
    LEFT JOIN users    u ON u.id = t.user_id
    LEFT JOIN children c ON c.id = t.child_id
       SET t.previous_hash = t.token_hash,
           t.token_hash    = ?,
           t.rotated_at    = UTC_TIMESTAMP(),
           t.last_used_at  = UTC_TIMESTAMP(),
           t.expires_at    = UTC_TIMESTAMP() + INTERVAL 400 DAY
     WHERE t.id = ? AND t.token_hash = ?
       AND t.revoked_at IS NULL
       AND t.expires_at > UTC_TIMESTAMP()
       AND (
             (t.principal_type = 'user'  AND u.is_active = 1 AND u.role = 'parent')
          OR (t.principal_type = 'child' AND c.archived_at IS NULL)
           )
  ```

  So `rowCount() === 1` now means *"the token was current, unrevoked, unexpired and its principal
  eligible at the instant of the write, and this request rotated it"* — a single atomic
  observation-and-action, which is exactly what the vault's 2026-08-10 round-4 lesson prescribes
  ("read once, decide once, act on that exact observation"). The earlier version inferred three of
  those four facts from a `SELECT` that had already gone stale.

  Without the CAS, two requests both matching the current hash would both "rotate", and the loser
  would hand the browser a token whose hash is no longer stored — a self-inflicted logout on the
  *next* visit, i.e. a failure that appears long after the code that caused it. Read-once,
  decide-once, act on that exact observation is the vault's 2026-08-10 round-4 lesson applied to
  this table.

  **`rowCount() === 0` is ambiguous and must never be read as "someone else rotated"**
  (Codex plan round 2, HIGH). The `WHERE` can also fail because the row was **revoked**
  (logout on another tab, theft detection, a restore's `revokeAll()`), **expired**, or **deleted**
  by pruning between the lookup and the update. Authenticating on a bare zero would therefore log
  in a credential that was concurrently revoked — defeating revocation precisely when it matters.
  So a failed CAS **re-reads the row and re-derives the verdict from that second observation**:

  | CAS result | Then | Outcome |
  |---|---|---|
  | `rowCount() === 1` | — | log in **and** `Set-Cookie` with the new token |
  | `rowCount() === 0` | re-read by id. Authenticate **only if** the row still exists, `revoked_at IS NULL`, `expires_at > now`, the principal is still eligible, **and** the presented hash now equals `previous_hash` with `rotated_at` inside the 60 s grace | log in, **no `Set-Cookie`** (a concurrent request already gave the browser the live token) |
  | `rowCount() === 0`, any of the above not true | — | **fail closed**: no login, clear the cookie |

  This makes the CAS-failure path and the grace path the *same* path, which is the point: the only
  reason to accept a superseded token is that a sibling request rotated it moments ago, and that
  claim is now verified against fresh state instead of inferred from an ambiguous row count.

- **Grace window: accept, but never re-issue** (Codex plan round 1, HIGH). A token matching
  `previous_hash` **within 60 s** of `rotated_at` is accepted, and the request explicitly does
  **not** rotate and does **not** send `Set-Cookie`. The reason is decisive: the service stores
  only hashes, so it *cannot* reconstruct the current plaintext token to hand back — re-issuing
  here could only mean re-installing the superseded token, which would overwrite the good cookie
  the winning request just set and cause a delayed lockout. The grace window exists so a cold
  page load's parallel requests (HTML + `navigation.js` + CSS) do not log the family out; it is
  the delayed-actor case the vault's 2026-08-10 concurrency lesson demands be played out, and it
  is pinned by a deterministic parallel-request test rather than by timing luck.
- **Reuse-after-grace = theft.** A token matching `previous_hash` **after** the grace window
  means two parties hold the same cookie. The whole token row is revoked, the request continues
  anonymously, and `AuditService` records `remember.reuse_detected`. Losing one device's login is
  the correct price for an actually-stolen cookie.
- **Opportunistic pruning** — `if (random_int(1, 100) === 1) DELETE … WHERE expires_at < now OR
  (revoked_at IS NOT NULL AND revoked_at < now - INTERVAL 30 DAY)`, copying the documented
  `login_attempts` pattern (`AuthService.php:196-202`) because there is no cron (INV-003).
- **Not wrapped in `WriteGate`.** `remember_tokens` is operational, exactly like `login_attempts`
  and `audit_log` (`WriteGate.php:14-15`). Wrapping it would make every page load take the write
  gate and would block logins during a backup/restore drain — turning a maintenance window into
  a lockout.

### 3. `app/Core/RememberCookie.php` (new)

Reads, writes and clears `fc_remember`. Attributes:

```php
setcookie('fc_remember', $token, [
    'expires'  => time() + self::LIFETIME,        // 10 years
    'path'     => (BasePath::get() ?: '/') === '/' ? '/' : BasePath::get() . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => Session::isHttps(),
]);
```

- `path` from `BasePath` — matching `Session.php:20`, **not** the hardcoded `'/'` that
  `OpsController`'s updater cookie uses. On a subdirectory install (`/familycastle/`, issue #4) a
  `/` cookie would be sent to every sibling app on the origin.
- `SameSite=Lax`, matching the session cookie. `Strict` would withhold the cookie on the first
  navigation from any external link, so the family would look logged out exactly when they follow
  a bookmark or a QR link — the symptom this change exists to remove. `Lax` still blocks
  cross-site POST, and every state-changing route is CSRF-protected regardless.
- `HttpOnly` always; `Secure` follows `Session::isHttps()` (the project's canonical source).

### 4. Session restore — one hook, in the one place identity is established

`app/routes.php:34`, inside `$requireInstalled`, immediately after `Session::start()`:

```php
Session::start();
RememberLogin::restore($lazyDb());   // no-op when a principal is already in the session
```

`$requireInstalled` wraps **every** route that has a session, including `/`, `/login` and
`/kid/login`, so one hook covers the whole app. `restore()`:

1. returns immediately if `Auth::parentId()` or `Auth::childId()` is already set (so the cost on
   a normal request is two array reads, no DB query);
2. returns if no `fc_remember` cookie;
3. resolves via `RememberService`; on failure **clears the cookie** and returns;
4. on success calls `Auth::loginParent()` / `Auth::loginChild()` — inheriting session
   regeneration and CSRF rotation — re-issues the rotated cookie, and audit-logs
   `parent.login_remembered` / `child.login_remembered`.

**Accepted consequence, stated rather than hidden:** a POST submitted from a page whose session
has since expired triggers a restore, and `Auth::login*()` rotates the CSRF token, so that
specific POST fails the CSRF check and shows "security check failed". The user is now logged in
and retries successfully. Failing closed on a stale form is the correct direction, and suppressing
the rotation would weaken session fixation protection for a cosmetic gain.

### 5. Issue on login, revoke on logout

- `AuthController::login()` (after `Auth::loginParent`) and `KidLoginController::pin()` / `qr()`
  (after `Auth::loginChild`) issue a token and set the cookie.
- `AuthController::logout()` revokes **that device's** token and clears the cookie. Logout means
  "this device forgets me"; it deliberately does not sign out the family's other devices.
- The guards' existing "not eligible → `Auth::logout()`" paths (`routes.php:50`, `:68`) must also
  clear the cookie, or a deactivated parent would be re-restored on the next request and land in
  a redirect loop. This is the single easiest thing to get wrong in this change.

### 6. Re-authentication for sensitive operations ("sudo mode")

- `$_SESSION['auth_sudo_at']` (unix ts) is set on **password** login and on successful re-auth.
  A cookie restore does **not** set it — that is the entire point.
- Window: **15 minutes** (`RememberLogin::SUDO_WINDOW`), matching `AuthService::WINDOW_MINUTES`
  so the app has one "recent" notion.
- New guard `$requireRecentAuth` in `routes.php`, composed exactly like the existing guards
  (`$requireRecentAuth($requireParent(...))`), applied to:

  | Route | Why |
  |---|---|
  | `POST /parent/settings/updates/start` | overwrites the whole installation |
  | `POST /parent/settings/updates/start-manual` | same, FTP chain |
  | `POST /parent/settings/backups/create` | takes the ops lock |
  | `POST /parent/settings/backups/{id}/delete` | irreversible |
  | `POST /parent/settings/backups/{id}/restore` | the most destructive path in the app |
  | `GET /parent/settings/backups/{id}/download` | streams the **entire database, config and uploads** as a zip (`OpsController.php:232-233`) — data exfiltration, and it is a GET with no CSRF |
  | `GET /parent/settings/diagnostics` | dumps environment + the last 40 log lines (`OpsController.php:643-649`) |

  The read-only pages (`/updates`, `/backups`, `/status`) and `POST …/updates/check` stay open, so
  a parent can still *look* without a password prompt. Including `download` and `diagnostics`
  extends the operator's "Backups / Restore / Updater" slightly: both disclose the full
  installation, both are GETs reachable by URL alone, and a permanent cookie is exactly what makes
  a bare GET dangerous. Flagged here rather than assumed.

- **POSTs are not replayed.** When sudo is stale on a POST the action is refused, a flash asks for
  confirmation, and the parent is sent to the re-auth form; afterwards they return to the *page*
  and click again. Storing and replaying a queued destructive POST across a re-auth is a far
  worse failure mode than one extra click.
- `GET /parent/confirm-password` + `POST /parent/confirm-password` render/verify. The form asks
  for a **password only — never a username.** Verification resolves the username from
  `Auth::parentId()`'s own row, calls `AuthService::attemptParentLogin()` with it (so throttling,
  dummy-hash timing equalisation and the audit trail are all inherited — **no second
  password-checking code path**), and then **asserts the returned user id is identical to
  `Auth::parentId()`**.

  That last assertion is the point (Codex plan round 1, HIGH): without binding the credential to
  the *currently authenticated* principal, a second parent's valid password would grant sudo to
  someone else's session — either a silent privilege grant or an identity swap, on exactly the
  routes that can wipe the installation. A single-parent family makes this unobservable today and
  guaranteed to be missed later, so it is asserted in code and pinned by test 22.
- The return target is a **server-side** value: the resolved path of the blocked request, stored
  in `$_SESSION['auth_sudo_return']` by the guard and validated on use to be one of the known
  parent paths. No user-supplied `?next=` parameter, so no open redirect.

### 6b. A database restore must not resurrect revoked tokens

**Codex plan round 1, HIGH — and a genuine hole the rest of the design could not close.**
`BackupService` dumps and restores every table it finds via `SHOW TABLES`
(`BackupService.php:287-289`), so `remember_tokens` rides along automatically. That is convenient
for the happy path and dangerous for the security path: restoring a snapshot taken *before* a
token was revoked — including revocation triggered by theft detection — puts the stolen
credential back into a live table, and the browser holding it is logged straight back in. Every
other mitigation in this plan is defeated by that one path.

Fix: at the end of a successful restore, `OpsController::restore()` calls
`RememberService::revokeAll()` (a single `UPDATE remember_tokens SET revoked_at =
UTC_TIMESTAMP() WHERE revoked_at IS NULL`). Everyone signs in once more after a restore. That is
a proportionate price for an operation that already puts the site into maintenance and rewrites
the database, and it is the only end-state that is safe regardless of what the snapshot contained.

Scope note: this touches `OpsController::restore()`, which the "out of scope" list otherwise keeps
away from. It is included because it is a direct security consequence of introducing the table —
shipping the table without it would knowingly leave the hole. It adds no lock handling and does
not alter the restore state machine, so **INV-005 is untouched**: the call sits after the
restore's own success path, outside every `ops.lock` critical section.

### 6c. Credential changes must revoke tokens — recorded, not implemented

Codex round 1 raised (HIGH) that a password change must call `revokeAllFor()`. Verified against
the code: **there is no password-change or reset feature today.** `grep` over `app/Http/` finds a
password only in the installer (`InstallController.php:204-212`) and in
`AuthService::attemptParentLogin()`'s transparent `needs_rehash` upgrade — which is not a
credential change by the user and must not sign anyone out.

So there is nothing to fix now, and inventing a password-change screen here would be scope creep.
Instead the constraint is written into the vault as part of **INV-007** with `password` in its
Area keywords, so the day someone adds password change or reset, the rule is injected into that
plan automatically. That is the mechanism the vault exists for; a comment in a file nobody will
open is not.

### 7. Cookie notice

- `views/partials/_cookie_notice.php`, included by `views/layouts/parent.php`,
  `views/layouts/kid.php` and `views/layouts/auth.php` via the repo's existing partial convention
  (`require __DIR__ . '/../partials/_cookie_notice.php';`).
- **Not** in `views/layouts/install.php`: that layout does not load `app.css`
  (`install.php:7` loads only `install.css`), so the notice would render unstyled — and there is
  no login cookie to explain during installation anyway.
- Shown unless `$_COOKIE['fc_cookie_notice'] === '1'`. Dismiss is a CSRF-protected POST to
  `/cookie-notice`, which sets a 10-year cookie and redirects back to the server-resolved current
  path. A form, not JavaScript: the CSP (`index.php:131`) forbids inline script, and this keeps
  the notice functional with JS disabled.
- New `.cookie-notice` rules in `public-assets/css/app.css`, next to `.flash` (`:124-126`), the
  only existing banner-ish component. Sticky bottom bar, safe-area aware, high contrast.
- Copy states plainly what is stored and why. New i18n keys `cookie.*` in `lang/de.php` and
  `lang/en.php`, in the established flat-array-with-group-comment style.

## Teilaufgaben

Each contains its own RED→GREEN cycle and ends with the suite green and the app consistent.

1. **Token store and service.** Migration `006`, `RememberService`, and
   `tests/Integration/RememberServiceTest.php` (issue/resolve/rotate/grace/theft/expiry/
   eligibility/pruning). *Shippable:* nothing calls it yet — pure addition, zero behaviour change.
2. **Cookie + restore + issue/revoke.** `RememberCookie`, `RememberLogin::restore()`, the
   `routes.php` hook, issuing on all three login paths, revoking on logout **and** on both
   guard-ineligibility paths. Plus `e2e/specs/persistent-login.spec.ts` proving survival across a
   context restart. *Shippable:* this is the feature the operator asked for.
3. **Sudo mode.** `$requireRecentAuth`, the confirm-password screen, and the seven route
   applications. *Shippable:* independently valuable hardening.
4. **Cookie notice.** Partial, CSS, route, i18n, layouts. *Shippable:* UI only.
5. **Version + changelog.** `VERSION` → `0.1.4`, CHANGELOG entry in end-user voice.
6. **Docs + vault.** `docs/DEVELOPMENT.md`, vault `ARCHITECTURE.md`, `DATABASE.md`, `FILE_MAP.md`,
   `CHANGELOG.md`, `LESSONS.md`, and **INV-007** (a remember cookie must never outrank live
   eligibility, and must never confer sudo).

## Edge cases

1. **Deactivated parent / archived child holding a cookie** — resolve joins on live eligibility,
   so it returns null; the cookie is cleared. Test 8/9.
2. **Concurrent requests on a cold load** — the 60 s grace window; without it the second parallel
   request logs the user out. Test 5.
3. **Genuine token theft** — reuse after the grace revokes the row and audit-logs. Test 6.
4. **Subdirectory install** — cookie `path` from `BasePath`. Test 12.
5. **`?r=` mode** — cookies are path-scoped, unaffected by routing mode; asserted anyway.
6. **Stale POST after session expiry** — CSRF rotation rejects that one submission; documented
   in §4 and asserted so the behaviour is deliberate rather than discovered.
7. **Both a session and a cookie present** — `restore()` returns immediately; no DB query, no
   rotation.
8. **Child logs in on a parent's device** — `Auth::loginChild()` already clears the parent key
   (one principal per session); the new cookie replaces the old one, and the parent's token row
   is revoked so a discarded cookie cannot resurrect it.
9. **Backup/restore round-trip** — `BackupService` enumerates tables via `SHOW TABLES`
   (`BackupService.php:287-289`), so `remember_tokens` is included automatically. Restoring an old
   snapshot restores old tokens; they remain valid, which is correct (same devices, same family).
10. **Migration rollback** — `MigratorTest::testDownRollsBackCleanly` covers it generically.
11. **Clock skew / `expires_at` in the past** — resolve filters `expires_at > UTC_TIMESTAMP()`.
12. **Forged-cookie flood** — see the threat model: deliberately *not* recorded in the shared IP
    throttle window.

## Threat model

**Auth surface:** this change adds a second way to become authenticated, so it is the highest-risk
change since T4. Mitigations, each mapped to a test:

- **Token strength** — 256 bits from `random_bytes`, base64url, never logged, never in a URL,
  never in `document.cookie` (HttpOnly). Guessing is infeasible; there is nothing to enumerate.
- **At rest** — only `hash('sha256', $token)` is stored, so a database leak (or a downloaded
  backup) yields no usable cookie. This is also why `download` now needs re-auth.
- **Theft** — rotation on every restore plus reuse-after-grace detection bounds a stolen cookie's
  usefulness and leaves an audit record.
- **Privilege** — a restored session is *not* sudo. The irreversible operations still need the
  password, so a stolen cookie cannot wipe or exfiltrate the installation.
- **Throttling — the one deliberate divergence from the T4 pattern.** Failed remember-token
  attempts are **not** written to `login_attempts`. The IP window counts failures and never resets
  (`AuthService.php:183-188`), so recording them would let anyone lock a whole family out of
  logging in by sending garbage `fc_remember` cookies from behind the same NAT — converting a
  brute-force defence into a denial-of-service. Since the token is 256-bit and shape-checked
  before any query, there is no guessing surface to throttle. Theft signals go to `audit_log`
  instead. This is a reasoned exception to the vault's 2026-08-09 auth lesson, not an oversight.
- **Session fixation** — restore goes through `Auth::login*()`, which regenerates the session id
  and rotates CSRF.
- **CSRF** — unchanged; `SameSite=Lax` plus the existing synchronizer tokens.
- **Untrusted input** — the cookie value (shape-checked, then only ever used as a hash input) and
  the dismiss POST (CSRF-protected, no user-controlled redirect target).

**Data sensitivity:** the table holds no personal data beyond principal ids and a coarse device
label. **Blast radius if wrong:** an over-permissive bug means a device stays logged in when it
should not — bounded by live-eligibility checks and by sudo mode on everything irreversible. An
over-strict bug means users retype their password, i.e. today's behaviour.

## Lessons followed (vault matches on the files this change touches)

- **Follows 2026-08-10 "Updater/restore: every failure path needs a decided end-state (open,
  reverted, or fail-closed)".** The lesson is about ops code, but its rule — *enumerate every
  failure point and write down which end-state it lands in* — is exactly what an auth path needs,
  so it is applied here rather than waved off:

  | Failure point | Decided end-state |
  |---|---|
  | Cookie malformed / unknown / expired | **Anonymous, fail-closed.** Clear the cookie, continue the request unauthenticated. Never a 500. |
  | Principal no longer eligible (deactivated / archived) | **Anonymous + revoke.** Delete the token, clear the cookie, so the next request does not retry. |
  | Reuse after the grace window (theft signal) | **Anonymous + revoke the whole row + audit.** Both parties must re-authenticate. |
  | Rotation `UPDATE` fails (DB error mid-restore) | **Fail closed for this request**: do not log the user in with a token whose rotation did not persist, clear the cookie, continue anonymously. The alternative — session granted, cookie stale — is the split-brain the lesson warns about. |
  | `issue()` fails during login | **Login still succeeds**, no cookie is set. Persistence is decoration on top of a successful password login and must never fail the primary operation (the 2026-08-10 demo-seeding lesson, same shape). |
  | Sudo re-auth fails | **Refuse the operation.** Never fall through to the controller. |

- **Follows 2026-08-10 "A shipped fix the browser never downloads is not a shipped fix" /
  INV-006.** The cookie notice deliberately adds **no new JS file** and puts its CSS in the
  already-versioned `app.css`, so it cannot become the next invisible-because-cached change, and
  `ServiceWorkerShellTest`'s HTML↔shell pairing needs no new exemption. Also applied at the level
  the lesson really teaches — *verify what the browser actually received*: acceptance is a real
  browser context restart (test 13), not an assertion that the server sent a `Set-Cookie` header.

- **Follows 2026-08-10 "Concurrency fixes that 'look atomic' need a second adversarial pass".**
  The delayed actor is played out explicitly: two parallel requests from one cold page load, the
  second carrying a token the first has just rotated away. That is why the grace window exists and
  why it is a named test (5) rather than a hopeful comment. The re-run actor is played out too: a
  token presented twice after the grace is treated as theft, not as a retry.

- **Follows 2026-08-09 "Auth review: IP windows must never reset on success"** — with one
  *reasoned* divergence, stated in the threat model rather than silently taken: remember-token
  failures are not recorded in `login_attempts`, because the never-resetting IP window would turn
  a forged-cookie flood into a family-wide login lockout. Password re-auth in sudo mode goes
  through `AuthService::attemptParentLogin()` and therefore keeps the full T4 throttling.

- **Follows 2026-08-10 "Idempotent-retry shortcuts must prove identity, not just matching keys".**
  A token is accepted only on `hash_equals`-grade equality of the stored sha256 — never on a
  selector or a principal id alone.

- **Does not apply: "File modes are program state".** No deploy/extract code is touched. The new
  files land under `app/` and `views/`, which `fc_chmod_tree()` already normalises to 0755/0644
  before the swap and which `tests/e2e-updater.sh:195-227` already asserts.

## Tests

**Integration — `RememberServiceTest`** (MariaDB, never SQLite):
1. `issue()` returns a 43-char base64url token and stores only its sha256.
2. `resolve()` returns the parent for a fresh token.
3. `resolve()` returns the child for a fresh child token.
4. `resolve()` rotates: the old token stops working after the grace window; the new one works.
5. **Grace:** immediately after rotation the previous token still resolves and does **not** rotate again.
6. **Theft:** the previous token used after the grace revokes the row; both tokens then fail; an `audit_log` row exists.
7. Malformed/empty/unknown tokens resolve to null without touching the DB beyond the shape check.
8. A token for a **deactivated** parent resolves to null.
9. A token for an **archived** child resolves to null.
10. An expired token resolves to null.
11. `revokeAllFor()` invalidates every token of that principal.
12. Pruning deletes expired rows and never touches live ones.
13. **Concurrent rotation (deterministic, not timing-based).** Resolve the same token twice
    without applying the first result's rotation to the second call — simulating two parallel
    requests. Exactly one call reports "rotated" (`rowCount()===1`); the other reports "already
    rotated" and returns a result carrying **no new cookie**. Both authenticate the same
    principal, and the token the winner issued still resolves afterwards. This is the CAS
    contract; without the assertion the loser could silently install a dead token.
14. **The grace path issues no cookie.** A resolve matching `previous_hash` inside the window
    returns a result whose "new token" is null, and `rotated_at` is unchanged.
15. **A restore revokes every token.** Seed live tokens, call `RememberService::revokeAll()`,
    assert none resolve — the unit-level half of §6b (the ops-level half is covered by the
    existing restore path being exercised in `tests/e2e-updater.sh`).
16. `expires_at` slides forward on a successful rotation.
17. The `CHECK` constraint rejects a row with both `user_id` and `child_id`, and one whose
    `principal_type` does not match the populated id.
18. **Revoke beats a losing CAS.** Resolve a token, and before applying the result revoke the row
    (simulating logout on another device, theft detection, or a restore). The CAS fails, the
    re-read finds `revoked_at`, and the result is **no login + clear cookie** — not a login.
19. **Expiry beats a losing CAS.** Same shape with `expires_at` moved into the past: fail closed.
20. **Ineligibility beats a losing CAS.** Same shape with the parent deactivated mid-flight:
    fail closed. Tests 18-20 are what stop `rowCount()===0` from ever being read as consent.
21. **Expiry beats a *winning* CAS.** Expire the row between the lookup and the update; the CAS
    must match **zero** rows (its `WHERE` carries `expires_at > UTC_TIMESTAMP()`), so no login and
    — critically — `expires_at` is **not** extended. Without the predicate the statement would
    have resurrected an expired credential by writing a new 400-day expiry onto it.
22. **Deactivation beats a *winning* CAS.** Deactivate the parent (or archive the child) between
    the lookup and the update; the CAS's joined eligibility predicate must match zero rows and the
    request must fail closed. Tests 21-22 cover the success path with the same rigour as 18-20
    cover the failure path — the asymmetry Codex round 3 caught.

**Unit — `RememberCookieTest`:** cookie name/flags/lifetime; `path` at domain root and under
`/family`; clearing sets an expiry in the past.

**Unit — `RememberCookieTest`** (18-20): cookie name/flags/lifetime; `path` at domain root and
under `/family`; clearing sets an expiry in the past.

**Unit — `SudoWindowTest`** (21): fresh password login is sudo; a cookie restore is not; sudo
expires after the window; re-auth renews it.

**Integration — re-auth identity binding** (22): with parent A authenticated, submitting parent
B's correct password does **not** grant sudo and does **not** change `Auth::parentId()`. Seeds a
second parent, which no existing test does.

**E2E — `e2e/specs/persistent-login.spec.ts`** (23-29; the behavioural proof, the rest is structural):
23. Parent logs in, the `fc_remember` cookie exists with `HttpOnly`, and a **new browser context
    seeded with only that cookie** lands on `/parent` already signed in. This is the actual
    "logged in forever" claim — asserted by discarding the session cookie, which is exactly what
    closing the browser does.
24. Same for a child (PIN login → new context → `/kid`).
25. Logout clears the cookie, and a new context carrying the old cookie value lands on `/login`.
26. A restored session may reach `/parent` and `/parent/settings/backups`, but
    `/parent/settings/diagnostics` redirects to the confirm-password screen.
27. After confirming the password, `/parent/settings/diagnostics` is reachable.
28. The cookie notice appears once, and after dismissing does not reappear on the next load.
29. `document.cookie` never exposes `fc_remember` (HttpOnly holds).

**29 tests total** — 17 integration on the service, 1 integration on identity binding, 4 unit,
7 e2e. (Codex plan round 1 correctly caught that the earlier "19" did not match its own list.)

**Non-regression:** the whole existing suite, especially `auth.spec.ts` — test 25 must not break
`'logout ends the parent session'`, and `tests/e2e-updater.sh` must stay green now that a restore
revokes tokens.

## Files changed

| File | Change |
|---|---|
| `app/Database/Migrations/006_remember_tokens.php` | **new** — table + `down()` |
| `app/Domain/RememberService.php` | **new** — issue/resolve/rotate/revoke/prune |
| `app/Core/RememberCookie.php` | **new** — cookie read/write/clear |
| `app/Core/RememberLogin.php` | **new** — `restore()` + sudo helpers |
| `app/routes.php` | restore hook in `$requireInstalled`; clear cookie on both ineligibility paths; `$requireRecentAuth`; confirm-password + cookie-notice routes |
| `app/Http/AuthController.php` | issue on login, revoke on logout, confirm-password actions |
| `app/Http/KidLoginController.php` | issue on PIN and QR login |
| `views/auth/confirm-password.php` | **new** |
| `views/partials/_cookie_notice.php` | **new** |
| `views/layouts/{parent,kid,auth}.php` | include the notice |
| `public-assets/css/app.css` | `.cookie-notice` |
| `lang/de.php`, `lang/en.php` | `cookie.*`, `auth.confirm_*` keys |
| `tests/Integration/RememberServiceTest.php` | **new** — tests 1-12 |
| `tests/Unit/RememberCookieTest.php`, `tests/Unit/SudoWindowTest.php` | **new** |
| `e2e/specs/persistent-login.spec.ts` | **new** — tests 13-19 |
| `.github/workflows/ci.yml` | register the new spec in the explicit e2e list |
| `VERSION`, `CHANGELOG.md`, `docs/DEVELOPMENT.md` | 0.1.4 + docs |

## Risks

| Risk | Mitigation |
|---|---|
| A stolen cookie grants permanent access | Sudo mode on every irreversible/exfiltrating route; rotation + theft detection; HttpOnly + Secure + `SameSite=Lax` |
| A cookie outlives a deactivated parent | Live eligibility inside the resolve query **and** cookie clearing on the guards' ineligibility paths (tests 8, 9) |
| Rotation logs users out on parallel requests | 60 s grace window, tested explicitly (test 5) — the single most likely field failure |
| The restore hook adds a DB query to every request | It returns before any query when a session principal exists; the query runs only on a cold load |
| A forged-cookie flood locks the family out | Failures deliberately excluded from the shared IP throttle window (threat model) |
| Cookie set at `/` leaks across a shared origin | `path` from `BasePath`, tested at both base paths (test 12) |
| Sudo prompts become annoying | Read-only ops pages stay open; 15-minute window; only 7 routes gated |

## Acceptance checklist

- [ ] Tests written first and observed RED, then GREEN, per Teilaufgabe
- [ ] Full PHPUnit suite green against MariaDB; coverage ≥80% on Domain+Core
- [ ] Playwright green on both projects; `auth.spec.ts` unbroken
- [ ] `tests/e2e-updater.sh` green (new table participates in backup/restore)
- [ ] Codex plan review CRITICAL=HIGH=MEDIUM=0, then a clean impl review
- [ ] `security-review` skill + security-reviewer agent on the auth surface
- [ ] Verified by hand in the browser: close the browser, reopen, still logged in
- [ ] Vault updated incl. INV-007
