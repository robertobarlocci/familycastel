# 2026-08-14 — Minus points with a photo, from their own navbar entry

## Goal

The operator asked for **minus points**: when a child does something wrong (forgets
homework, games too long, does not come home after school), a parent records a deduction,
**optionally attaches a photo stored on the server itself — explicitly no S3** — and the
child can then open the app and see it, so the entry teaches rather than just subtracts:
*"−1 point because I forgot to make my bed before leaving the house."* It must be reachable
from **its own navbar entry, including inside the burger menu on a phone**, and the photo
button lives on that page.

### What the measurement changed about this ask

`.claude/cache/reproduction.md` (status `BASELINE-CONFIRMED`) establishes that **the minus
half already exists and works**, which the request could not have known:

- `transactions.coins_delta` is `INT` **signed** and `type` already contains `'deduction'`
  (`app/Database/Migrations/001_initial_schema.php:82-103`).
- Both parent entry points already pick the sign-correct type —
  `type: $coins >= 0 ? 'award' : 'deduction'` (`app/Http/Parent/ChildScreenController.php:114`,
  `app/Domain/TemplateService.php:132`).
- `views/parent/child.php:26` already renders a negative quick action with a red
  `.template-btn.negative` variant, and the demo family ships one
  (*"Zimmer nicht aufgeräumt −5 🪙"*, `app/Domain/DemoSeeder.php:119`).
- The child's journal already has an **Abgezogen** filter
  (`app/Domain/JournalService.php:37`, `lang/de.php:405`) and Emma's journal shows three
  real negative rows.

So this spec does **not** build a second economy. It reuses the existing deduction path
verbatim and adds only the two things that genuinely do not exist:

1. **A dedicated entry point.** The open burger menu contains exactly nine links and none of
   them is about consequences; recording one today means picking a child tile on `/parent`,
   then scrolling past ten positive quick actions.
2. **Photo evidence.** There is no upload code anywhere in the product — repo-wide,
   `$_FILES`, `move_uploaded_file`, `finfo`, `getimagesize` return **zero** matches. A child
   sees a penalty as text only (delta, title, timestamp, optional `💬` comment).

## Surgical scope

Limited to exactly the operator's request: a parent-side **Minuspunkte** page reachable from
its own navbar/burger entry, on which a parent records a coin deduction for a child with a
reason and an **optional photo stored on the server filesystem**, and the same photo shown to
that child in their own journal.

**Out of scope** (tempting, deliberately excluded):

- **Any change to XP.** INV-002 states XP never decreases, and the database agrees:
  `transactions.xp_delta` is `INT UNSIGNED` and `children.xp_total` is `BIGINT UNSIGNED`, so
  a negative XP is a DB-level error on top of the service guard
  (`app/Domain/LedgerService.php:86-88`). A penalty is coins-only. **The operator should know
  this is a constraint, not an omission** — see *Open decisions* below.
- **A new transaction type.** `'deduction'` already exists and is already what the journal's
  `deducted` filter matches. Adding `'penalty'` would need a schema change to the `type`
  ENUM, a `LedgerService::TYPES` change and a `JournalService.php:37` change, and would split
  one concept across two values for no user-visible gain.
- **Changing the negative-balance policy.** The floor stays exactly what the existing custom
  award uses: available balance (balance − pending reward reservations), overridable only by
  the existing `economy.allow_negative_balance` setting
  (`app/Http/Parent/ChildScreenController.php:98`). No new setting, no new semantics.
- **Fixing that `TemplateService::apply()` never passes `allowNegative`** while
  `ChildScreenController::custom()` does — a real inconsistency found while reading, but a
  pre-existing one on a screen this change does not touch. **Filed as a separate issue**
  (see *Risks*), not fixed here.
- **Photo delete / replace / an admin media browser.** Not asked for. INV-001 forbids
  deleting history, and a photo attached to a history row is part of that row's evidence.
  See *Open decisions* — this is the one place where the invariant and a plausible privacy
  need pull in different directions, so it is raised rather than decided silently.
- **Reversing a penalty from the UI.** `LedgerService::reverse()` exists and has no UI today
  for *any* transaction type. Adding one for penalties only would be a new feature.
- **Stripping EXIF/GPS from uploads.** Would need `ext/gd`, which is **not** in
  `SystemCheck::REQUIRED_EXTENSIONS` (`app/Install/SystemCheck.php:14`) and is not guaranteed
  on the target hosting (INV-003). Accepted risk, stated in the *Threat model*.
- **Notifying the child.** `NotificationService` exists but **nothing in the kid UI reads
  notifications** — `unreadFor()` is called only from a test. Delivering a penalty notice
  would mean building a child-side notification surface, which is a separate feature. The
  child learns from the journal, which is what the operator described ("the kid can go
  online and check it").
- **Fixing issue #16** (`.star-btn` / `.topbar-menu-toggle` still take their foreground from
  the UA). Every control **this** change adds declares its own `color` from the start, so the
  new page never becomes another instance — but the existing controls stay as they are.
- **A new JavaScript file.** Issue #10 documents that `sw.js` does not precache the JS files
  the app already has; a new one would land straight in that gap. The page uses no JS —
  `navigation.js:47-51` already closes the burger on any link click, so the new nav entry
  works with zero JS changes.
- **Adding a `storage/` subdirectory to the release ZIP skeleton.** `storage/uploads/` already
  ships (`scripts/build-release.php:37`), is HTTP-denied, is included in every backup
  (`app/Domain/BackupService.php:66`) and survives updates (`tests/e2e-updater.sh:267`). The
  new `penalties/` subdirectory is runtime data created lazily by the code, so
  `build-release.php` and `tests/Integration/ReleaseBuilderTest.php` need no change.

## What ships

### 1. Storage: `storage/uploads/penalties/YYYY/MM/<32 hex>.<ext>`

Under the existing, already-protected `storage/uploads/` — which is denied over HTTP by
`storage/.htaccess:1` (`Require all denied`, module-independent) **and** by the root
`.htaccess:12` rewrite deny, is asserted unreachable by a live HTTP probe at install time
(`app/Install/SystemCheck.php:92-173`, a 200 is a **blocking** failure) and again by two
real-Apache probes in `release.yml:152-158`. The photo is therefore **never** served by
Apache; it is streamed by PHP behind an authorisation check.

The directory is created lazily with an **explicit** mode, per the 2026-08-10 lesson that a
file mode is output and never a side effect:

```php
if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
    throw new \RuntimeException('Upload directory is not writable: ' . $dir);
}
```

(The trailing `&& !is_dir($dir)` closes the race where a sibling request created it between
the check and the `mkdir` — `mkdir` then returns false for a directory that does exist.)

Year/month sharding keeps any single directory small on hosting with no shell to clean up
with. The stored file is `chmod`-ed to **0640** — PHP is the only reader, and `storage/` is
explicitly excluded from `FileModeHeal` (`app/Core/FileModeHeal.php:37-39`), so nothing will
later widen it back to 0644.

### 2. `app/Domain/PenaltyPhotoStore.php` (new) — the whole trust boundary

Every untrusted byte is handled here, and nothing downstream re-derives anything from client
input.

```php
final class PenaltyPhotoStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Detected image type => [extension, mime]. SVG is absent on purpose. */
    private const ACCEPTED = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG  => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    private const PATH_PATTERN = '#^\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp)$#';

    /** $chmod is a test seam (see test 10b); production passes null and the store uses chmod(). */
    public function __construct(
        private readonly string $uploadsDir,
        private readonly ?\Closure $chmod = null,
    ) {}

    /** @return array{path: string, mime: string, bytes: int} */
    public function store(array $file): array;      // $_FILES['photo']
    public function resolve(string $path): string;  // stored column -> absolute path
    public function delete(string $path): void;     // rollback only; never deletes history
}
```

`store()` **owns every failure after the move.** Once `move_uploaded_file()` has run, the caller
does not yet hold a path, so `store()` cannot delegate cleanup: every post-move failure
(`chmod` refused, the size re-check failing on the real bytes) unlinks the file it just placed
**before** it throws. Nothing that fails inside `store()` can leave a file behind.

`store()`, in order — each step is a hard boundary, not a hint:

1. `$file['error'] !== UPLOAD_ERR_OK` → a typed message. `UPLOAD_ERR_INI_SIZE` /
   `UPLOAD_ERR_FORM_SIZE` map to *"the photo is too large"*, not to a generic failure, because
   on shared hosting the real `upload_max_filesize` is whatever the host gives us
   (`docker/php.ini:4-5` is a dev approximation only) and the parent must be told which knob
   they hit.
2. `is_uploaded_file($file['tmp_name'])` — refuses a caller-supplied local path.
3. `$file['size'] > MAX_BYTES` **and** `filesize($tmp) > MAX_BYTES` — the client-declared size
   is not trusted; the real one is checked too.
