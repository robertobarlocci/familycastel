# Updater must install code with web-servable file modes

- **Issue:** #000 (no GitHub issue — reported directly by the owner against production)
- **Lane:** Risky (self-updater; touches the live code swap on a production install)
- **Reproduction:** `.claude/cache/reproduction.md` — status REPRODUCED
- **Prior issues considered:** `.claude/cache/prior-issues-considered.md`

## Goal

After a parent clicks **Update now**, the new release must be downloaded, unpacked and
installed so that the site works exactly as it does after a fresh install — including
Apache being able to serve `public-assets/**` — and no user data may be touched.

Today the download, hash verification and unpacking are already correct (verified against
the real v0.1.1 artifact). The defect is the **file modes** the swap installs.

## Surgical scope

Limited to exactly the user's request: make the in-app (GitHub-chain) update install the
release with deterministic, web-servable file modes, and prove it with tests. Nothing else
in the updater's behaviour changes.

**Out of scope** (tempting, deliberately excluded — each filed as its own GitHub issue):

- The **manual FTP chain** (`start-manual` → `fc_step_manual_verify`). It neither downloads
  nor unpacks; the user's FTP client wrote those files and owns their modes. Separate issue.
- The **`pretty_urls` misdetection** on the production host (mod_rewrite is demonstrably
  active there, yet the install is in `?r=` mode). Cosmetic, unrelated. Separate issue.
- `Router::resolvePath()` treating any path ending in `/index.php` as the home route.
  Separate issue.
- Adding a permissions probe to `SystemCheck` / the parent system-status page. Would be
  useful, is not needed to fix the update. Separate issue.
- Re-testing or changing `scripts/build-release.php`. Proven correct for v0.1.1.

## Root cause

`update.php` never sets a mode explicitly. Every staged entry is created with an ambient
mode and the swap then `rename()`s the staged inode into the live tree, carrying that mode
with it:

| site | call | mode under umask 022 | under umask 077 |
|---|---|---|---|
| `update.php:544` | `mkdir($staging, 0770, true)` | 0750 | 0700 |
| `update.php:581` | `mkdir(dirname($dest), 0770, true)` — every staged dir | **0750** | **0700** |
| `update.php:583` | `file_put_contents($dest, …)` — every staged file | 0644 | **0600** |
| `update.php:702` | `rename($new, $live)` — the promotion | **carries the staging mode into the live tree** | |
| `update.php:882` | `@copy($stagedSelf, update.php.new)` then rename | 0644 | **0600** |
| `update.php:1038` | `@mkdir($dest, 0770, true)` in `fc_restore_removed` | 0750 | 0700 |

There is **no `chmod()` and no `umask()` anywhere in `update.php`**, and nothing copies the
pre-update live directory's mode onto its replacement.

On the production host PHP runs as the account owner while Apache serves static files as a
different user, so a 0750 directory is not traversable by the static-file server:
`RewriteCond %{REQUEST_FILENAME} !-f` evaluates true for every asset, the request is
rewritten to `index.php`, and the router answers 404 `text/html`. Hence "no CSS".

Under a 0777-umask host the same bug also makes the swapped **files** 0600 and would break
`index.php` itself — the current breakage is the mild version of this fault.

### Why no gate caught it

- `.github/workflows/release.yml:225-233` **does** assert `public-assets/css/app.css` and the
  dashboard's own `<link href>` return 200 — but only on the **install** path, where the tree
  came from `unzip` (0755) and never from PHP.
- `tests/e2e-updater.sh` exercises the real update, but over `php -S` (`:119`), where the PHP
  interpreter and the static handler are the same process and user — the mode is irrelevant
  by construction. It asserts no modes at all.
- The dev/CI Docker image is `php:8.3-apache` (mod_php): PHP and static serving are again the
  same uid. The split this bug needs is reproduced nowhere.

This is the counterpart the vault already predicted: *"every 'never touch X mid-flight' rule
needs a companion 'then when DOES X get updated?' answer"* (LESSONS 2026-08-10, final quality
check) and *"a real gate must navigate NATURALLY … fetch the page's own stylesheet href"*
(LESSONS 2026-08-10, fallback modes, round-2 addendum).

## The rule this change establishes

> Every filesystem entry the updater places into the **live tree** gets an explicit mode:
> **directories 0755, files 0644**. Never an ambient one.

`chmod()` is not subject to `umask`, so this is deterministic on every host. 0755/0644 is
precisely the state a fresh manual install produces — the release ZIP stores no Unix modes
(`build-release.php` never calls `setExternalAttributes*`), so `unzip` applies its own
defaults `0777 & ~umask` / `0666 & ~umask` = 0755/0644. **Update state ≡ install state.**