4. `getimagesize($tmp)` — the type detector. **Deliberately `getimagesize()` and not
   `finfo`:** `ext/fileinfo` is *not* in `SystemCheck::REQUIRED_EXTENSIONS`
   (`app/Install/SystemCheck.php:14`) and neither is `ext/gd`, so depending on either would
   make the feature fail on a host we promise to run on (INV-003). `getimagesize()` lives in
   `ext/standard` and is always present. It returns `false` for a text file, an SVG, and for
   anything that is not one of the raster formats it knows — which is exactly the check we
   need. `$info[2]` must be a key of `ACCEPTED`; anything else is rejected.
5. The extension and the stored MIME come **from `ACCEPTED[$info[2]]`** — never from
   `$file['name']` and never from `$file['type']` (both are attacker-controlled). A file
   uploaded as `evil.php` containing a real PNG is stored as `<hex>.png`; a file named
   `photo.jpg` containing PHP source is rejected at step 4.
6. Name: `bin2hex(random_bytes(16))` — 32 hex chars, no client input in the path at any point,
   so there is no traversal surface to defend.
7. **The name is reserved before it is filled, so a collision can never overwrite.**
   `fopen($abs, 'xb')` is `O_CREAT|O_EXCL`: it fails atomically if the name already exists. On
   failure a fresh random name is drawn, up to 5 attempts, then a `RuntimeException`. Only
   after the reservation succeeds does `move_uploaded_file()` write over the zero-byte file we
   provably own. This matters because the rollback in §4 deletes **by path**: without an
   exclusive reservation, "this path is mine" would be a probability rather than a fact, and a
   compensating delete could remove a file a different, committed row references.
8. `chmod($abs, 0640)`, checked — `move_uploaded_file()` leaves an umask-dependent mode, and
   `chmod()` is the only one of these calls that `umask` does **not** touch.
9. Returns the **relative** path (`YYYY/MM/<hex>.<ext>`), the MIME from our own map, and the
   real byte size.

**Directory modes are set, not requested.** `mkdir($dir, 0770, true)` is masked by the process
umask — under the host's `umask 027` the promised 0770 silently becomes 0750, and under
`umask 077` it becomes 0700. So after `mkdir` the store `chmod()`s **each level it created**
(`penalties/`, `YYYY/`, `YYYY/MM/`) to 0770 and checks each result, exactly as
`update.php:1131` `fc_chmod_tree()` does for the swap tree. This is the 2026-08-10 lesson
applied where it now bites: a mode is output, and `mkdir`'s mode argument is a request while
`chmod` is an instruction.

`resolve()` is the read boundary and treats its own database column as untrusted, because a
restored backup or a hand-edited row must not become a file-read primitive:

1. The value must match `PATH_PATTERN` — three fixed segments, lower-case hex, one of three
   extensions. `..`, a leading `/`, a backslash and any other extension are all unmatchable.
2. `realpath()` of the join must be a string **and** must start with `realpath($uploadsDir) .
   DIRECTORY_SEPARATOR` — the containment re-check the repo already uses for zip extraction
   (`app/Domain/BackupService.php:155`). This is what makes a symlink planted inside the
   uploads tree unable to escape it.

### 3. `app/Database/Migrations/007_transaction_photos.php` (new)

A separate table rather than a column on `transactions`, so the append-only ledger keeps its
exact shape and `LedgerService::post()`'s signature is not widened by a concern only one
caller has.

```sql
CREATE TABLE IF NOT EXISTS transaction_photos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    path           VARCHAR(255) NOT NULL,
    mime           VARCHAR(50)  NOT NULL,
    byte_size      INT UNSIGNED NOT NULL,
    created_at     DATETIME NOT NULL,
    UNIQUE KEY uq_transaction_photos_tx   (transaction_id),
    UNIQUE KEY uq_transaction_photos_path (path),
    CONSTRAINT fk_transaction_photos_tx FOREIGN KEY (transaction_id)
        REFERENCES transactions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `ON DELETE RESTRICT` matches `transactions`' own FK style and INV-001: a history row that
  carries evidence can never be deleted out from under it.
- `uq_transaction_photos_tx` is what makes "one photo per penalty" a database fact rather than
  a controller convention, and it is what makes the idempotent replay of a double-submitted
  form land on **one** photo row instead of two.
- `uq_transaction_photos_path` means the same stored file can never be referenced twice, so
  the rollback `delete()` can never remove a file another row still points at.
- `down()`: `DROP TABLE IF EXISTS transaction_photos` — the rollback path Hard Rules require.
  Rolling back leaves the image files on disk; that is deliberate (a rollback must not destroy
  data) and harmless (they are unreferenced bytes inside a directory nothing serves).
- `up()` is written idempotently (`CREATE TABLE IF NOT EXISTS`) to match `003`/`005`/`006`.

### 4. `app/Domain/PenaltyService.php` (new) — one atomic unit, and the file-vs-DB ordering

```php
public function record(
    int $childId,
    int $coins,            // POSITIVE magnitude; the service negates it
    string $reason,
    ?string $comment,
    ?array $upload,        // $_FILES['photo'] or null
    ?int $actorUserId,
    ?string $idempotencyKey,
    bool $allowNegative,
): int
```

Validation (throws `\InvalidArgumentException`, house style — messages come from `t()` at the
controller boundary, the service throws the key-resolved string like `TemplateService` does):

- `$coins < 1 || $coins > 999` → rejected. **Both** bounds live in the service, not only in the
  form: `<input type="number" max="999">` is a hint to a browser, not a constraint on an HTTP
  request, and without the upper bound a hand-crafted POST would be limited only by
  `LedgerService`'s `MAX_MAGNITUDE = 100_000` sanity ceiling — a 100 000-coin "penalty" from a
  page whose whole purpose is small consequences. The **magnitude** is what the form collects,
  so a parent can never accidentally *award* coins from the penalties page — the sign is the
  service's decision, not the form's.
- `trim($reason) === ''` or `mb_strlen($reason) > 190` — 190 is the `transactions.title`
  column width, matching `ChildScreenController::custom()`.
- `$comment` trimmed, `null` when empty, capped at 500 like the existing custom award.

**Ordering — the part that decides what an interrupted request leaves behind:**

1. The photo is validated and moved **before** the database transaction opens. A filesystem
   move cannot participate in a DB transaction, so doing it inside would mean a rolled-back
   transaction leaves a file nothing references — an orphan we could never find again.
2. The DB transaction then posts the ledger row and inserts the photo row:

```php
try {
    return $this->db->transaction(function (Db $db) use (...): int {
        $txId = (new LedgerService($db))->post(
            childId: $childId, coinsDelta: -$coins, xpDelta: 0,
            type: 'deduction', title: $reason, actorUserId: $actorUserId,
            comment: $comment, idempotencyKey: $idempotencyKey,
            allowNegative: $allowNegative,
        );
        if ($stored !== null) {
            $db->execute('INSERT INTO transaction_photos (...) VALUES (?,?,?,UTC_TIMESTAMP())', [...]);
        }
        return $txId;
    });
} catch (\Throwable $e) {
    if ($stored !== null) { $this->discardIfUnreferenced($stored['path']); }
    throw $e;
}
```

`LedgerService::post()` already opens with `WriteGate::assertOpen($db)` and locks the child
row `FOR UPDATE`, and `Db::transaction()` **joins** an existing transaction rather than nesting
(`app/Core/Db.php:100-118`), so the ledger row and the photo row commit or roll back together.

**The compensation re-derives; it never interprets the exception.** This is the single most
important correction in this plan, and it comes straight from the 2026-08-10 vault lesson
*"`rowCount() === 0` is not evidence: an ambiguous write result must be re-derived, not
interpreted"*. An exception escaping `transaction()` does **not** prove the transaction rolled
back: a `COMMIT` that fails at the network or timeout layer can leave a **committed** row while
PHP sees a `PDOException`. Deleting the file on the strength of "an exception happened" would,
in exactly that case, destroy the photo of a penalty the child can already see — silent, and
undetectable until someone opens the journal weeks later.

So the compensation asks the database instead of guessing:

```php
private function discardIfUnreferenced(string $path): void
{
    try {
        $row = $this->db->fetchOne('SELECT id FROM transaction_photos WHERE path = ?', [$path]);
    } catch (\Throwable) {
        return;                     // cannot prove it is unreferenced -> KEEP the file
    }
    if ($row === null) {
        $this->photos->delete($path);
    }
}
```

Three properties make this sound:

- **It fails in the safe direction.** When the re-derivation itself cannot run (the database is
  the thing that just broke), the file is kept. An unreferenced file is a few bytes in a
  directory nothing serves; a deleted referenced file is lost evidence. The asymmetry decides
  the default.
- **The answer is unambiguous, unlike the exception.** `uq_transaction_photos_path` means at
  most one row can ever claim this path, and §2 step 7 proves the path is exclusively ours via
  `O_EXCL`, so "no row references it" cannot mean "some other row references it".
- **It never masks the original failure.** `discardIfUnreferenced()` swallows only its own
  errors; the caught exception is always rethrown.

3. `AchievementService::sync($childId)` runs after a successful post, exactly as both existing
   award paths do (`ChildScreenController.php:68,132`).

**Why the duplicate replay is safe.** A double-submitted form re-sends the same `op` nonce, so
`LedgerService` hits `uq_transactions_idempotency` and throws `DuplicatePostException`. The
transaction rolls back and `discardIfUnreferenced()` deletes the **second** upload — provably
the second, because the first penalty's photo row holds a *different* path (a different
`O_EXCL` reservation), so the lookup for this path returns null while the first row is
untouched. The first penalty and its photo survive unchanged. The controller then flashes
success, matching `ChildScreenController.php:70-73`, because a replay's desired outcome is the
outcome that already happened.

**Orphans, and the sweep that collects them — serialized against writers, not timed.** One
window remains that no compensation can close: the process can be killed between
`move_uploaded_file()` and the commit, so the `catch` never runs. That leaves an unreferenced
file — harmless individually, but unbounded over years on a host with a disk quota. It is
collected the way this codebase already collects the other thing it cannot cron:
**opportunistically**, exactly as `RememberService` prunes tokens ("~1 % of issues, because
shared hosting has no cron", DATABASE.md).

The hard part is not finding orphans; it is proving a file is one. **An age threshold cannot
do it.** A round-2 review finding: a request that stalls between the move and the commit —
a slow host, a suspended process, a long lock wait — for longer than the grace period would be
declared an orphan and deleted, and would then commit a row pointing at a file that no longer
exists. Any grace period is a guess about how long a request can take, and the vault's
2026-08-10 lesson is explicit that a decision made from a stale observation is a race no matter
how the threshold is tuned.

**The mutual exclusion lives in the database, not the filesystem.** A round-3 review killed the
obvious `flock` design on two counts, and both are fatal on the hosting this product targets:

1. **Bootstrap.** A lock file inside `storage/uploads/penalties/` does not exist before the
   *first* upload creates that directory — so the very first writer would run unprotected,
   which is exactly when a concurrent sweep would find a file with no row.
2. **Coherence.** `flock()` is not reliable across NFS-backed or multi-node shared hosting,
   which cyon-class hosting can be. Two processes could each believe they hold conflicting
   locks, and the sweep would delete a referenced file — silently, and only on the customer's
   host, never in dev or CI. That is the same shape as the 2026-08-10 file-mode bug: a property
   our test environments structurally cannot observe.

So the lock is a **MariaDB advisory lock**, which is the one coordination point every node
provably shares — and which is already this codebase's idiom for exactly this problem
(`Migrator.php:72-84` uses `GET_LOCK('familycastel_migrations', 15)` to serialize concurrent
migration runners; `DemoSeeder.php:30` uses the same pattern):

- **Every `record()` takes `GET_LOCK(<lock name>, 10)`** — where `<lock name>` is the
  installation-specific name derived below, never a literal — before the
  `move_uploaded_file()` and releases it in a `finally` after the transaction commits or rolls
  back **and** after `discardIfUnreferenced()` has run — the compensation reads
  `transaction_photos`, so it must be inside the protected span or the sweep could act on the
  file between the rollback and the compensation.
- **The sweep takes the same lock with a zero timeout** (`GET_LOCK(..., 0)`) and **skips the
  whole run** if it does not get it. It never waits and never deletes without it.

This makes the deletion criterion exact rather than probabilistic: while the sweep holds the
lock, no writer is anywhere between its move and its commit, so *"this file has no
`transaction_photos` row"* means *"this file will never have one"*. No threshold to tune, no
stalled-request case, and nothing that depends on a directory existing yet.

**…provided the sweep's read is a CURRENT read.** A round-4 finding, and a subtle one: InnoDB's
default isolation is REPEATABLE READ, so a consistent-read snapshot is established at the
**first read of a transaction** and does not advance. If the sweep ever ran inside an
already-open transaction, its `SELECT` could return the snapshot as it was *before* another
request committed a photo row — the lock would be held correctly and the query would still say
"no row", and the sweep would delete a referenced file. Holding the right lock does not help
when the question is answered from a stale snapshot. Two guards, both cheap:

- **The sweep refuses to run inside a transaction.** `if ($this->db->pdo()->inTransaction())
  { return; }` is its first statement. In autocommit every statement is its own transaction and
  therefore its own fresh snapshot, which is exactly what the proof needs.
- **Its reference lookup is a locking read** (`SELECT ... FROM transaction_photos WHERE path = ?
  LOCK IN SHARE MODE`), which InnoDB always serves as a *current* read rather than from a
  snapshot — so the guarantee survives even if a future caller reintroduces an outer
  transaction. Belt and braces, because the failure is silent and destroys data.

The same reasoning applies to `discardIfUnreferenced()`, which asks the identical question for
the opposite purpose; it uses the identical locking read.

Three properties carry it, and each is why a DB lock beats the file lock it replaced: advisory
locks are **connection-scoped and released when the connection dies**, so a crash mid-upload
self-heals exactly as `flock` would have; they are **coherent across nodes and over NFS**,
because MariaDB is a single authority rather than a filesystem feature; and there is **no lock
file**, so there is no bootstrap ordering, no unlink-under-lock race, and nothing extra riding
along in every backup ZIP.

Serializing writers against each other is a deliberate, cheap trade: `GET_LOCK` has no shared
mode, so two parents recording penalties at the same instant queue behind one another for the
few hundred milliseconds a move-plus-insert takes. For a single-family installation that is
free, and it buys a mutual-exclusion primitive that is actually sound on the target host.

**Three properties of the lock that are easy to assume and must instead be established** (all
raised in round 4):

- **Never re-entrant.** MariaDB's `GET_LOCK` is *counting*: acquiring twice on one connection
  succeeds twice and needs two `RELEASE_LOCK` calls, so a nested acquire followed by a single
  release leaves a hidden hold that wedges every later writer for the life of the connection.
  `PenaltyService` therefore tracks its own hold in a private flag and **throws on a nested
  acquire** rather than incrementing the counter. The lock is taken in exactly one place.
- **The connection is not persistent.** The self-healing argument ("the lock dies with the
  connection") is only true if the connection actually ends with the request. Verified, not
  assumed: `app/Core/Db.php:30-33` constructs its `PDO` with `ATTR_ERRMODE`,
  `ATTR_DEFAULT_FETCH_MODE` and `ATTR_EMULATE_PREPARES` only — `PDO::ATTR_PERSISTENT` is never
  set, anywhere in the repo — so each request gets its own connection and teardown releases any
  lock still held. If persistent connections were ever introduced, this argument dies with
  them; that is why it is written down here rather than left implicit.
- **`RELEASE_LOCK` never depends on teardown.** It runs in a `finally`, so the normal path
  releases explicitly and connection death is only the backstop.

**Lock name — per installation, not per product.** A MariaDB advisory lock name is a flat
global namespace **on the server**, not within the database, and on shared hosting one MariaDB
server carries many customers' databases. A fixed name such as `familycastel_penalty_photo`
would therefore
make two unrelated Family Castel installations on the same host contend with each other: one
family's slow upload would time out another family's penalty, across account boundaries, with
no way to diagnose it from either side. The name is therefore discriminated by the
installation's own database:

```php
'fc_penalty_photo_' . substr(sha1((string) $db->fetchOne('SELECT DATABASE() AS d')['d']), 0, 16)
```

33 characters, inside MariaDB's 64-character limit, stable for the life of an installation, and
resolved once per request and reused (never recomputed per call, so the writer and the sweep
provably use the same name). Two installations sharing one database — the only case where they
genuinely *should* serialize — get the same name, which is correct.

The same latent cross-tenant contention exists on the repo's two existing advisory locks,
`familycastel_migrations` (`Migrator.php:72-84`) and `familycastel_demo_seed`
(`DemoSeeder.php:30`). Both are far less harmful (they are install/migrate-time, not
per-request) and neither is in this change's scope — **filed as a drive-by issue** rather than
fixed here.

**No deadlock against the transaction's own locks — enforced, not merely intended.**
`GET_LOCK` is taken **before** `beginTransaction()` and released **after** commit/rollback, so
it is strictly outside the transaction's lock scope, and every writer therefore acquires in the
same order: advisory lock → `ops_state` share lock → child row `FOR UPDATE` (`LedgerService`'s
documented order) → `transactions` insert. Identical ordering for all writers is what makes a
deadlock impossible rather than merely unlikely.

That argument has a precondition the code must enforce rather than assume, and round 5 found
it: `Db::transaction()` **joins** an existing transaction instead of nesting
(`app/Core/Db.php:100-118`), so a future caller invoking `record()` from inside an already-open
transaction would take the advisory lock *after* that outer transaction's row locks — reversing
the order and reintroducing exactly the cycle this section rules out. So `record()`'s **first
statement** is a fail-closed guard:

```php
if ($this->db->pdo()->inTransaction()) {
    throw new \LogicException('PenaltyService::record() must not run inside a transaction.');
}
```

It throws before taking any lock and before touching the upload, so the failure is loud, at
development time, and leaves nothing behind — rather than a deadlock that appears only under
concurrency on a customer's host. This is the same shape as the sweep's `inTransaction()`
guard, and for the same underlying reason: the correctness argument depends on this code owning
its transaction boundary, so that ownership is checked instead of trusted.

**Acquisition is fail-closed for the writer.** If `GET_LOCK` returns 0 (timeout) or NULL
(error), `record()` throws rather than proceeding — per the 2026-08-10 round-5 lesson that a
mutual-exclusion primitive which continues when acquisition fails is decorative. The parent
sees `t('penalties.error_storage')` and can retry. The sweep is the opposite and equally
deliberate: it is maintenance, so a failure to acquire simply ends the run, and every error
inside it is logged and swallowed — it must never fail a parent's penalty.

**Coverage is a rotating cursor, so no month is stranded.** Sweeping only the current and
previous month (an earlier draft) leaves an orphan permanent once its month ages out. Instead
each run walks **exactly one** `YYYY/MM` directory — the one after the value in
`storage/uploads/penalties/.sweep-cursor`, wrapping to the oldest at the end — and writes the
cursor back. One directory per run keeps the scan cheap on a host with no shell, and the
rotation guarantees every month is revisited.

The cursor is a hint, never a trusted input, because an interrupted write can truncate it:

- Its content must match `^\d{4}/\d{2}$`. Anything else — truncated, empty, malformed, or
  naming a directory that no longer exists — is **not an error**: the run deterministically
  restarts from the lexicographically smallest `YYYY/MM` directory present. A corrupt cursor
  can therefore never wedge the sweep permanently, which is the failure mode of treating it as
  state rather than as a hint.
- It is written **tmp file in the same directory + `rename()`**, the atomic-replace idiom the
  installer already uses for `config/config.php` (`Installer.php:244-261`), so a kill during
  the write leaves either the old value or the new one and never a half-written one.

**The sweep validates every path it walks, exactly like `resolve()` does.** It is deleting
files, so it gets the same treatment as the read boundary rather than being trusted because it
is "internal": directory names must match `^\d{4}$` and `^\d{2}$`; every component must pass
`realpath()` containment inside the uploads root; a symlinked `YYYY`, `MM` or file is skipped,
never followed (the same reason `FileModeHeal:233-235` and `fc_chmod_tree()` skip symlinks);
and only **regular files** whose name matches the `[a-f0-9]{32}\.(jpg|png|webp)` pattern are
candidates for `unlink()`. Anything else in that tree is left alone.

### 5. `app/Http/PenaltyPhotoResponse.php` (new) — one streaming implementation, two callers

Both the parent view and the child view need to emit the same bytes under different guards, so
the response lives once:

**The MIME is re-derived from the validated path, not read from the row.** `resolve()` already
proves the path matches `PATH_PATTERN`, whose last group is one of exactly `jpg|png|webp`, so
the extension is a *validated* value while `transaction_photos.mime` is only a *stored* one. A
restored backup or a hand-edited row could pair `.png` bytes with `text/html`, and serving that
under `Content-Type: text/html` would turn a family photo into stored XSS — the one way an
uploaded file could still become executable content despite never being served by Apache. So
the column keeps its diagnostic value and `send()` takes its header from the extension:

```php
public static function send(string $absolutePath, string $extension, int $transactionId): string
{
    $mime = self::MIME_BY_EXTENSION[$extension];           // 'jpg'|'png'|'webp' -> image/*
    header('Content-Type: ' . $mime);                      // from a validated path, never the row or the client
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Content-Disposition: inline; filename="minuspunkt-' . $transactionId . '.' .
           self::extensionFor($mime) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');             // also set globally at index.php:126
    readfile($absolutePath);
    exit;
}
```

Modelled on the only existing streaming site, `OpsController::downloadBackup()`
(`app/Http/Parent/OpsController.php:222-234`), including the `exit` after `readfile` that the
router contract needs (`index.php:153` echoes the handler's return value). `inline` rather than
`attachment` because the point is that the child *sees* it. `private, no-store` because a
shared cache must never hold one child's photo where another request could be served it, and
because `sw.js:99-101` only cache-firsts `/public-assets/`, so nothing else caches it either.
The filename is generated from the transaction id — no stored or client string reaches it.

### 6. Routes — `app/routes.php`

Four routes, and **they do not all go in the same place** — two details here were HIGH findings
in plan review round 1 and are both about `routes.php` being a plain sequential script, not a
declarative table:

- **Class names are fully qualified.** `app/routes.php` has no `use` statements; every existing
  route names its controller in full (`\FamilyCastel\Http\Parent\TemplatesController`, …).
  A bare `PenaltiesController` would resolve to the global namespace and fatal at first request.
- **`$kid` does not exist yet at line 248.** The shared kid-controller factory is defined at
  `routes.php:278`. `fn () => $kid()` captures `$kid` **by value at closure-creation time**, so
  a kid route registered before line 278 captures `null` and dies with *"Value of type null is
  not callable"* — at request time, not at boot, which is what makes it the kind of bug that
  reaches a user. The kid photo route therefore goes **into the kid block, after `$kid` is
  defined**, next to the existing `/kid/journal` route it belongs with.

The three parent routes, after the templates block (`routes.php:248`):

```php
$router->get ('/parent/penalties',            $requireParent(fn () => (new \FamilyCastel\Http\Parent\PenaltiesController($lazyDb(), $view))->index()));
$router->post('/parent/penalties/create',     $requireParent(fn () => (new \FamilyCastel\Http\Parent\PenaltiesController($lazyDb(), $view))->create($_POST, $_FILES)));
$router->get ('/parent/penalties/photo/{id}', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\PenaltiesController($lazyDb(), $view))->photo((int) $p['id'])));
```

The child route, in the kid block immediately after the existing `GET /kid/journal`
(`routes.php:294`), where `$kid` is in scope:

```php
$router->get('/kid/journal/photo/{id}', $requireChild(fn (array $p) => $kid()->penaltyPhoto((int) $p['id'])));
```

Route-order note: `Router::match()` is first-match-wins in registration order
(`app/Core/Router.php:32-43`), and `/kid/journal/photo/{id}` is a 4-segment path while
`/kid/journal` is 2, so the patterns are disjoint and the order between them does not matter —
stated because "add it after" would otherwise look load-bearing when it is not.

Guard choice, stated so it is a decision and not an accident: `$requireParent`, **not**
`$requireRecentAuth`. Per INV-007 clause 2 and `docs/DEVELOPMENT.md:61-62`, the step-up window
guards the seven operations that are irreversible or that disclose the whole installation
(updates, backups, restore, download, diagnostics). Recording a penalty is a normal, reversible
economy action of the kind a parent does from a phone many times a week; requiring a password
each time would make the feature unusable and would not close any hole a parent session does
not already have. `$requireChild` re-checks `children.archived_at IS NULL` on every request
(`routes.php:106-128`), so an archived child cannot read photos.

### 7. `app/Http/Parent/PenaltiesController.php` (new)

- `index()` — renders the page with: active children (`ChildService::listActive()`), the last
  20 deductions across all children, and the effective upload limit for the hint text.
- `create(array $post, array $files)` — in this exact order:
  1. **`post_max_size` overflow check, before CSRF.** When a request body exceeds
     `post_max_size`, PHP discards `$_POST` **and** `$_FILES` entirely, so `_csrf` is gone and
     `Csrf::validate(null)` returns false (`app/Core/Csrf.php:26`) — the parent would be told
     their *security check* failed when what actually happened is that their photo was too
     big, sending the next person who debugs it in exactly the wrong direction.

     The test is **narrow on purpose**: `$post === []` **and** `$_FILES === []` **and**
     `CONTENT_LENGTH > ` the parsed `ini_get('post_max_size')` (shorthand bytes — `K`/`M`/`G`
     — resolved to an integer; a value of `0` means unlimited, in which case this branch can
     never be taken). An earlier draft tested only `CONTENT_LENGTH > 0`, which also swallows
     every *ordinary* malformed POST — a request with a body PHP simply could not parse would
     have been reported to the parent as "your photo is too large", and any unauthenticated
     shape of malformed body could have produced a nuisance flash. Requiring the length to
     exceed the actual configured limit makes the branch describe the one condition it is for.
     Anything that does not match falls through to the normal CSRF check and is rejected there.

     Skipping CSRF on this one branch is safe because the branch mutates nothing — it flashes
     and redirects — and it is unreachable without already holding a parent session, since
     `$requireParent` runs before the handler.
  2. `Csrf::validate($post['_csrf'] ?? null)` → `t('common.error_csrf')` on failure.
  3. `op_from_post($post)` → idempotency key `'penalty:' . Auth::parentId() . ':' . $op`,
     namespaced like `'award:'` / `'custom:'`.
  4. `$allowNegative` read from `economy.allow_negative_balance` exactly as
     `ChildScreenController.php:98` does.
  5. `PenaltyService::record(...)`.
  6. Exception mapping, identical in shape to the two existing award handlers:
     `DuplicatePostException` → **success** flash; `WriteLockedException` →
     `t('common.maintenance')`; `InsufficientCoinsException` → `t('award.error_insufficient')`
     (the existing key — the message is already exactly right, including its note that
     reserved coins do not count as available); `\InvalidArgumentException` → its message;
     `\RuntimeException` from the store (unwritable directory) → `t('penalties.error_storage')`
     and the real reason to `storage/logs` via the app error log, never to the browser.
  7. POST-redirect-GET back to `/parent/penalties`.
- `photo(int $transactionId)` — looks the photo up by transaction id, 404-redirects when
  absent, `resolve()`s the path, delegates to `PenaltyPhotoResponse::send()`.

### 8. `app/Http/Kid/KidController.php` — one new method

```php
public function penaltyPhoto(int $transactionId): string
```

Loads the photo joined to its transaction and **requires `transactions.child_id ===
Auth::childId()`**. This is the IDOR boundary: the route parameter is a transaction id a
sibling could guess by counting, so ownership is proven from the session principal, never from
the URL. A miss redirects to `/kid/journal` rather than disclosing whether that id exists.

### 9. `app/Domain/JournalService.php` — two new fields on ledger entries

The ledger branch (`:33-56`) gains a `LEFT JOIN transaction_photos p ON p.transaction_id = t.id`
and each ledger entry gains:

```php
'transaction_id' => (int) $tx['id'],
'has_photo'      => $tx['photo_id'] !== null,
```

The four non-ledger branches (sidequest claims, reward requests, suggestions, milestone
requests) gain `'transaction_id' => null, 'has_photo' => false`, so **every** entry the view
receives has both keys and the template needs no `isset()` guard — the shape stays uniform,
which is what stops the next edit from reintroducing a missing-key notice.

No filter changes: a penalty is `type = 'deduction'` with `coins_delta < 0`, which
`JournalService.php:37` already matches, so it appears under **Abgezogen** with no work.

### 10. Views

**`views/layouts/parent.php`** — exactly one `<a>`, inserted between the templates link
(`:48`) and the system link (`:49`):

```php
<a href="<?= e(url('/parent/penalties')) ?>"<?= $currentAttr('/parent/penalties', true) ?>><span class="nav-icon" aria-hidden="true">⛔</span><span><?= e(t('nav.penalties')) ?></span></a>
```

One line covers **both** navigations: a single `<nav class="topbar-nav">` serves desktop and
mobile, and the `@media (max-width: 860px)` block (`app.css:316`) re-lays that same nav as the
burger's two-column grid. `navigation.js:47-51` already closes the menu on any link click, so
the new entry inherits the behaviour. `$currentAttr(..., true)` gives it the same
`aria-current="page"` active state every section link has.

**`views/parent/penalties.php`** (new) — `.page-head` + `.page-title`, then a `.form-card`
with `enctype="multipart/form-data"` (the first multipart form in the codebase), then the
recent-penalties `.list-cards`:

- child `<select>` (required), reason `<input type="text" maxlength="190" required>`,
  coins `<input type="number" min="1" max="999" value="1" required>` — a **positive
  magnitude**, labelled *"Coins abziehen"*, with the sign owned by the service,
- optional comment, optional `<input type="file" name="photo" accept="image/jpeg,image/png,image/webp">`
  with a hint naming the real limit,
- `Csrf::field()` and `op_nonce()` as the first two children of the form, matching
  `views/parent/child.php:23-25`,
- each list row shows child name, reason, `−N 🪙`, timestamp, and — when a photo exists — an
  `<img class="penalty-thumb" src="<?= e(url('/parent/penalties/photo/' . eurl((string) $row['id']))) ?>" alt="" loading="lazy">`.

**`views/kid/journal.php`** — inside the existing article, after the comment line:

```php
<?php if ($entry['has_photo']): ?>
    <img class="journal-photo" src="<?= e(url('/kid/journal/photo/' . eurl((string) $entry['transaction_id']))) ?>"
         alt="<?= eattr(t('journal.photo_alt')) ?>" loading="lazy">