Not a security regression: `app/`, `views/`, `lang/` are protected by the root `.htaccess`
deny rule plus their own per-directory `Require all denied`, and `release.yml:148-164`
already probes exactly those paths against a 0755 tree on real Apache. `config/config.php`
(chmod 0640 by the installer) and `storage/` are **not** swap entries and are never touched.

## What ships

### 1. `fc_chmod_tree()` — new helper in `update.php`

```php
/**
 * Give every entry under $root an EXPLICIT web-servable mode: directories
 * 0755, files 0644 — the same state a fresh unzip-based install produces.
 * chmod() ignores umask, so the result does not depend on the host's ambient
 * umask (0770&~077 would otherwise ship 0700 directories Apache cannot enter).
 * Idempotent: safe to re-run on a resumed step.
 *
 * @return bool true only when EVERY chmod provably succeeded.
 */
function fc_chmod_tree(string $root): bool
```

- walks with `RecursiveIteratorIterator(..., SELF_FIRST)`, chmods `$root` itself too;
- **skips symlinks** (`$item->isLink()`) — `chmod()` follows them, and the extractor never
  creates any, so encountering one means something is wrong; do not follow it out of tree;
- returns `false` if any `chmod()` fails; never throws.

### 2. Call it on the staged tree — `fc_step_extract()`

At the end of `fc_step_extract()`, after the extraction loop and before `fc_advance('stage_verify')`:

```php
if (!fc_chmod_tree($staging)) {
    fc_abort('Could not set file permissions on the staged release.');
}
fc_log('Staged tree normalized to 0755/0644.');
```

**Why staging, not the live tree:** the swap promotes the staged inode by `rename()`, so
fixing staging fixes what lands live, and the live tree is never observed half-chmodded.
**Decided end-state on failure:** `fc_abort()` — pre-swap, nothing destructive has happened,
so it cleans staging and releases the lock (the "(a) nothing happened yet → reopen" case of
the LESSONS 2026-08-10 three-end-state rule).

### 3. The executor self-replacement — `fc_step_finish()`

`update.php:878-888` currently `copy()`s the new executor to `update.php.new` and renames it,
inheriting an ambient mode. If that lands 0600, Apache can no longer serve `/update.php` and
**every future update — including the manual FTP escape hatch — is dead**. Chmod the temp
copy before the rename, and treat a failed chmod like a failed copy:

```php
if (@copy($stagedSelf, $tmpSelf) && @chmod($tmpSelf, 0644) && @rename($tmpSelf, $liveSelf)) {
```

**Decided end-state on failure:** unchanged from today — unlink the temp file, log a warning,
keep the current working executor. Status quo, post-commit, non-fatal.

### 4. The rollback path — `fc_restore_removed()`

`update.php:1038` recreates directories **in the live tree** with `mkdir(..., 0770, true)`.
Restoring a retired file into a 0750 directory reintroduces the same fault on the rollback
path. Use `0755` and chmod explicitly (mkdir's mode is umask-masked, chmod's is not) —
but **only for directories this call actually creates**, and with the chmod's return value
folded into the existing aggregate `$ok`:

```php
if ($item->isDir()) {
    if (!is_dir($dest) && (!@mkdir($dest, 0755, true) || !@chmod($dest, 0755))) {
        $ok = false;
    }
    // A destination directory that already exists keeps its own mode: this is a
    // ROLLBACK path, and re-moding live directories the caller never created is
    // both out of scope and destructive of pre-update state.
} elseif (!file_exists($dest) && !@rename($item->getPathname(), $dest)) {
    $ok = false;
}
```

Two properties this must keep (both were wrong in the first draft of this spec and were
caught by the Codex plan review):

- **A failed chmod must fail the restore.** `$ok` feeds `fc_restore_swapped_entries()` →
  `fc_rollback_files()` / `fc_rollback_full()`, which call
  `fc_terminal_failure(..., reopen: false)` when it is false. Swallowing the chmod result
  with a bare `@chmod(...)` would let a half-restored tree reopen the site — precisely the
  failure mode of LESSONS 2026-08-10 "Ops round 3: recovery code is code too".
- **Pre-existing destination directories are not touched.**

Restored **files** are deliberately *not* chmodded: they are the original live inodes moved
into `previous/__removed__/` by the swap, so renaming them back restores their exact
pre-update mode, which is what a rollback must do.

The existing aggregate-`$ok` / fail-closed contract is otherwise unchanged.