<?php endif; ?>
```

`url()` throughout, never a literal path, so both pretty-URL and `?r=` fallback installs work
(`app/Core/BasePath.php:59-73`) — the 2026-08-10 lesson that a fallback which does not *generate*
its own links is not a fallback. `alt` is a translated string rather than the reason text,
because the reason is already the adjacent heading and repeating it makes a screen reader say
it twice.

### 11. `public-assets/css/app.css`

Additive only; no existing rule is modified and no breakpoint moves (`e2e/specs/assets.spec.ts:31,47`
hard-code the `860px` string):

- `.penalty-thumb` — 72×72, `object-fit: cover`, radius 12, 1px border.
- `.journal-photo` — `display: block; max-width: 100%; height: auto; margin-top: .5rem;`
  radius 12. `max-width: 100%` + `height: auto` is what keeps a 4000px phone photo from
  widening the article; `html { overflow-x: clip }` (`app.css:19`) would otherwise *hide* the
  overflow silently, which is exactly the failure mode issue #15 taught.
- `.penalty-form .field-photo small` — the muted limit hint.
- Every added rule that sets a `background` also sets a `color`, per the v0.1.5 rule that an
  app-styled control owns its foreground. The page's buttons are the existing `.btn-primary` /
  `.btn-secondary`, which already do.

### 12. `lang/de.php` + `lang/en.php`

`nav.penalties` plus a `penalties.*` block (title, intro, the five field labels, the file hint,
`created`, `error_too_large`, `error_storage`, `empty`) and `journal.photo_alt`. **Both** files —
a key present only in `de.php` silently falls back to German for an English family
(`app/Core/I18n.php:42-44`). German is the default locale and the e2e suite runs `de-CH`.

### 13. Version, changelog, landing page

`VERSION` 0.1.5 → **0.1.6**, because INV-006 makes a CSS change unreachable without it: no
`Cache-Control` ships, and `sw.js` serves `/public-assets/` cache-first across the whole app
root, so a returning browser keeps the old stylesheet and the new page renders unstyled.

Two consequences that are easy to miss and both burned this repo already:

- **`site/index.html:40`** advertises `"softwareVersion": "0.1.5"`, and
  `tests/Unit/LandingPageSeoTest.php:189` pins that value to the `VERSION` file. Bumping one
  without the other is red on `main` — the 2026-08-11 "two branches off the same base" lesson,
  in its single-branch form.
- **The dev stack has a database counterpart.** `index.php:47-83` 503s every route outside a
  five-route allowlist while `FC_VERSION` differs from `settings.app.version`. After the bump,
  `UPDATE settings SET value = '"0.1.6"' WHERE \`key\` = 'app.version'` in the dev database, or
  the next Playwright run fails ~44 tests in unrelated specs and reads like a regression.

`CHANGELOG.md` gains a `## [0.1.6]` section written for parents, not for developers.

### 14. `e2e/specs/assets.spec.ts`

`:75-82` asserts the parent menu holds **exactly nine** links. Adding a tenth is the intended
change, so the assertion moves to ten. It is a real guard against accidental nav growth and
stays exact rather than becoming `toBeGreaterThan`.

## Lessons followed (vault matches on the files this plan touches)

- **Avoids 2026-08-11 "Bumping VERSION locally 503s the dev stack, and it looks like the whole
  test suite broke":** the `VERSION` bump this plan requires (INV-006) is treated as a
  **deployment step with two halves**, not a text edit. Teilaufgabe 7 bumps `VERSION`,
  `site/index.html` and `CHANGELOG.md` **together**, and the dev stack's counterpart
  (`UPDATE settings SET value = '"0.1.6"' WHERE \`key\` = 'app.version'`) is run immediately
  after, **before** any Playwright run — otherwise `index.php:47-83` holds every route outside
  its five-route allowlist at 503 and the failures surface in unrelated specs as
  "element not found" and "cannot read cssRules", which reads like a regression in the CSS this
  change touches. Corollary also taken from that entry: test output is written to a file and
  grepped, never piped through `tail -N`, and a broad failure triggers one
  `curl -o /dev/null -w '%{http_code}' /` before anything is debugged. (This session already
  hit exactly this: the stack answered 503 on first contact and was fixed by the same UPDATE —
  recorded in `.claude/cache/reproduction.md`.)

- **Avoids 2026-08-10 "A shipped fix the browser never downloads is not a shipped fix":** this
  plan adds CSS to `public-assets/css/app.css`, the exact file that entry is about. The
  `VERSION` bump is therefore **not optional polish** — without it, `asset()` emits an
  unchanged `?v=0.1.5` URL, no `Cache-Control` ships, and `sw.js` serves `/public-assets/`
  cache-first across the whole app root, so a returning phone renders the new page with the old
  stylesheet. The final Playwright validation additionally **verifies the delivered bytes**
  (`curl` the stylesheet, compare length against disk) before concluding the CSS "works",
  rather than trusting a hard-reloaded dev browser. No new asset **file** is added, so the
  INV-006 `VERSIONED_PATHS` / `STATIC_SHELL` pairing in `sw.js` is unchanged and needs no
  matching edit.