### 5. The bootstrap self-heal — why the updater fix alone is not enough

`update.php` is deliberately excluded from the swap and is replaced **last**, post-commit
(`fc_step_finish`). So the update that *carries* this fix is driven by the **old, unfixed
executor**. Proven empirically, not reasoned: running the real e2e updater with the OLD
`update.php` installed in the sandbox and the FIXED one inside the release still produced
`app is 0700` and `app/Core/View.php is 0600`. Without a counterpart, the owner's **next**
click of "Update" breaks the site again and only the update after that is correct — which is
worthless for a product whose users have no shell (INV-003).

The only new code that runs during an old-executor update is: migrations
(`fc_step_migrate` → `fc_app_autoload()`), the `SettingsService::pruneRetention()` call in
`fc_step_finish` (whose exceptions are swallowed, so it cannot gate anything), and the new
`index.php` on the next request. A migration is the wrong tool — a permission repair is not
a schema change, and its `down()` could only be a no-op, which violates the project rule
"never write a migration without a rollback path". So:

> The new `index.php` performs a **release-scoped, one-time** file-mode self-heal before
> routing.

`app/Core/FileModeHeal.php` (new):

#### Design: probe the real property, keep no state at all

The first three drafts of this section used a persistent "already healed" marker keyed on the
release. Codex refuted each one in turn: keyed on the version string it went stale across a
rollback; keyed on the `VERSION` inode it had a swap-during-walk TOCTOU; re-checking the
identity after the walk still left an **ABA** window (identity A→B→A during the walk).