- **Avoids 2026-08-10 "File modes are program state: an updater that INHERITS them ships a
  broken site":** this plan creates the first directory the *application* ever makes at
  runtime, which is the same defect class one level down. So every mode is **output**:
  `mkdir(..., 0770, true)` with the result checked and a `!is_dir()` re-check for the
  create-race, and an explicit checked `chmod($file, 0640)` after `move_uploaded_file()` —
  which leaves an umask-dependent mode. And per that entry's third lesson ("when a gate cannot
  reproduce the environment split, assert the underlying property directly"), test 6 runs the
  store under **`umask(077)`** and asserts the exact octal modes, instead of hoping the dev
  box's umask is representative. The inverse of that entry also holds here and is deliberate:
  the 2026-08-10 bug was that files were **not web-servable enough**; these files must be
  **not web-servable at all**, which is why they live under `storage/` and are streamed by PHP.

## Teilaufgaben

Executed one after another; each ends green before the next begins.

1. **Migration `007_transaction_photos`** — table + `down()`. RED: an integration test asserting
   the table, both UNIQUE keys and the FK exist after `migrate()`, and that `rollbackAll()`
   removes it. GREEN: the migration.
2. **`PenaltyPhotoStore`** — RED: the unit suite (tests 1–10 below) against a temp root, using
   real generated PNG/JPEG/WEBP bytes and real non-image bytes. GREEN: the class. This is the
   trust boundary, so it lands first and alone.
3. **`PenaltyService`** — RED: the integration suite (tests 11–19) against real MariaDB.
   GREEN: the service, including the compensating `delete()` on every failure path.
4. **`JournalService`** — RED: test 20 (a penalty entry exposes `transaction_id` + `has_photo`,
   appears under `deducted`, and every non-ledger entry carries both keys). GREEN: the join.
5. **The whole reachable surface, in one step: `PenaltiesController` + `PenaltyPhotoResponse` +
   `KidController::penaltyPhoto` + the two views + CSS + both lang files.** Not split into
   "HTTP layer" then "views": a route registered against a view that does not exist yet is a
   500, so the earlier 5/6 split produced a step that was *sequential* but not
   *independently shippable* — the definition a Teilaufgabe has to meet. Nothing in this step
   is routed, so the tree is green and the feature is simply not reachable yet.
6. **Wiring: the 4 routes + the nav `<a>`.** One small step that turns step 5 on. Shippable on
   its own, and trivially revertable if the page misbehaves, because everything it references
   already exists and is tested.
7. **Version, changelog, landing page, `assets.spec.ts` 9 → 10** — the release-shaped chores,
   done together so the pairing tests never see a half-applied bump, followed immediately by
   the dev-database `app.version` UPDATE (see *Lessons followed*).
8. **E2E `penalties.spec.ts`** — tests 22–26, on the desktop and mobile projects.

## Scope

**In:** the parent Minuspunkte page and its nav entry; photo upload, storage, and streaming
under both guards; the photo in the child's journal; the migration; lang for de + en; the CSS
for the two new visual elements; the version/changelog/landing-page triple; tests at all three
levels.

**Out:** everything in *Surgical scope → Out of scope* above.

## Edge cases

| # | Case | Behaviour |
|---|---|---|
| 1 | Body exceeds `post_max_size` (`$_POST` and `$_FILES` both empty) | Detected before CSRF; `penalties.error_too_large`; nothing written |
| 2 | `UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` | Same message; the penalty is **not** recorded, so the parent can retry with a smaller photo rather than end up with a photo-less entry they did not intend |
| 3 | `UPLOAD_ERR_NO_FILE` | Not an error — the photo is optional; the penalty records without one |
| 4 | Non-image bytes with an image name (`shell.php.jpg`) | `getimagesize()` returns false → rejected; nothing moved |
| 5 | SVG | `getimagesize()` does not accept it → rejected. Never an accepted type, because an SVG is a script container |
| 6 | Real image with a hostile name (`../../../index.php`) | The name is never read; the stored name is 32 random hex |
| 7 | Real image, wrong extension (`.png` bytes named `.jpg`) | Stored as `.png` from the **detected** type; the served `Content-Type` matches the bytes |
| 8 | Balance too low, `allow_negative_balance = false` | `InsufficientCoinsException` → `award.error_insufficient`; the uploaded file is deleted; nothing committed |
| 9 | Balance too low, `allow_negative_balance = true` | Recorded; the balance goes negative (`children.coin_balance` is signed `BIGINT`) |
| 10 | Pending reward request reserves the coins | The floor is **available** balance; the existing message already explains reserved coins |
| 11 | Double-submitted form (same `op`) | One transaction, one photo row; the second upload is deleted; success flash |
| 12 | Missing/malformed `op` | No idempotency protection (existing behaviour, `op_from_post()` returns null) — a second submit is a second, real penalty, exactly as on the child screen today |
| 13 | Maintenance / write gate closed | `WriteLockedException` → `common.maintenance`; file deleted |
| 14 | `storage/uploads/penalties/` not writable | `RuntimeException` → `penalties.error_storage`; the reason goes to the log, not the browser |
| 15 | Child archived between page render and submit | `LedgerService` throws *"Child not found or archived"*; file deleted |
| 16 | Sibling requests the other child's photo id | `child_id !== Auth::childId()` → redirect to the journal; no disclosure of whether the id exists |
| 17 | Logged-out request for a photo URL | `$requireParent` / `$requireChild` redirect to the login before any file is touched |
| 18 | Photo row present, file missing (manual deletion, partial restore) | `resolve()` fails the `realpath` check → the parent list renders a broken-image-free placeholder and the route redirects; the penalty itself still reads correctly |
| 19 | Backup / restore | `BackupService` already zips `storage/uploads` recursively (`:66`) and restores it zip-slip-safely (`:114-166`); the new subdirectory needs no change |
| 20 | In-app update | `update.php` can never touch `storage/` (`update.php:328`); `tests/e2e-updater.sh:267` already asserts uploads survive |
| 21 | Migration rolled back | Table dropped, image files left on disk unreferenced — deliberate: a rollback must not destroy data |
| 22 | Reason longer than 190 chars / empty | Rejected by the service before any file is moved |
| 23 | Coins ≤ 0 or > 999 | Rejected; the form collects a magnitude so an award can never be posted from this page |
| 24 | Very long reason in the journal | `.journal-photo` is `max-width: 100%`; the title already wraps via the v0.1.5 `overflow-wrap` rule |

## Threat model

**Auth surface.** Three new authenticated routes and one new POST. The upload is
parent-only (`$requireParent`, which re-checks `is_active = 1` on every request). The child
read route is `$requireChild` (re-checks `archived_at IS NULL`) **plus** an explicit
`child_id === Auth::childId()` comparison — the transaction id in the URL is guessable by
counting, so ownership is proven from the session, never from the URL. No route is added to
the `$requireRecentAuth` set, and that is argued above rather than assumed: a penalty is
reversible and discloses nothing beyond the family, unlike the seven operations INV-007
clause 2 protects. INV-007 is otherwise untouched — no credential, no cookie, no session
handling changes, and clause 6 (credential changes revoke remember tokens) is not engaged
because this adds no credential change.

**Untrusted inputs, and what constrains each.**

| Input | Constraint |
|---|---|
| `$_FILES['photo']['tmp_name']` | `is_uploaded_file()` — refuses an arbitrary local path |
| `$_FILES['photo']['name']` | **Never read.** The stored name is 32 random hex |
| `$_FILES['photo']['type']` | **Never read.** The MIME comes from `getimagesize()` via our own map |
| `$_FILES['photo']['size']` | Checked, then re-checked against the real `filesize()` |
| file bytes | Must be JPEG/PNG/WEBP per `getimagesize()`; served with `nosniff` + an exact `Content-Type`, from a directory Apache refuses to serve at all |
| `photo` (POST field name) | Fixed by the form; a missing key is `UPLOAD_ERR_NO_FILE` |
| `child_id` | Cast to int, then must be an **active** child; `LedgerService` re-locks `WHERE id = ? AND archived_at IS NULL FOR UPDATE` |
| `coins` | Cast to int, `1..999`, negated by the service — the form cannot express an award |
| `reason` / `comment` | Trimmed, length-capped, stored via prepared statements, escaped with `e()` on output |
| `{id}` route params | `(int)` cast; the row is then authorised, not trusted |
| `transaction_photos.path` (our own column) | Treated as untrusted on read: strict regex **and** `realpath()` containment |

**Data sensitivity.** Photos of children inside their own home — the most sensitive data this
product will hold. Three properties keep them private: they live under `storage/`, which is
denied over HTTP by a module-independent per-directory guard and verified unreachable by a
live probe at install time and by two real-Apache probes at release time; every read passes a
guard and an ownership check; and `Cache-Control: private, no-store` keeps them out of shared
caches. They are included in backups — which is correct (a restore must bring them back) and
worth stating, because a backup ZIP is downloadable by a sudo-holding parent.

**Blast radius if abused.** A compromised parent session can already award and deduct coins,
wipe the installation and download a full backup; adding "can upload an image" does not widen
that meaningfully. The one genuinely new capability is **disk consumption** on hosting with a
quota: 5 MB per penalty, parent-authenticated, no automatic pruning. Mitigation today is the
per-file cap plus the fact that the actor is already the installation's owner; a quota or a
retention policy is deliberately not invented here. **Stated as a residual risk**, not hidden.