That is the exact pattern LESSONS 2026-08-10 "Ops round 6" describes: *"When a lock-protocol
fix needs a third clever refinement, the design is wrong… Observation and action must live
inside the same critical section."* The honest reading here is that the marker itself is the
problem — it is shared mutable state asserting a **claim about the past** ("this tree was
healed"), and every failure mode above is that claim going stale. The alternative used
instead is to hold no state and evaluate the **actual property, fresh, on every request**:

- **Fast path (steady state): one `stat()`, no marker, no lock, no walk.** Check that
  `public-assets/` is world-traversable. That is precisely the property whose absence broke
  production, tested directly rather than remembered.
- **The walk chmods `public-assets/` itself LAST**, after every other path in the allowlist
  has succeeded, so the probed bit is a **completion witness** rather than a *sample*. An
  interrupted walk leaves it unset and is simply redone (the heal is idempotent, so redoing
  it is free).
- **The heal never runs concurrently with a swap** — see below. This is the part that makes
  the witness sound, and it is exclusion, not cleverness.

##### Excluding the swap instead of out-racing it

Codex refuted four successive attempts to make this safe by construction (stale marker →
TOCTOU → ABA → "chmod the root last, which still targets whatever is at that path *now*, so
a swap mid-walk can heal tree A's descendants and then set tree B's root"). That last one is
unfixable by ordering: a path-based `chmod()` cannot be bound to the inode the walk actually
visited.

The project's own LESSONS 2026-08-10 "Ops round 6" names this exact trap — *"When a
lock-protocol fix needs a third clever refinement, the design is wrong… Observation and
action must live inside the same critical section."* So the heal stops racing the swap and is
**excluded** from it, using two signals that already exist and that this change does not
modify:

- **`storage/maintenance.flag`.** The updater sets it in `fc_step_maintenance_on` (step 4 of
  11) and clears it in `fc_step_finish` at the commit point — so extract, swap, migrate and
  health all happen inside that window. `index.php` already answers 503 on that flag
  **before** routing, so the heal, invoked *after* that check, is unreachable while any swap
  is in flight.
- **`storage/ops.lock`.** A second, independent signal covering the rollback tail and the
  parent restore flow. The heal skips while it exists.

Checking whether `ops.lock` exists is a read-only **observation**, never a create/rename/
delete, so INV-005 (every ops.lock *mutation* goes through the shared flock mutex) is
untouched — no lock code is edited by this change.

**Those two checks alone are check-then-act, and Codex round 7 was right to refuse them:** a
healer admitted a microsecond before `fc_step_maintenance_on` runs is still walking when the
swap lands. Existence checks cannot exclude an *already-admitted* actor. What excludes an
already-admitted actor is the thing built for exactly that job — the **writer drain**:

```php
fc_gate(true);        // ops_state.write_locked = 1  → new writers refused
fc_gate_drain();      // SELECT write_locked … FOR UPDATE → waits for in-flight writers
```

So **the heal becomes a writer**. When (and only when) the probe fails, it opens the same
transaction shape every other mutator uses:

```
BEGIN
SELECT write_locked FROM ops_state WHERE id = 1 FOR UPDATE
  write_locked = 1 → ROLLBACK, SKIP        (an ops operation owns the system)
  write_locked = 0 → walk the allowlist, then COMMIT
```

That is genuine mutual exclusion in both directions, and it needs no new primitive:

- a heal already in flight **holds the row**, so `fc_gate_drain()` blocks until the walk
  finishes — the swap cannot start underneath a walking healer;
- a heal starting after `fc_gate(true)` reads `write_locked = 1` and skips;
- the two are serialized by the database, not by a check-then-act window.

Cost is paid only on the rare heal path: the steady state is still one `stat()` and never
touches the database. If the database is unavailable or the app is not installed, the heal
**SKIPs** and logs — a file-mode problem must never escalate into taking the site down.

With the swap excluded, the only writers of these modes are the swap itself (which installs a
uniform tree by one atomic `rename()`) and the heal (descendants first, root last). Both
preserve the invariant **"`public-assets/` is world-traversable ⟹ everything under it is
healed"**, and a rollback restores an inode set that satisfied that invariant when the swap
parked it. That is what the one-`stat()` probe reads.

- Only when the probe fails does the heal take the lock and walk the allowlist.

Every refuted failure mode disappears rather than being patched:

| Refuted failure | Why it cannot occur now |
|---|---|
| Stale marker after rollback | No marker. A rollback that restores a broken tree fails the probe on the next request and is healed. |
| Swap-during-walk writes a marker for the wrong tree | Nothing is written. |
| ABA (A→B→A across the walk) | A partial heal simply fails the next request's probe and is redone. The heal is idempotent, so redoing it is free. |
| Corrupt `VERSION` steering a marker path | `VERSION` is not consulted at all. |

The cost is one `stat()` per request — less than the autoloader already spends — in
exchange for deleting the entire marker/identity/TOCTOU surface. The `.retry` cooldown is
kept, because it bounds the one case the probe cannot fix by itself: `chmod()` genuinely
failing (wrong owner), where re-walking on every request would burn CPU and flood the log.

<details>
<summary>Superseded marker design (kept for the record — do not implement)</summary>

- **Marker key = the deployment's identity, not its version string.**
  `sha1(FC_VERSION . '|' . filemtime(VERSION) . '|' . fileinode(VERSION))`, used as
  `storage/cache/file-modes-<key>.ok`. Keying on the version alone is unsound: `storage/`
  survives updates *and rollbacks*, so a rollback that restores an older `VERSION` would
  find that version's old `.ok` marker and skip a heal the restored tree may need (Codex
  round-2 MEDIUM). The swap moves a *new* `VERSION` inode in, so a release always gets a
  fresh key; a rollback moves the *original* inode back, so it recovers the original key —
  which is correct, because `chmod()` mutates the inode that the swap parked in
  `previous/` and later restored, i.e. a tree healed before the update is still healed
  after the rollback. Hashing also removes any path-injection concern from a corrupt
  `VERSION` file, since the key is always hex.
- **Fast path:** one `is_file()` on the `.ok` marker per request in the steady state — no
  parse, no lock, no walk.
- **Failure cooldown:** a `.retry` marker holds the last failed attempt. While it is
  younger than 60 s the heal returns "failed" immediately — no walk, no log. This bounds
  the retry cost that Codex flagged (round-2 MEDIUM: retrying on every request would mean
  repeated tree walks and unbounded log growth) while keeping recovery automatic.
- **Non-blocking lock:** `flock(LOCK_EX | LOCK_NB)` on `storage/cache/file-modes.lock`.
  A blocking lock here would serialize every concurrent public request behind one healer —
  a request convoy. If the lock is not obtained, another request is already healing: this
  request skips the heal and is served normally (at worst it renders unstyled once). The
  `.ok` marker is re-checked *inside* the lock, so a request that queued behind a
  just-finished healer does no redundant work.
- walks a **hard-coded allowlist** — `app`, `views`, `public-assets`, `lang` plus the root
  files `index.php`, `.htaccess`, `sw.js`, `offline.html`, `VERSION` — chmodding directories
  0755 and files 0644. `config/`, `storage/` and `update.php` are **never** touched, so
  `config.php` (0640), `install-state.json`, `setup-token.txt` and `auth-token` (0600) keep
  their tight modes;
- skips symlinks (`lstat` semantics via `isLink()`), never follows them;
- writes the `.ok` marker only when every chmod succeeded.

**The identity must be re-verified after the walk, not just recomputed under the lock.**
Codex round 3 found a TOCTOU hole in the first version of this design: the key is derived
from `VERSION`, but a swap can land *while the walk is running*. The healer would then chmod
the **new** tree and write `.ok` under the **old** key — and a later rollback restoring that
old, unhealed tree would find a matching `.ok` and skip the heal it needs. Re-checking `.ok`
inside the lock does not close this; only re-reading the identity does.

Resolution order, so every branch is cheap and decided:

1. `.ok` marker for the currently-observed key present → **OK**, continue (steady state,
   one `is_file()`). A key that is stale here merely misses and falls through to the lock.
2. `.retry` marker younger than 60 s → **FAILED**, no walk, no log.
3. `flock(LOCK_EX|LOCK_NB)` not obtained → **SKIP**: another request is healing; serve this
   request normally.
4. **Recompute the identity inside the lock** → `$before`. Re-check `.ok` for `$before` → **OK**.
5. Walk the allowlist and chmod.
6. **Recompute the identity again** → `$after`. If `$after !== $before` the tree was swapped
   under us: write **no** marker at all and return **SKIP**; the next request heals under the
   new identity. The `.ok` marker is therefore only ever written for an identity that was
   provably stable across the entire walk.
7. All chmods succeeded and the identity held → write `.ok` for `$before`, drop `.retry`,
   **OK**. Otherwise → touch `.retry`, log once, **FAILED**.

</details>

#### Resolution order as implemented

0. **Cheap exclusion** — invoked only after `index.php`'s existing `storage/maintenance.flag`
   check, and **SKIP** while `storage/ops.lock` exists. These two are an optimisation, not
   the guarantee: they keep the common case off the database. The guarantee is step 4a.
1. **Probe** — `public-assets/` world-traversable → **OK**, continue. One `stat()`; this is
   the steady state. Sound because step 5 sets that bit last (completion witness).
2. `.retry` marker younger than 60 s → **FAILED**, no walk, no log.
3. `flock(LOCK_EX | LOCK_NB)` on `storage/cache/file-modes.lock` not obtained → **SKIP**:
   another request is already healing; serve this request normally. Non-blocking on purpose —
   a blocking lock would queue every concurrent public request behind one healer.
4. Re-probe inside the lock (a healer that just finished may have fixed it) → **OK**.
4a. **Join the writer drain — this is the actual exclusion.**
   `BEGIN; SELECT write_locked FROM ops_state WHERE id = 1 FOR UPDATE`.
   `write_locked = 1` → `ROLLBACK`, **SKIP**. No database, or not installed → **SKIP** +
   log; a mode problem must never escalate into an outage. Otherwise hold the row for the
   whole of step 5 and `COMMIT` after it. `fc_gate_drain()` therefore blocks on a walking
   healer, and a healer starting after `fc_gate(true)` sees the lock and stands down.
5. Walk the allowlist, chmod directories 0755 and files 0644, skipping symlinks — with
   `public-assets/` itself **deferred to the very end** and set only if everything else
   succeeded. Ordering, not extra state, is what makes the probe a completion witness.
6. All chmods succeeded → delete `.retry`, **OK**. Otherwise → leave the witness bit unset,
   touch `.retry`, log once, **FAILED**.

Nothing asserts a claim about the past, so nothing can go stale: correctness is re-derived
from the filesystem on every request.

**Decided end-state on failure** (case (c) of the three-end-state rule): no `.ok` marker is
written and the request is answered with a 503 naming the manual step ("set 0755 on
directories and 0644 on files for app, views, public-assets and lang using your hosting file
manager"). The heal is **retried after the cooldown** rather than latched into a permanent
failed state: the parent has no shell and cannot clear a latched marker, whereas a
cooldown-bounded retry means the site recovers by itself the moment the modes are corrected
in the hosting file manager. `SKIP` is deliberately *not* a failure — a request that loses
the lock race is served normally, because one unstyled page render is strictly better than a
503, and the healer that holds the lock is about to fix it.

**Rejected: latching a permanent failed state** (Codex's first suggestion). It bounds the
retry cost, but the only actor who could clear the latch is the one who cannot: the
non-technical parent this product exists for (INV-003). The cooldown achieves the same cost
bound while keeping recovery automatic.

Once shipped, this also makes the manual FTP chain self-heal (issue #3), because the same
bootstrap runs no matter how the files arrived.

## Teilaufgaben

Executed strictly one after another; each is finished, tested and green before the next starts.

1. **T1 — RED: unit test for `fc_chmod_tree()`.**
   New `tests/Unit/UpdaterChmodTreeTest.php`. Uses the dormant library hook
   `update.php:1154` (`if (defined('FC_UPDATE_LIB')) return;`) — define `FC_UPDATE_LIB`, copy
   `update.php` into a temp root, `require` it, and call `fc_chmod_tree()` directly (a pure
   function: no `fc_ok`/`exit`, so no process isolation needed). Cases: nested dirs+files land
   0755/0644; **the same holds under `umask(077)`**; idempotent on a second run; returns
   `false` when a chmod cannot succeed; a symlink is skipped, not followed. Must fail to even
   load (undefined function) before T2.

2. **T2 — GREEN: implement `fc_chmod_tree()`** in `update.php`. T1 green.

3. **T3 — RED→GREEN: wire it into `fc_step_extract()`** and add the `fc_abort` failure path.

4. **T4 — RED→GREEN: `fc_step_finish()` executor chmod** (§3) and **`fc_restore_removed()`**
   (§4).

5. **T5 — RED: end-to-end mode + data-preservation assertions in `tests/e2e-updater.sh`.**
   Scenario 1 (happy path) gains:
   - the `php -S` sandbox server is started under **`umask 077`**, so every file the updater
     creates is ambient-0600/0700 — this makes the assertion prove *explicit* modes rather
     than a lucky default, and it is the assertion that fails on today's code;
   - after `finish`: every directory under `app/`, `views/`, `public-assets/`, `lang/` is
     `0755` and every file is `0644`; `index.php`, `.htaccess`, `sw.js`, `VERSION` are `0644`;
     `update.php` is `0644`;
   - **data preservation:** a sentinel written to `storage/uploads/keepme.txt` and to
     `config/keepme.txt` before the run is byte-identical after it (alongside the existing
     child-data and `settings` assertions).
   Verify RED on stock `update.php`, then GREEN with T2-T4.

6. **T6 — CI wiring.** `.github/workflows/ci.yml` and `release.yml` already run
   `tests/e2e-updater.sh`; confirm the umask leg runs there and that a mode regression fails
   the job. No new workflow.

7. **T7 — file the five out-of-scope findings as GitHub issues** (filed: #3, #4, #5, #6, #7)
   and reference them in the PR body. This is workflow §1 bookkeeping ("out-of-scope
   findings stay in their issues for later"), not implementation work: it adds **zero**
   lines to the diff and exists precisely so the fix stays surgical. Codex's plan review
   flagged it as scope creep; keeping it is the deliberate call, because the alternative is
   either silently dropping five real findings or widening this PR to fix them.

8. **T9 — RED: `tests/Unit/FileModeHealTest.php`.** Marker short-circuits the walk; a wrong
   tree is healed to 0755/0644; `config/`, `storage/` and `update.php` are untouched; a
   non-semver version is refused; symlinks skipped; idempotent; no marker written when a
   chmod fails.

9. **T10 — GREEN: `app/Core/FileModeHeal.php` + the call in `index.php`,** plus an e2e leg:
   run the updater with the OLD executor installed and the FIXED release, then assert the
   modes are correct **after one HTTP request to the front controller**. That is the exact
   scenario proven broken above and it must go green.

10. **T8 — vault update** — `CHANGELOG.md`, `LESSONS.md` (new dated entry: ambient modes are a
   correctness bug; a gate whose PHP and static-file server share a uid can never see it),
   `ARCHITECTURE.md` (the 0755/0644 rule), `FILE_MAP.md` if files were added.

## Edge cases

- **Ambient umask 077** — the case the unit test and the e2e leg both pin. `chmod()` ignores
  umask, so the result is identical to umask 022.
- **Resumed step.** `fc_step_extract` re-runs from scratch (`fc_rrmdir($staging)` at `:543`),
  so normalization re-runs too; `fc_chmod_tree()` is idempotent regardless.
- **Swap already partially done.** Untouched — normalization happens strictly before the
  first `rename()`, so no entry is ever promoted un-normalized.
- **Rollback.** `fc_restore_*` renames the ORIGINAL inodes back from `previous/`, restoring
  the pre-update modes exactly. Unchanged, and correct: rollback must restore what was there.
- **chmod fails because PHP does not own the file.** Then the updater could not have created
  it; pre-swap `fc_abort` is the safe end-state.
- **Symlink inside staging.** Skipped by `isLink()`; the extractor cannot create one
  (`fc_step_extract` writes only regular files), and `build-release.php:124,160` refuses to
  package symlinks.
- **Hosts where PHP and Apache are the same uid** (dev, CI, mod_php): the change is a no-op
  in effect but the assertions still hold, which is exactly why the assertions are on modes
  and not on HTTP status.

## Threat model

Auth surface: none added — `fc_chmod_tree()` is reached only from steps already behind
`fc_auth()` (one-time token, `hash_equals`) plus the ops lock and step-order fencing.
Untrusted input: none — the walked path is `FC_UPDATE_ROOT . '/storage/updates/staging'`, a
constant, never manifest-derived; `removed[]` remains whitelisted by `fc_removed_allowed()`.
Data sensitivity: the change loosens modes on **code only**. Secrets keep their tight modes
because they are not swap entries: `config/config.php` 0640 (`Installer.php:253`),
`storage/install-state.json` and `setup-token.txt` 0600 (`InstallState.php:264`),
`storage/updates/auth-token` 0600 (`OpsController.php:525`) — the spec must not touch
`config/` or `storage/`, and T5 asserts the sentinels survive.
Blast radius if wrong: a too-permissive mode on `app/`/`views/`/`lang/` would expose source
over HTTP — mitigated by the root `.htaccess` deny plus per-directory `Require all denied`,
and already probed against a 0755 tree by `release.yml:148-164`. Symlink-following in a
recursive chmod would be the one real escape route; explicitly skipped.

## Tests

1. `fc_chmod_tree()` sets 0755 on every directory and 0644 on every file in a nested tree.
2. Same result under `umask(077)`.
3. `fc_chmod_tree()` is idempotent (second call, same modes, returns true).
4. `fc_chmod_tree()` returns `false` when a chmod cannot succeed.
5. `fc_chmod_tree()` skips symlinks instead of following them.
6. e2e scenario 1 under `umask 077`: all swapped directories 0755.
7. e2e scenario 1 under `umask 077`: all swapped files 0644, `update.php` 0644.
8. e2e scenario 1: `storage/uploads/keepme.txt` and `config/keepme.txt` survive byte-identical.
9. e2e scenarios 2 and 3 (checksum abort, migration rollback) still pass unchanged — the
   rollback still restores the pre-update tree and its original modes.
10. `fc_restore_removed()` chmods **only** directories it created; a destination directory
    that already exists keeps its pre-existing mode.
11. `fc_restore_removed()` returns `false` when its `chmod()` fails (not only when `mkdir()`
    fails), so a half-restored tree fails closed instead of reopening the site.

## Files changed

| File | Change |
|---|---|
| `update.php` | + `fc_chmod_tree()`; call in `fc_step_extract()`; chmod in `fc_step_finish()` self-replace; 0755 + chmod in `fc_restore_removed()` |
| `tests/Unit/UpdaterChmodTreeTest.php` | **new** — tests 1-5 |
| `tests/e2e-updater.sh` | umask-077 server start; mode assertions; data-preservation sentinels (tests 6-8) |
| `docs/superpowers/specs/2026-08-10-000-updater-file-modes.md` | **new** — this spec |
| `.claude/cache/reproduction.md`, `.claude/cache/prior-issues-considered.md` | workflow markers |
| Vault: `CHANGELOG.md`, `LESSONS.md`, `ARCHITECTURE.md` | T8 |

## Risks

- **R1 — 0755 on `app/`/`views/`/`lang/` is judged too permissive.** Mitigated by the
  `.htaccess` guards already probed by the clean-install gate against a 0755 tree; and it is
  the exact state a manual install produces, so the alternative (0750) would make update and
  install diverge and would depend on PHP owning every file forever. If Codex disagrees,
  the fallback is 0755 for `public-assets` only and 0750 elsewhere — strictly more code and
  a host-dependent invariant, which is why it is not the primary design.
- **R2 — recursive chmod following a symlink out of tree.** Explicitly skipped; test 5 pins it.
- **R3 — the e2e umask change destabilises unrelated scenarios** (storage/journal writes at
  0600). Contained: those paths are only ever read/written by the same PHP user. Scenarios 2
  and 3 are re-run to prove it (test 9).
- **R4 — the fix cannot be verified on the real host without shipping a release.** Accepted:
  production has already been repaired by hand (`chmod 755`), and the next real update will
  self-heal the four directories because the swap replaces them wholesale.

## Lessons followed (vault LESSONS.md — read in full this session)

- **Follows 2026-08-10 "Updater/restore: every failure path needs a decided end-state
  (open, reverted, or fail-closed)".** Every new failure point in this change is assigned
  one of the three end-states explicitly: `fc_chmod_tree()` failing in `fc_step_extract`
  is case (a) *nothing happened yet → abort and reopen* (pre-swap, staging is discarded,
  the lock released); the `fc_step_finish` executor chmod failing is case (b) *provably
  reverted → reopen* (temp file unlinked, the working executor stays, post-commit so the
  update itself is already committed); `fc_restore_removed` keeps its existing aggregate
  `$ok` → case (c) *fail closed* via `fc_terminal_failure(reopen: false)`. No path in this
  change is left without a decided end-state.

- **Follows 2026-08-10 ""Fallback modes" are features: they need detection, generation AND
  a gate that runs them" (incl. the round-2 addendum).** The addendum is the direct
  ancestor of this bug: *"curl-based smoke tests lie… a real gate must navigate NATURALLY
  … fetch the page's own stylesheet href"*. The install gate learned that lesson
  (`release.yml:225-233`); the **update** path never did, and `php -S` collapses the very
  distinction that matters. Rather than claim "it works on split-uid hosts" without a gate
  that runs it, T5 makes the property **directly observable**: the updater runs under
  `umask 077` and the modes themselves are asserted. That is the "a CI variant with X
  genuinely absent" third part, expressed as a mode assertion because building a
  two-uid Apache in CI would be a far larger and more fragile change.

- **Follows 2026-08-09 "Shared-hosting installer/updater gotchas".** Its rule (1) —
  *"`rename()` is only atomic within one filesystem — staging/backup dirs must live inside
  the app root"* — is why normalization is applied to **staging** and then promoted by the
  existing in-root `rename()`, instead of chmod-walking the live tree after the swap. The
  staging→live rename stays exactly as it is; nothing about the atomicity argument changes.

- **Follows 2026-08-10 "Concurrency fixes that 'look atomic' need a second adversarial
  pass" and "Ops round 3: recovery code is code too".** Playing the delayed contender:
  `fc_chmod_tree()` takes no lock, mutates no shared resource, and touches only
  `storage/updates/staging` — a path already owned exclusively by this run through the ops
  lock acquired at preflight and re-asserted by the mid-chain ownership check
  (`update.php:1177-1182`). It is idempotent, so a re-run of the recovery path or a
  superseded handler re-entering `fc_step_extract` cannot corrupt anything: the step already
  begins by discarding staging wholesale (`update.php:543`). It therefore introduces no new
  observation-then-act window.

- **Does not apply: 2026-08-10 "Ops rounds 4-7" (lock/fence protocol) and INV-005.** This
  change adds no lock, fence, graveyard or `ops.lock` mutation of any kind; `fc_lock_mutex`,
  `fc_ops_lock_acquire`, `fc_lock_is_stale` and `fc_ops_lock_release` are not edited.

## Invariants

- **INV-003 (production runs on plain shared hosting) — preserved and strengthened.** No
  shell, no Composer, no new dependency: `chmod()` is core PHP. The change exists precisely
  to make the release behave correctly on cyon.ch-class hosting, where PHP and the static
  file server run as different users. Subdirectory installs are unaffected (no URL change).
- **INV-005 (every `ops.lock` mutation goes through the shared flock mutex) — preserved.**
  No lock code is touched. `fc_chmod_tree()` runs inside `fc_step_extract`, which already
  holds the ops lock acquired at preflight; no new lock, no new mutation of `ops.lock`.
- **INV-001 / INV-002 (history never deleted; coins only via the ledger) — untouched.** No
  DB code changes; `config/` and `storage/` are not swap entries, and test 8 pins that.
- **INV-004 (product name "Family Castel") — untouched.**

## Acceptance checklist

- [ ] `fc_chmod_tree()` exists, is idempotent, skips symlinks, returns a checked boolean
- [ ] `fc_step_extract()` normalizes the staged tree and aborts (pre-swap) if it cannot
- [ ] `fc_step_finish()` chmods the new executor to 0644 and does not rename on failure
- [ ] `fc_restore_removed()` creates live directories 0755, chmods **only** what it created,
      and folds a failed `chmod()` into `$ok` so the rollback fails closed
- [ ] Unit tests 1-5 green
- [ ] `tests/e2e-updater.sh` runs its server under `umask 077`; tests 6-8 green; RED proven
      against stock `update.php` first
- [ ] Scenarios 2 and 3 still green (test 9)
- [ ] Full PHPUnit suite green; coverage gate unchanged
- [ ] Five out-of-scope findings filed as issues and named in the PR body
- [ ] Vault updated (CHANGELOG, LESSONS, ARCHITECTURE)