**Accepted, and why.** EXIF/GPS metadata is not stripped: doing so needs `ext/gd`, which is not
a required extension and is not guaranteed on the target hosting (INV-003). The photos never
leave the family's own server, so the metadata is visible only to people who can already see
the photo. Filed as an issue rather than silently ignored.

**Invariants.** INV-001 preserved — nothing deletes history; the photo table's FK is
`RESTRICT`; the only `unlink()` is the compensating rollback of a file whose transaction never
committed. INV-002 preserved — coins move **only** through `LedgerService::post()`, no code
path touches `coin_balance`, and `xpDelta` is a literal `0`. INV-003 preserved — no new PHP
extension, no shell, no cron, no S3, no build step; the release ZIP's allowlist already covers
`app/`, `views/`, `lang/`, `public-assets/`. INV-004 preserved — "Family Castel", and the
feature is not called a quest. INV-005 untouched — no `ops.lock` code path is involved.
INV-006 honoured — the CSS change ships with the `VERSION` bump, and no new asset file means
`sw.js`'s `VERSIONED_PATHS` / `STATIC_SHELL` split is unchanged. INV-007 as argued above.

## Tests

Every one becomes a real test.

**Unit — `tests/Unit/PenaltyPhotoStoreTest.php`** (temp root, no DB, shaped like
`FileModeHealTest`):

1. `UPLOAD_ERR_INI_SIZE` and `UPLOAD_ERR_FORM_SIZE` produce the too-large message; other error
   codes produce the generic one; nothing is written in either case.
2. A file over `MAX_BYTES` is rejected — and separately, a file whose **declared** size is
   under the cap while the real bytes are over it is also rejected.
3. Text bytes named `photo.jpg` are rejected; the temp file is not moved.
4. SVG markup is rejected.
5. Real PNG, JPEG and WEBP bytes are each accepted and stored at
   `YYYY/MM/<32 hex>.<ext>`, with the extension and returned MIME taken from the **detected**
   type — asserted by uploading PNG bytes under the name `evil.php` and finding a `.png` file.
6. The stored file's mode is `0640` and the created directories are `0770` — asserted under
   `umask(077)`, the 2026-08-10 lesson that a gate which cannot observe the failure class is
   not coverage.
7. A client filename of `../../../evil.jpg` cannot influence the stored path.
8. `resolve()` rejects `..`, an absolute path, a backslash, an unknown extension, upper-case
   hex and a wrong-length name.
9. `resolve()` rejects a path that `realpath()`s outside the uploads root (symlink planted in
   the tree).
10. `delete()` removes a stored file and is a no-op — not an error — for a path already gone.
10a. **The name is reserved exclusively.** With a pre-existing file at the name the generator
    would draw, `store()` does **not** overwrite it: the `O_EXCL` reservation fails, a new name
    is drawn, and the pre-existing file is byte-identical afterwards. (Driven by injecting a
    seeded name generator, so this asserts the retry rather than waiting for a 2⁻¹²⁸ event.)
10b. **A post-move failure leaves nothing behind.** Driven through the constructor's `$chmod`
    seam with a closure that returns `false`, because the obvious approach — making the parent
    directory read-only — does **not** make `chmod()` on an existing file fail, so that version
    of the test would pass while exercising nothing (the same "passes for the wrong reason"
    shape as the 2026-08-09 constraint test and the 2026-07-27 capacity test). With the seam
    failing deterministically and `unlink()` still available, `store()` throws **and** no file
    remains — the caller never receives a path it would have to compensate for.
10c. `MIME_BY_EXTENSION` covers exactly the three accepted extensions, and
    `PenaltyPhotoResponse::send()` derives `Content-Type` from the extension — a row whose
    `mime` column says `text/html` still serves `image/png` for a `.png` path.

**Integration — `tests/Integration/PenaltyServiceTest.php`** (real MariaDB, the repo's
migrate-and-wipe boilerplate):

11. `record()` writes one `transactions` row: `type = 'deduction'`, `coins_delta = -N`,
    `xp_delta = 0`, `actor_user_id` set, title = the reason; `children.coin_balance` drops by N
    and `xp_total`/`level` are unchanged.
12. With a photo: exactly one `transaction_photos` row, linked to that transaction, with the
    stored path, our MIME and the real byte size; the file exists on disk.
13. `coins = 0`, `coins = -5` and `coins = 1000` are all rejected — a penalty cannot become an
    award, and the 999 ceiling is enforced by the **service**, asserted by calling it directly
    rather than through the form, since the form's `max` attribute is what a forged POST
    ignores.
14. An empty reason and a 191-character reason are rejected; no file is left on disk.
15. Insufficient available balance → `InsufficientCoinsException`, **no** transaction row, **no**
    photo row, **and the uploaded file is gone** — the compensating delete is asserted, not
    assumed.
16. `allowNegative: true` records the penalty and drives the balance negative.
17. Same idempotency key twice → one transaction, one photo row, and the second call's file is
    removed from disk.
18. Write gate closed (`ops_state.write_locked = 1`) → `WriteLockedException`, nothing
    committed, file removed.
19. A second photo for the same transaction id violates `uq_transaction_photos_tx`.
20. `JournalService::entries()` returns `transaction_id` and `has_photo` for the penalty, lists
    it under the `deducted` filter, and returns both keys on **every** non-ledger entry kind.
21. Migration 007: `up()` is re-runnable, `rollbackAll()` drops the table, and the FK is
    `RESTRICT` (deleting the parent transaction fails).
21a. **The compensation never deletes a referenced file.** `discardIfUnreferenced()` is called
    directly for a path that **does** have a `transaction_photos` row: the file survives. This
    is the ambiguous-commit case (an exception raised while the row is committed) reproduced
    without having to fake a broken COMMIT, and it is the one assertion that would have caught
    the round-1 design.
21b. **The compensation keeps the file when it cannot prove otherwise.** With the lookup forced
    to throw, `discardIfUnreferenced()` leaves the file in place — fail-safe direction.
21c. **The orphan sweep deletes only provable orphans.** A file with no `transaction_photos`
    row is removed; a file **with** a row is kept regardless of age. Age is never part of the
    decision, so there is no threshold for the test to pick.
21d. **A writer in flight stops the sweep dead** — the round-2/3 finding, and the assertion
    that would have caught both. A **second database connection** takes
    `GET_LOCK(<the derived installation-specific name>, 0)` — taken from the service's own
    name resolver, never a hard-coded string, or the test would lock a different name and
    silently stop simulating contention while still passing (simulating a writer between its
    move and its
    commit), the sweep then runs, and a file with no row is **still there** afterwards. Uses a
    real second connection rather than a mock, because the whole point of moving off `flock`
    was that the guarantee has to hold across connections.
21e. **Lock acquisition is fail-closed for the writer.** With the lock held by a second
    connection and the writer's timeout forced to 0, `record()` **throws** and does not move
    the file — it never proceeds unprotected.
21f. **The cursor rotates, is bounded, and cannot wedge the sweep.** Successive runs walk
    successive `YYYY/MM` directories and wrap at the end; each run walks exactly one; and a
    cursor that is empty, truncated (`"202"`), malformed (`"../.."`) or names a deleted
    directory causes a deterministic restart from the oldest directory rather than an error —
    asserted for each of those four values, because "corrupt state wedges the maintenance job
    forever" is a failure nobody would notice until the disk filled.
21g. **The sweep refuses to follow symlinks or delete strangers.** A symlinked `YYYY/MM`
    directory pointing outside the uploads root is skipped; a non-matching filename
    (`notes.txt`, `UPPERCASE.JPG`) inside a swept directory is left untouched even with no row.
21h. **The sweep never answers from a stale snapshot** (round-4 finding). Called while the
    caller's connection has an open transaction, the sweep **returns without deleting
    anything** — asserted by opening a transaction, creating a row-less file, running the sweep
    and finding the file still present.
21i. **The advisory lock is never taken re-entrantly and is always released.** A nested
    acquire throws instead of incrementing MariaDB's lock counter; after a successful
    `record()` and after a failed one, `IS_FREE_LOCK(<the derived name>)` returns 1 — asserted
    from a **second** connection, since the holding connection could not observe its own leak.
21j. **`record()` refuses to run inside a transaction** (round-5 finding). Called with an open
    transaction, it throws, and — asserted explicitly — **no lock is taken and no file is
    moved**, so the lock-ordering argument cannot be violated by a future caller.
21k. **The lock name is installation-specific and stable.** It contains the `SELECT DATABASE()`
    hash, is ≤ 64 characters, is identical for the writer and the sweep within a run, and
    differs between two `Db` instances pointed at different schemas — the cross-tenant case,
    asserted directly rather than argued.

**E2E — `e2e/specs/penalties.spec.ts`** (desktop 1280×800 + mobile 390×844):

22. The nav contains **Minuspunkte** — reached with `parentNavigate()`, i.e. through the burger
    on mobile — and it opens `/parent/penalties` with `aria-current="page"`.
23. A penalty without a photo: the flash appears, the entry is in the list, and the child's
    balance on `/parent/child/{id}` has dropped by exactly the amount.
24. A penalty **with** a photo (`setInputFiles` with a real fixture): the list row shows a
    thumbnail whose `naturalWidth > 0`, and a direct fetch of the photo route returns 200 with
    a `content-type` starting `image/`.
25. As the child: `/kid/journal` shows the penalty under **Abgezogen**, its `<img>` loads
    (`naturalWidth > 0`), and the article does not overflow the viewport at 390 px.
26. As Noah, requesting Emma's photo id redirects to the journal and returns no image bytes.
27. `assets.spec.ts` — the parent menu holds exactly **ten** links; the burger a11y contract
    (`aria-expanded`, Escape, outside click, close-on-link) still holds on mobile.

**Coverage.** `phpunit.coverage.xml` scopes `<source>` to `app/Domain` + `app/Core`, so
`PenaltyPhotoStore` and `PenaltyService` are inside the ≥80 % target and are covered by 1–21.
`PenaltiesController` / `KidController` are outside that scope by the project's existing
convention — which is precisely why 22–27 exist, and precisely the gap that let issue #12 ship
a 500 on an untested controller.

## Files changed

| File | Change |
|---|---|
| `app/Database/Migrations/007_transaction_photos.php` | **new** — table + `down()` |
| `app/Domain/PenaltyPhotoStore.php` | **new** — validate, store, resolve, delete |
| `app/Domain/PenaltyService.php` | **new** — record a penalty atomically, compensate the file |
| `app/Http/Parent/PenaltiesController.php` | **new** — index, create, photo |
| `app/Http/PenaltyPhotoResponse.php` | **new** — the one streaming implementation |
| `app/Http/Kid/KidController.php` | +1 method: `penaltyPhoto()` with the ownership check |
| `app/Domain/JournalService.php` | LEFT JOIN + `transaction_id` / `has_photo` on every entry |
| `app/routes.php` | +4 routes |
| `views/layouts/parent.php` | +1 nav `<a>` (serves desktop **and** burger) |
| `views/parent/penalties.php` | **new** — form + recent list |
| `views/kid/journal.php` | render the photo when present |
| `public-assets/css/app.css` | `.penalty-thumb`, `.journal-photo`, the field hint |
| `lang/de.php`, `lang/en.php` | `nav.penalties`, `penalties.*`, `journal.photo_alt` |
| `VERSION` | 0.1.5 → 0.1.6 (INV-006) |
| `site/index.html` | `softwareVersion` → 0.1.6 (pinned by `LandingPageSeoTest:189`) |
| `CHANGELOG.md` | `## [0.1.6]` section |
| `e2e/specs/assets.spec.ts` | 9 → 10 nav links |
| `tests/Unit/PenaltyPhotoStoreTest.php` | **new** — tests 1–10 |
| `tests/Integration/PenaltyServiceTest.php` | **new** — tests 11–21 |
| `e2e/specs/penalties.spec.ts` | **new** — tests 22–26 |

Not changed, and each for a reason: `scripts/build-release.php` (`storage/uploads` already in
`STORAGE_SKELETON`; the subdirectory is runtime data), `tests/Integration/ReleaseBuilderTest.php`
(same), `sw.js` (no new asset file), `app/Domain/LedgerService.php` (the penalty is an ordinary
`deduction`), `app/Install/SystemCheck.php` (no new required extension), `update.php`
(`storage/` is already untouchable), `app/Core/FileModeHeal.php` (never walks `storage/`).

## Risks

- **Shared-hosting upload limits are unknowable at build time.** A host with
  `upload_max_filesize = 2M` will reject photos our 5 MB cap accepts. Mitigated by reading the
  effective `ini_get()` values at render time for the hint and by mapping the ini error codes
  to a message that names the cause. Residual: a parent on a very small limit is told the photo
  is too large without being told the exact number their host allows.
- **Disk consumption has no automatic bound.** Stated in the threat model. A retention or quota
  policy is a product decision for the operator, not something to invent inside this diff.
- **The migration adds a table to a live install.** `CREATE TABLE` is fast and takes no lock on
  existing tables, and `down()` is a clean `DROP`. The updater runs migrations in a fresh
  request after the swap (`docs/DEVELOPMENT_PLAN.md`), so old code never sees the new table.
- **First multipart form in the codebase.** `post_max_size` overflow silently empties `$_POST`;
  handled explicitly (edge case 1) because otherwise it surfaces as a CSRF error and sends the
  next person debugging in the wrong direction entirely.
- **`getimagesize()` is a format check, not a safety proof.** It is sufficient here only
  because the file is never executed, never served by Apache, and always served with an exact
  `Content-Type` under `nosniff`. If a future change ever moves uploads under a web root, this
  argument dies with it — recorded as an invariant in the vault, not just here.
- **Drive-by findings to file as separate issues** (not fixed in this diff):
  1. `TemplateService::apply()` never passes `allowNegative`, so a negative quick action fails
     with `InsufficientCoinsException` even when the family enabled negative balances, while
     the custom award on the same screen succeeds.
  2. `economy.allow_negative_balance` has no UI anywhere — it can only be changed in the
     database.
  3. EXIF/GPS metadata is not stripped from uploaded photos (needs `ext/gd`).
  4. `Migrator.php:72-84` and `DemoSeeder.php:30` use unqualified advisory lock names
     (`familycastel_migrations`, `familycastel_demo_seed`). Advisory lock names are global to
     the MariaDB **server**, so two Family Castel installations on one shared host contend
     across account boundaries. Low impact (install/migrate-time only, not per request), same
     fix as this change applies to its own lock; out of scope here.

## Acceptance checklist

- [ ] A **Minuspunkte** entry appears in the parent navbar and inside the burger menu on a
      phone, with the correct active state, and the burger still closes when it is tapped.
- [ ] A parent can record a penalty for a child with a reason and a coin amount; the child's
      balance drops by exactly that amount and their XP and level do not move.
- [ ] A parent can attach a JPEG, PNG or WEBP photo; it is stored under
      `storage/uploads/penalties/YYYY/MM/` with a random name, mode 0640, in a directory
      created 0770.
- [ ] The photo is **not** reachable directly over HTTP; it is served only through the guarded
      PHP routes, with the correct `Content-Type` and `Cache-Control: private, no-store`.
- [ ] The child sees the penalty in their journal, under **Abgezogen** and under **Alles**,
      with the photo rendered and not overflowing at 390 px.
- [ ] A sibling cannot fetch another child's photo.
- [ ] Every failure path — too large, wrong type, insufficient coins, maintenance, duplicate
      submit — leaves **no** orphan file and **no** partial database state.
- [ ] Both `lang/de.php` and `lang/en.php` carry every new key.
- [ ] `VERSION`, `site/index.html` and `CHANGELOG.md` agree on 0.1.6; `LandingPageSeoTest`
      passes.
- [ ] Full PHPUnit suite green against MariaDB; `e2e` green on both projects; `php -l` clean.

### Repository maintenance (separate from the feature, required by this repo's process)

Not a deliverable of the feature and deliberately outside the *Files changed* table above,
which lists only the product diff. It is listed because `~/.claude/hooks/enforce-workflow.sh`
blocks `git commit` until the vault pages describing the changed areas have been updated
(workflow §11), so leaving it implicit would stall the commit, not save scope.

- [ ] `~/Coding-Vault/Coding/familycastle/`: DATABASE.md (migration 007),
      ARCHITECTURE.md (the upload trust boundary), FILE_MAP.md, CHANGELOG.md, LESSONS.md,
      and a new INVARIANT — *uploaded files are never web-servable and their content type is
      always re-derived server-side from validated data, never from the client or from a
      stored column* — which is the property this plan's §2/§5 rest on and the one a future
      refactor is most likely to lose.

## Open decisions for the operator (not blocking — defaults chosen and stated)

1. **XP is untouched.** A penalty removes coins only, because INV-002 and the database schema
   both make XP monotonic. If losing XP is genuinely wanted, that is a different, larger change
   (three layers: the service guard, two UNSIGNED columns, and the invariant itself) and should
   be its own issue. **Default taken: coins only.**
2. **No way to remove a photo once attached.** INV-001 says history is never deleted, and the
   photo is evidence attached to a history row. A parent who attaches the wrong photo currently
   cannot take it back, which is a plausible privacy need pulling against the invariant.
   **Default taken: no delete**, raised here rather than decided silently.
3. **Recording a penalty does not require the password step-up**, so it stays usable from a
   phone. **Default taken: `$requireParent`**, argued in the threat model.

## Prior issues considered

`.claude/cache/prior-issues-considered.md` — 7 open issues, 2 closed, 0 open PRs, 9 closed PRs
indexed; #16, #10, #15, #12 and PRs #17, #14, #13, #11, #8/#9, #2, #18 read in full and each
folded into a constraint above.

## Reproduction

`.claude/cache/reproduction.md` — status `BASELINE-CONFIRMED`, one block, walked in the real
app at 390×844 as both the parent and the child.
