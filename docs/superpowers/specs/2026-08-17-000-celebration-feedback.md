# Celebration & consequence feedback for the child, and a "something new" mark on the Journal

- **Date:** 2026-08-17
- **Issue:** none (operator request) → `000`
- **Branch:** `feat/000-celebration-feedback`
- **Lane:** Standard
- **Reproduction:** `.claude/cache/reproduction.md` — Issue #000, status `BASELINE-CONFIRMED`
- **Prior issues considered:** `.claude/cache/prior-issues-considered.md`

---

## Goal

The operator asked for three things, in their words:

1. Confetti not only when a Sidequest is approved, but **also when a parent simply gives
   points through "Eigene Aktion"**.
2. **A dark cloud raining for 2–3 seconds when the child gets minus points**, so a negative
   event is as legible as a positive one — *"so they always know, something happened"*.
3. **A mark on the Journal** so the child can see there is something new there.

Restated in this codebase's terms: the child's app currently has exactly one celebration
trigger — a fresh **achievement** unlock — and no negative feedback and no unread mark at
all. This change gives the celebration a second, **transaction-shaped** source of truth
next to the achievement one, so that *every* Coin/XP event the child receives produces
feedback the next time they open their castle, and the Journal advertises what is waiting.

### What the reproduction found, and why it changes the shape of the work

The request's premise — "when a parent accepts a sidequest they get confetti" — is not what
the code does, and this was measured, not assumed (`reproduction.md`, and the same trap as
LESSONS 2026-08-14 *"the feature was half-built already"*, one level over):

`public-assets/js/celebrate.js:7` fires **only** when the page carries `[data-celebrate]`.
The single producer of that marker is `views/kid/home.php:34-43`, gated on
`AchievementService::takeUnseen()` — i.e. on `child_achievements.seen_at IS NULL`. A
Sidequest approval therefore celebrates only *by accident*, when it happens to push
`sidequests_approved` past an achievement threshold. Measured live: with an unseen
`+3 🪙/+3 ✨` award **and** an unseen `−2 🪙` deduction waiting, Emma's `/kid` reported
`[data-celebrate]` = 0 and `<canvas>` = 0.

**Consequence for the plan:** the fix is *not* "add a confetti call to the two award
controllers". Doing it per entry point would reproduce the operator's own complaint one
entry point later — a negative quick-action template, an `adjustment`, a `reversal` and any
future award path would all still be silent. The correct unit is the **ledger transaction**,
which every one of those paths already funnels through (INV-002). One trigger, all paths.

---

## Surgical scope

**Limited to exactly the operator's request:** feedback on the child's screen for
positive and negative point events, and an unread mark on the child's Journal nav entry.

**Explicitly out of scope** (tempting, adjacent, deliberately excluded):

- Any change to who may award or deduct points, to amounts, or to the ledger's economics.
- A level-up celebration. `celebrate.js:1` claims one and there has never been one
  (`LevelService` records no event); building it is a separate feature.
- Widening the unread mark beyond ledger transactions. The Journal also lists pending
  Sidequest claims, reward requests, suggestions, milestone wishes and rejections; giving
  those seen-state means seen-state on four more tables. Not asked for, not done.
- Fixing issue #19 (`.history-title` / `.journal-body strong` need `overflow-wrap`) even
  though this change touches `views/kid/journal.php`'s neighbourhood.
- Fixing issue #16 (`.star-btn`, `.topbar-menu-toggle` take their foreground from the UA).
  The **new** control introduced here declares its own `color`; the existing ones stay #16's.
- Fixing issue #10 (precaching `navigation.js`, `qr-render.js`, `qrcode.js`).
- Adding `penalties.spec.ts` to the CI e2e list. It is genuinely missing from
  `.github/workflows/ci.yml`, which is a real finding — filed as **issue #21**, not folded in.
  Only the **new** spec from this change is added to that list.
- Fixing the identical read-and-consume race in `AchievementService::takeUnseen()`. Found by
  Codex while reviewing this plan, real, and filed as **issue #22**. The new code avoids the
  bug; retrofitting the achievements path (and its tests) is a separate change.
- Adding sound to positive point events. Reusing `[data-celebrate]` would have done it for
  free via `sounds.js`; a separate marker is used precisely so no sound is added anywhere.
- Any notification/inbox UI on top of the unused `notifications` table.
- Sound design. `sounds.js` already plays its fanfare off `[data-celebrate]` and is
  untouched; no sound is added for the negative event.

---

## Invariants (vault, hook-injected)

| Id | How this change keeps it true |
|---|---|
| **INV-001 — History is never deleted** | **Preserved.** Nothing is deleted, archived or rewritten. The two new columns are *display state* (`celebrated_at`, `journal_seen_at`) written from `NULL` to a timestamp exactly once; no economic field (`coins_delta`, `xp_delta`, `type`, `title`, `created_at`) is ever touched, and no row is ever removed. The Journal keeps rendering every entry forever, seen or not — the mark says "new", it never hides anything. This is the same mechanism `child_achievements.seen_at` has used since T8-T12. |
| **INV-002 — Coins/XP flow only through the ledger** | **Preserved.** This change reads the ledger and writes only seen-state. No balance is written, no transaction is created, `LedgerService::post()` is not called and its signature is not widened. The mood decision is derived *from* `coins_delta`/`xp_delta`/`type`, never from `children.coin_balance`. |
| **INV-003 — Production runs on plain shared hosting** | **Preserved.** No new PHP extension, no build step, no daemon, no cron. The migration uses the repo's existing `information_schema`-probe idiom (migration 003), which works on MySQL and MariaDB alike — not MariaDB-only `ADD COLUMN IF NOT EXISTS`. The rain is CSS the browser already parses. |
| **INV-006 — Emitted CSS/JS carries the version token, SW shell matches** | **Preserved, trivially: no asset file is added or removed.** The rain lives in `public-assets/css/kid.css` and `public-assets/js/celebrate.js`, both already emitted with `asset()` and already in `sw.js`'s `VERSIONED_PATHS`. `sw.js` is not edited and `tests/Unit/ServiceWorkerShellTest.php` needs no change. Adding a `rain.js` was rejected for exactly this reason (see "Rejected alternatives"). |
| **INV-008 — Uploads are never web-servable, type always re-derived** | **Untouched and preserved.** No upload path, no `$_FILES`, no `transaction_photos` write. `views/kid/journal.php` is edited nowhere near the `has_photo` branch; the photo route and `PenaltyPhotoStore` are not opened. |

---

## What ships

### 1. Two seen-state columns on `transactions` (migration 008)

```sql
ALTER TABLE transactions ADD COLUMN celebrated_at   DATETIME NULL AFTER created_at;
ALTER TABLE transactions ADD COLUMN journal_seen_at DATETIME NULL AFTER celebrated_at;
ALTER TABLE transactions ADD KEY ix_transactions_child_celebrated (child_id, celebrated_at);
ALTER TABLE transactions ADD KEY ix_transactions_child_journal    (child_id, journal_seen_at);
-- Backfill: everything that already happened has already been lived through.
-- Bounded by a DURABLE watermark, so a replay can never consume a NEW event.
UPDATE transactions SET celebrated_at = created_at, journal_seen_at = created_at
 WHERE id <= :watermark AND (celebrated_at IS NULL OR journal_seen_at IS NULL);
```

Each DDL statement is guarded by its own `information_schema` probe (COLUMNS for the columns,
STATISTICS for the keys), exactly as `003_reward_request_cost_snapshot.php` does — portable
across MySQL and MariaDB, no MariaDB-only `ADD COLUMN IF NOT EXISTS`. `down()` drops both keys,
both columns **and** the watermark row, so `up → down → up` is clean.

#### Why the backfill needs a durable watermark (Codex round 1, HIGH)

MariaDB DDL is non-transactional, so `up()` can die **between** the `ALTER`s and the `UPDATE`.
Guarding the backfill on "do the columns exist?" is then exactly wrong in both directions: a
crash after the ALTER but before the DML leaves the columns present and the history
un-backfilled (so the retry skips it and the child is greeted by their entire history), while
an unguarded re-run would consume legitimately new events on every later `migrate()`.

The fix is one durable row written **before** the DML, in the `settings` table that migration
001 already creates:

The order is **watermark → DDL → backfill**, and each step is separately replay-safe:

1. **Transaction A — fix the watermark, before anything else happens.**
   1. `SELECT write_locked FROM ops_state WHERE id = 1 FOR UPDATE`. If the row is missing,
      abort: `WriteGate` treats that as an inconsistent install and so does this.
   2. Read `settings` for key `migration.008.backfill_max_id`. If absent, capture
      `COALESCE(MAX(id), 0)` from `transactions` and insert it.
   3. COMMIT.
2. **DDL** — the probed `ALTER`s. Not inside a transaction: MariaDB DDL causes an implicit
   commit, so putting it in one would be a lie.
3. **The backfill**, using the watermark read back from `settings`:
   `UPDATE transactions SET celebrated_at = created_at, journal_seen_at = created_at
   WHERE id <= <watermark> AND (celebrated_at IS NULL OR journal_seen_at IS NULL)`.

**Why the watermark is committed BEFORE the DDL (Codex round 6, HIGH).** An earlier revision
ran the DDL first, which reintroduced round 2's bug in a new place: a crash after the `ALTER`s
but before the watermark was stored leaves an install with the columns present and no
watermark, so the retry captures a *later* `MAX(id)` and backfills everything that committed in
between as already seen. Storing the watermark first makes every crash point recoverable
against the **same** boundary:

| Crash point | Retry behaviour |
|---|---|
| Before / during transaction A | Nothing was written. The retry captures the watermark cleanly. |
| After A, before the `ALTER`s | Watermark present and reused; DDL and backfill run against it. |
| After the `ALTER`s, before the backfill | Watermark present and reused — the round-2 case, now genuinely covered. |
| Mid-backfill | The predicate is idempotent (`… IS NULL`), so the retry completes it. |

**The backfill itself needs no gate**, only the capture does: every id at or below the
watermark is already committed (that is what transaction A proves), and no future insert can
land at or below it. Rows created after the migration started keep `NULL` on both columns and
celebrate normally — which is correct, because they *are* new.

#### Why `MAX(id)` is only a valid boundary under the write gate (Codex round 5, MEDIUM)

The plan itself argues, when rejecting a per-child watermark, that `AUTO_INCREMENT` ids follow
**allocation** order and not **commit** order. That argument applies to this migration too, and
it must not be answered with a weaker standard just because it is a one-off: a transaction can
reserve id 100, the migration can capture `MAX(id) = 105`, and that transaction can then commit
— at which point the backfill has marked a genuinely new event as already seen, and the child
silently loses it.

The fix is not a better boundary, it is **removing the concurrency**, using the mechanism this
product already has for exactly that. Every domain mutation begins with
`WriteGate::assertOpen()`, whose first act is
`SELECT write_locked FROM ops_state WHERE id = 1 LOCK IN SHARE MODE`, and that shared lock is
held until the writer commits — the property `WriteGate`'s own docblock states as *"the shared
lock is held to COMMIT, so update/restore quiescence is provable."* So a single
`SELECT … FOR UPDATE` on that one row, taken as the first statement of the backfill
transaction, does both halves of the job:

- it **blocks until every in-flight ledger writer has committed** (their share locks must be
  released before our exclusive lock is granted), and
- it **prevents any new one from starting** for as long as we hold it.

`MAX(id)` read after that lock — in **transaction A above, before any DDL** — is therefore a
true boundary: every id at or below it is committed, and every insert that happens afterwards
necessarily gets a higher id. The allocation-vs-commit gap is closed rather than assumed away.

Three properties that make this the right primitive rather than a heavy one:

- **It cannot strand the installation.** The lock is an ordinary row lock released by COMMIT or
  ROLLBACK, so a crash mid-migration frees it. Setting `ops_state.write_locked = 1` for the
  duration would give the same exclusion but a stuck flag would leave the family unable to
  write anything until someone noticed — trading a silent data bug for a site-down bug.
- **It needs no privilege beyond what the app already uses.** No `LOCK TABLES`, no `GET_LOCK`,
  nothing outside plain DML — INV-003 safe on cyon.ch-class hosting.
- **The watermark is committed on its own, before the DDL** — deliberately *not* atomically
  with the backfill, which is what an earlier revision tried and what round 6 correctly
  rejected. Atomicity there would put the capture *after* the `ALTER`s and reopen the crash
  window. Replay safety comes from the watermark being durable **first** plus the backfill's
  predicate being idempotent, not from the two sharing a transaction.

Belt and braces, and worth stating because it is why this is cheap in practice: migrations run
during an update, when `index.php`'s boot gate is already 503-ing every route outside its
five-route allowlist (none of which posts to the ledger), and on a fresh install `transactions`
is empty. The row lock is what makes the property *true*; the boot gate is why it never
actually waits.

**`down()` deletes the watermark LAST** (Codex round 2, MEDIUM). Order matters and is
specified rather than left to the reader: drop the two indexes, drop the two columns, and only
once **all** DDL has succeeded delete `migration.008.backfill_max_id`. The reverse order is a
trap — a crash after deleting the key but before dropping the columns leaves an install whose
columns still exist, whose seen-state is still populated, and whose watermark is gone; the next
`up()` would then capture a *new*, much higher `MAX(id)` and backfill every event that arrived
in between, silently consuming celebrations nobody had seen. Deleting the key last means a
crash anywhere in `down()` leaves the watermark intact, so a retry of either direction is
bounded by the original id.

The result is an `up()` that is idempotent **and** replay-safe from any crash point, because
the predicate is a fixed id bound rather than a re-derived one: `id <= watermark` can only
ever match rows that existed when the migration first started, whatever happens afterwards.
A fresh install stores `0` and the backfill matches nothing. Re-running the `UPDATE` after it
already succeeded is a no-op (`celebrated_at IS NULL OR journal_seen_at IS NULL` is false for
every row it touched).

An id bound is used rather than a timestamp because `AUTO_INCREMENT` only ever grows, so
"created after the migration began" and "id greater than the watermark" are the same set,
while `created_at` has second granularity and could tie with a row inserted in the same
second.

**Two independent columns, not one.** They are consumed at different moments and must not
clear each other: the confetti/rain is consumed on `/kid`, the unread mark on
`/kid/journal`. One column would mean the badge vanished before the child ever opened the
Journal — the exact thing the operator asked for would be destroyed by the thing they
asked for next to it.

**The backfill is load-bearing, not tidiness.** Without it, the first `/kid` load after the
update would consume the child's entire history at once — confetti *and* rain
simultaneously — and the Journal would advertise "99+" on an install where nothing new
happened. Asserted by a test, not assumed.

#### Why columns on `transactions`, when migration 007 deliberately chose a side table

007's docblock says a photo got its own table so *"the append-only ledger keeps its exact
shape and `LedgerService::post()`'s signature is not widened by a concern exactly one
caller has"*. Both halves of that reasoning are checked here and neither applies:

- **The signature is not widened.** Nobody passes these values in. They default to `NULL`
  at insert and are set later by the reader. `LedgerService::post()` is not edited at all.
- **A photo is optional 1:N-shaped data with its own constraints** (unique per transaction,
  unique per path, a filesystem reference, a byte size, a MIME) — a table's worth of facts.
  "Has this child seen this row yet" is a single nullable timestamp on a row that already
  has exactly one child (`transactions.child_id`), so a side table would be a 1:1 table
  enforcing nothing.
- **The codebase already solves precisely this problem this way.** `child_achievements.seen_at`
  is a nullable timestamp on the row it describes, consumed atomically, and it is what the
  existing confetti reads. Two different mechanisms for "the child has not seen this yet"
  would be the drift, not the consistency.

#### Why a per-row flag and not a per-child high-water mark

A `children.last_seen_transaction_id` watermark would be two tiny columns and no backfill —
and it is wrong. `transactions.id` is `AUTO_INCREMENT`: id 101 can commit while id 100 is
still open, so a watermark set to 101 skips 100 **forever**. Two parents on two phones in
the same second is not exotic in a family app. This is the same class as LESSONS
2026-08-10 *"rowCount() === 0 is not evidence"* — do not infer a set of rows from a number
that only looks ordered. A per-row `IS NULL` predicate cannot skip a row, whatever order
things commit in.

### 2. `app/Domain/CelebrationService.php` (new)

```php
final class CelebrationService
{
    /**
     * Types where a negative coins_delta is the CHILD'S OWN choice — spending
     * saved Coins on a reward they picked. Raining on that would tell the child
     * that getting what they saved for is a punishment.
     */
    private const VOLUNTARY_SPENDS = ['reward_spend', 'milestone_spend'];

    public function __construct(private readonly Db $db) {}

    /**
     * Atomically read-and-consume the child's uncelebrated ledger events.
     * Same shape as AchievementService::takeUnseen(): rows are locked and
     * marked in ONE gated transaction, so two concurrent page loads can never
     * both celebrate the same event.
     *
     * @return list<array{coins_delta:int, xp_delta:int, type:string}>
     */
    public function takeUncelebrated(int $childId): array;

    /**
     * Pure. What feedback does this batch call for?
     *
     * @param  list<array{coins_delta:int, xp_delta:int, type:string}> $events
     * @return array{positive: bool, negative: bool}
     */
    public static function feedbackFor(array $events): array;
}
```

`feedbackFor()` is `static` and side-effect-free so the whole decision table is unit-tested
without a database. The rule:

| Condition | Result |
|---|---|
| `coins_delta > 0` **or** `xp_delta > 0` | `positive` |
| `coins_delta < 0` **and** `type` ∉ `VOLUNTARY_SPENDS` | `negative` |
| `coins_delta < 0` **and** `type` ∈ `VOLUNTARY_SPENDS` | neither — the child chose it |
| `coins_delta === 0 && xp_delta === 0` | neither |

Both flags can be true for one batch; that is a real state (a parent awarded *and* deducted
between two visits) and it is rendered as both, sequenced (§4).

**Why "negative" is decided on Coins alone, with no `xp_delta < 0` branch (Codex round 4,
MEDIUM).** The prose elsewhere says "every Coin/XP event", so the asymmetry has to be justified
rather than left to look like an oversight: **a negative-XP event cannot exist in this
product.** `transactions.xp_delta` is `INT UNSIGNED` at the schema level, and
`LedgerService::postInternal()` additionally throws `InvalidArgumentException` on
`$xpDelta < 0`, both of them enforcing INV-002's "XP never decreases". So `xp_delta` is only
ever a *positive* signal, which is exactly how the table uses it. Adding an `xp_delta < 0`
branch would be unreachable code asserting the opposite of a HARD invariant, and it would rot
into a claim that XP can go down. Pinned instead by test 12b, which asserts the column is
unsigned and that `LedgerService::post()` rejects a negative `xpDelta` — so if that invariant
is ever deliberately relaxed, the test fails and forces this rule to be revisited rather than
silently mis-deciding the mood.

#### The consume marks ONLY the rows it read (Codex round 1, HIGH)

The obvious implementation — `SELECT … WHERE celebrated_at IS NULL FOR UPDATE` followed by
`UPDATE … WHERE child_id = ? AND celebrated_at IS NULL` — is **wrong**, and this is the one
place the plan deliberately does *not* copy `AchievementService::takeUnseen()`:

`FOR UPDATE` locks the rows the `SELECT` found. It does not stop a row from being **inserted**
between the two statements, and that new row is then still `celebrated_at IS NULL`, so the
broad `UPDATE` marks it seen even though it was never in the returned batch. The child
silently loses that celebration, undetectably. The window is not theoretical: a parent tapping
"Eintragen" while the child's home page is loading is ordinary behaviour in this app.

So the second statement is bounded by the ids actually read — **and the read itself is
chunked**, so the `IN (…)` list can never grow without limit (Codex round 2, MEDIUM):

```php
public const CHUNK = 200;        // rows per statement
public const MAX_CHUNKS = 25;    // ≤ 5 000 rows consumed by one page load

return WriteGate::transaction($this->db, function (Db $db) use ($childId): array {
    $events = [];
    for ($pass = 0; $pass < self::MAX_CHUNKS; $pass++) {
        $rows = $db->fetchAll(
            'SELECT id, coins_delta, xp_delta, type FROM transactions
             WHERE child_id = ? AND celebrated_at IS NULL ORDER BY id LIMIT ' . self::CHUNK . ' FOR UPDATE',
            [$childId]
        );
        if ($rows === []) {
            break;
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $db->execute(
            'UPDATE transactions SET celebrated_at = UTC_TIMESTAMP()
             WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND celebrated_at IS NULL',
            $ids
        );
        foreach ($rows as $row) {
            $events[] = $row;
        }
        if (count($rows) < self::CHUNK) {
            break;
        }
    }

    return $events;
});
```

Three properties this gives, all testable:

- **Bounded statement size.** At most `CHUNK` placeholders per prepared statement, whatever the
  backlog. Without the chunking, a child returning to an install whose backfill was skipped
  would build an `IN (…)` list with one placeholder per historical row and 500 the page — a
  failure that only appears on the installs with the most history.
- **Bounded work per request.** `MAX_CHUNKS` caps one page load at 5 000 rows. If a backlog
  somehow exceeds that, the remainder simply keeps its `NULL` and drains on the next visit;
  the feedback shown is still correct, because the mood only needs *one* positive and *one*
  negative row to be right.
- **The mid-flight insert is still safe.** A row that arrives after a chunk's `SELECT` is not
  in that chunk's `$ids`. Either a later pass picks it up (and it celebrates now) or it does
  not (and it celebrates next visit). It can never be marked without being returned, which is
  the whole point.

`LIMIT` is a compile-time integer constant interpolated into the SQL, never a value from a
request. `JournalService::markSeen()` uses the identical chunked read-then-bounded-update loop,
for the identical reason.

**The pre-existing instance in `AchievementService::takeUnseen()` is a real bug with the same
shape.** It is filed as **issue #22** and deliberately NOT fixed here: fixing it means touching
the achievements path and its tests, which is a different change. This plan only guarantees the
new code does not add a second instance.

### 3. `JournalService`: two methods

```php
/** Badge cap. A dock badge is a hint, not a report — and the query must not scan a whole ledger. */
public const UNSEEN_CAP = 99;

/**
 * Unseen ledger entries, saturating at UNSEEN_CAP + 1.
 *
 * The return value is deliberately NOT the true count: it is
 * min(actual, UNSEEN_CAP + 1). A result of exactly UNSEEN_CAP + 1 is the
 * SENTINEL meaning "at least this many" and is what the view renders as "99+";
 * anything <= UNSEEN_CAP is exact.
 */
public function unseenCount(int $childId): int;

/** Mark the child's currently-unseen ledger entries as looked-at. */
public function markSeen(int $childId): void;
```

**The cap has exactly one definition (Codex round 1, LOW).** `unseenCount()` saturates at
`UNSEEN_CAP + 1 = 100` — it never returns 101, and it never returns the true count above the
cap. The query is
`SELECT COUNT(*) AS c FROM (SELECT 1 FROM transactions WHERE child_id = ? AND journal_seen_at IS NULL LIMIT 100) x`,
which rides the `(child_id, journal_seen_at)` index and stops at 100 rows regardless of how
long the ledger is. The view's rule is the mirror image and stated once:
render `$n > UNSEEN_CAP ? UNSEEN_CAP . '+' : (string) $n`. Both halves are pinned by tests
(a child with exactly 99, exactly 100 and 150 unseen entries).

`markSeen()` uses the same read-then-bounded-update shape as `takeUncelebrated()` (it marks
only ids it read), for the same reason.

### 4. `KidController`

- **`home()`** — after the existing achievement consume, add the transaction consume:

  ```php
  $feedback = ['positive' => false, 'negative' => false];
  try {
      $feedback = CelebrationService::feedbackFor(
          (new CelebrationService($this->db))->takeUncelebrated($this->childId())
      );
  } catch (WriteLockedException) {
      // A backup/restore is running. The events keep their NULL marks and
      // celebrate on the next visit — feedback is decoration, never a 500.
  }
  ```

  and pass `'feedback' => $feedback`.

- **`journal()`** — when (and only when) the child opened the **`all`** filter **and the
  request is a real, deliberate navigation** (see below), mark seen *before* computing the
  badge, wrapped in the same `WriteLockedException` guard. A filtered view
  (`?filter=deducted`) deliberately does **not** clear the mark: the child has not seen the
  other entries, so claiming they have would hide exactly what the feature exists to surface.
  The nav link points at `/kid/journal` with no filter, so tapping the badge always clears it.

#### Clearing state on a GET, and the guard that makes it deliberate (Codex round 1, MEDIUM)

"The child has looked at their Journal" is genuinely observed by the Journal being rendered,
so the clear belongs on that render — a POST would mean the nav entry could no longer be a
link, and would need a CSRF token and JavaScript on a page whose entire job is to be tapped
in a bottom dock. RFC 9110 §9.2.1 contemplates exactly this: a safe method may carry side
effects the user did not *request*, provided they cannot be held accountable for them. The
write is idempotent, non-destructive, scoped to the authenticated child's own display state,
and hides nothing — the Journal still lists every entry, seen or not (INV-001).

That argument covers *deliberate* requests. It does not cover a request the child never made,
so the plan adds a cheap, explicit guard — `Request::isUserNavigation()` in
`app/Core` — and the clear runs only when it passes:

```php
// Speculative loads. Sec-Purpose is the current header; Purpose: prefetch and
// X-Moz: prefetch are the legacy ones Chrome and Firefox still send, and Codex
// round 2 was right that checking only the first leaves those through.
// 'preview' is Safari's token on X-Purpose and means the same thing: the page
// was fetched to show a thumbnail, not because anybody looked at it.
const SPECULATIVE = ['prefetch', 'prerender', 'preview'];

foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $header) {
    $value = strtolower((string) ($_SERVER[$header] ?? ''));
    foreach (self::SPECULATIVE as $token) {
        if (str_contains($value, $token)) {
            return false;               // the child has not seen anything
        }
    }
}

// Fetch metadata. Present on every current browser; when present it is
// authoritative, when absent we fail open (see the residual risk below).
$dest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
if ($dest !== '' && $dest !== 'document') {
    return false;                       // <img>/fetch/script subresource, not a page view
}
$mode = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
if ($mode !== '' && $mode !== 'navigate') {
    return false;
}

// A top-level navigation STARTED BY ANOTHER SITE carries a Lax cookie, so it
// reaches us authenticated. The child did not choose to look at their Journal;
// somebody else's link did. Same-origin, same-site and 'none' (typed URL,
// bookmark, PWA launch) all pass.
if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
    return false;
}

return true;
```

This covers every case Codex named across all three rounds: browser prefetch, prerender **and**
Safari's `preview`, across all four headers; a cross-site `<img src>`/`fetch()` subresource; a
crawler that follows links as subresources; and a **cross-site top-level navigation** whose
`SameSite=Lax` cookie would otherwise make it look like a real visit. Normal use is unaffected:
an in-app link click is `same-origin`, a typed URL / bookmark / PWA launch is `none`, and
browser back/forward is a `navigate`/`document` request with the same site value as the
original — all pass.

**The cost of rejecting `cross-site`, and why it is the right side to err on (Codex round 3,
LOW).** A child who reaches their Journal by tapping a link on *another* site sees the page but
does not clear the badge. Nothing is lost — state is **deferred, never destroyed**: the entries
are all still listed, and the badge clears on their very next in-app navigation, which for this
feature is the normal path anyway (the badge *is* a dock entry, and tapping it is by
construction `same-origin`). The opposite choice — trusting a cross-site navigation — means any
page on the internet can silently clear a signed-in child's "there is something new" hint by
linking at it, which is precisely the accidental-clear class this guard exists to close. A
deferred hint is a smaller harm than a hint somebody else can switch off.

**Residual risk, stated rather than hidden:** a browser that sends no Fetch-metadata headers at
all (pre-2023 Safari, old embedded webviews) gets the fail-open path, so a speculative load on
such a browser could still clear the badge one visit early. That is accepted deliberately:
failing *closed* would mean the feature silently never works on those browsers, which is worse
than the harm — the Journal still lists every entry (INV-001), so the cost is one missed
"there is something new" hint, not lost information. It is also strictly better than the status
quo, since `AchievementService::takeUnseen()` runs today with no guard at all. A hard guarantee
would need a CSRF-protected POST, which would cost the nav entry its ability to be a plain
link; that trade is not worth making for a hint.

The guard composes with the two protections that already exist: `$requireChild` (the request
must carry an authenticated child session at all) and `SameSite=Lax` cookies (which already
keep a cross-site *subresource* request from carrying that session).

**The same guard wraps the `home()` celebration consume**, so a prefetched `/kid` cannot eat
the confetti either. `AchievementService::takeUnseen()`'s existing unguarded call on the same
route is left exactly as it is — widening this change into the achievements path is what
issue #22 is for.

- **All seven `layouts/kid` renders** (`home`, `settings`, `sidequests`, `rewards`,
  `milestones`, `achievements`, `journal`) gain `'journalUnseen' => $this->journalUnseen()`.
  Explicitly, one key per call — no refactor of the render calls, and the layout still reads
  `(int) ($journalUnseen ?? 0)` so a future kid page that forgets it degrades to "no badge"
  instead of a warning.

  `journalUnseen()` is a two-line private helper that returns `0` on `WriteLockedException`
  /`Throwable`, matching the parent layout's own precedent (`views/layouts/parent.php:33-35`
  catches `\Throwable` around its badge with the comment *"Badge is decoration — never break
  the page for it"*).

### 5. Views

**`views/layouts/kid.php`** — the Journal nav item, and only that item:

```php
<a href="<?= e(url('/kid/journal')) ?>" class="kid-nav-item"<?= $kidCurrent('/kid/journal') ?><?=
    $journalUnseen > 0 ? ' aria-label="' . eattr(t('kidnav.journal_new', ['count' => $journalUnseenLabel])) . '"' : '' ?>>
    <?php if ($journalUnseen > 0): ?><span class="kid-nav-badge"><?= e($journalUnseenLabel) ?></span><?php endif; ?>
    <span aria-hidden="true">📖</span><span><?= e(t('kidnav.journal')) ?></span>
</a>
```

`$journalUnseenLabel` is a **layout-local**, not a passed data key (see "Lessons followed"):
the layout derives it from `$journalUnseen` and `JournalService::UNSEEN_CAP`.

Four deliberate details:

- **The badge is the FIRST child, not the last.** `kid.css:161` is
  `.kid-nav-item span:last-child { display:block; width:100%; text-overflow:ellipsis; … }` —
  a descendant selector. A badge appended last would steal that rule (and strip the label's
  ellipsis truncation). Placing it first leaves the existing rule pointing at the label,
  unchanged. It is absolutely positioned, so DOM order costs nothing visually.
- **No `aria-hidden` on the badge**, because `kid.css:162` styles `span[aria-hidden]` at
  `1.3rem` (the emoji size).
- **The accessible name comes from `aria-label` on the link**, so a screen reader says
  *"Journal — 3 neu"* instead of *"3 book Journal"*. This avoids a nested visually-hidden
  span, which would itself be a `:last-child` and collide with the same rule. There is no
  `.sr-only` utility in this codebase and this change does not introduce one.

**`views/kid/home.php`** — two conditional blocks, above the existing celebration card:

```php
<?php if ($feedback['negative']): ?><?php require __DIR__ . '/../partials/_rain_cloud.php'; ?><?php endif; ?>
<?php if ($feedback['positive']): ?><div class="event-celebrate" data-celebrate-events="1" hidden></div><?php endif; ?>
```

#### A separate marker, so no sound is added anywhere (Codex round 1, MEDIUM)

Reusing the existing `data-celebrate` attribute was tempting — it would make confetti work
with zero JavaScript changes — but `public-assets/js/sounds.js:59-66` plays its fanfare off
**the same selector**. Reusing it would therefore add a fanfare to every ordinary Coin award,
which is a sound this change never declared and the operator never asked for. That is exactly
the scope creep the "Surgical scope" section forbids, arriving through a shared selector
rather than through a new file.

So the transaction celebration gets its **own** marker, `data-celebrate-events`, and
`celebrate.js` fires on either:

```js
var trigger = document.querySelector('[data-celebrate], [data-celebrate-events]');
```

`sounds.js` is not edited and keeps matching only `[data-celebrate]`, so the fanfare stays
exactly where it is today: a fresh achievement unlock. The negative case carries neither
attribute — rain has no sound at all.

Adding sound to point awards may well be a good idea; it is a **deliberate non-goal here** and
a one-line follow-up if the operator wants it.

**`views/partials/_rain_cloud.php`** (new) — a fixed, `aria-hidden`, `pointer-events: none`
overlay: one `.rain-cloud` element and twelve `.rain-drop` spans, no text, no image, no
inline style.

### 6. `public-assets/css/kid.css`

- `.kid-nav-item { position: relative; }` — added to the existing rule so the badge can be
  positioned. `overflow: hidden` is **kept**; the badge sits inside the box.
- `.kid-nav-badge` — copied from the proven `.kid-tile-badge` (`kid.css:85-90`), sized down
  for a 52 px dock item. **It declares `color: #fff` next to its `background`**, per
  ARCHITECTURE.md's v0.1.5 rule and issue #16: an app-styled control owns its foreground.
- `.rain-fx` / `.rain-cloud` / `.rain-drop` + two `@keyframes`. The cloud is drawn from a
  border-radius box plus `::before`/`::after` lobes in dark slate — **not an emoji**, because
  ☁️/🌧️ render differently on every platform and the operator asked specifically for a *dark*
  cloud. Drop stagger comes from `:nth-child()` rules, never from an inline
  `style="--delay"`: `index.php:131` ships `style-src 'self'` with no `'unsafe-inline'`.
- Total scene ≈ **2.6 s** (fade in, rain, fade out), inside the operator's "2–3 seconds".
- `@media (prefers-reduced-motion: reduce) { .rain-fx { display: none; } }` — explicit,
  rather than relying on `kid.css:166`'s global `animation-duration: .01ms !important`,
  which would otherwise make the scene flash and jump. This mirrors confetti's own
  `disableForReducedMotion: true`. A reduced-motion child still gets the Journal badge, which
  is the non-animated half of the same information.

### 7. `public-assets/js/celebrate.js`

Two small additions to the existing IIFE; the file stays under 60 lines.

- **Teardown, belt and braces.** The CSS ends the scene invisible *and* JS removes the node
  after 3 s. Either alone has a failure mode: a browser or extension that suppresses CSS
  animations would leave the final keyframe unapplied and strand a dark cloud on screen
  (harmless to touch — `pointer-events: none` — but wrong), while JS alone would depend on a
  script that may not run.
- **Sequencing.** When a rain scene is present, the confetti is fired after it
  (`setTimeout`, ~2.8 s) instead of on top of it. Confetti bursting through a rain cloud
  reads as neither. Order is deliberate: the consequence first, then the reward — so the
  screen the child is left looking at is the celebratory one. With no rain the delay is `0`,
  so today's achievement confetti is byte-for-byte unchanged in behaviour.
- No inline styles, no `element.style` writes, no new global, no new file.

### 8. Demo data, translations, release bookkeeping

- **`DemoSeeder`** marks its seeded transactions `celebrated_at`/`journal_seen_at`
  immediately after `spreadTimestamps()`, next to the existing
  `$achievements->markSeen($emma)` and its comment *"Old unlocks are 'seen' — only future
  ones should celebrate."* Without this, a fresh demo install greets the child with confetti,
  rain and a "99+" badge for a story that is 21 days old, and the CI screenshot job (which
  runs first, on the pristine seed) captures a rain cloud over every screenshot.
- **`lang/de.php`, `lang/en.php`** — one new key each: `kidnav.journal_new`
  (`'Journal — {count} neu'` / `'Journal — {count} new'`), placed in the existing
  `// Kid nav` group.
- **`VERSION`** → `0.1.7`; **`CHANGELOG.md`** gains a `## [0.1.7]` section in the
  established end-user prose; **`site/index.html:40`** `"softwareVersion"` → `0.1.7`, because
  `tests/Unit/LandingPageSeoTest.php` pins the page to the `VERSION` file (LESSONS
  2026-08-11 — two branches, one shared version claim).
- **Dev-stack note, not a code change:** bumping `VERSION` locally 503s the whole stack until
  `settings.app.version` matches (`index.php:47-83`, LESSONS 2026-08-11). The Teilaufgabe
  that bumps the version also runs
  `UPDATE settings SET value = '"0.1.7"' WHERE \`key\` = 'app.version'` against the dev
  database, *before* any Playwright validation.

---

## Lessons followed (vault 🔥 matches on the files this change touches)

**Avoids 2026-08-10 — *"extract(EXTR_SKIP) makes a shared helper's local variables part of
its public API"* (issue #12/#14).** This plan introduces new view-data keys, which is exactly
the hazard: `View::renderFile()` populates the template scope from caller data, and since the
#14 fix it owns `$__view`/`$__data`/`$__file` and **throws** on a reserved or unusable key
rather than silently dropping it. Two concrete steps rather than a hope:

- The new keys are `feedback`, `journalUnseen` — checked against every local that can already
  exist in the scope they land in: `renderFile()`'s `$__view`/`$__data`/`$__file`, the layout
  contract's `$content`/`$title`/`$child`, and `views/layouts/kid.php`'s own
  `$currentPath`/`$kidCurrent`. No collision, and none of them is a name a view would
  plausibly want later.
- `$journalUnseenLabel` is **not** passed as data. It is computed inside the layout from
  `$journalUnseen`, so it lives in a scope no caller can populate — the same resolution #14
  reached ("when a guard needs local variables, put them somewhere the hazard does not
  reach"). The layout opens with
  `$journalUnseen = (int) ($journalUnseen ?? 0);` followed by
  `$journalUnseenLabel = $journalUnseen > \FamilyCastel\Domain\JournalService::UNSEEN_CAP ? \FamilyCastel\Domain\JournalService::UNSEEN_CAP . '+' : (string) $journalUnseen;`
  — the cap constant is referenced, never re-typed as a literal.
- The lesson's other half — *"the templates area had zero coverage"* — is answered by e2e
  tests 28-30, which open the affected pages rather than only asserting the domain.

**Avoids 2026-08-10 — *"a shipped fix the browser never downloads is not a shipped fix"*
(INV-006).** The rain is CSS in `kid.css` and behaviour in `celebrate.js`: both are files a
returning visitor may hold a heuristically-cached copy of, and no `Cache-Control` ships. This
is precisely the failure shape where the feature "does not work" on a real phone while being
perfectly correct on disk. Two consequences already in the plan, made explicit here:

- Shipping this **requires** the `VERSION` bump (Teilaufgabe 9). Within one version the token
  does not move, so the change would not reach an existing install. The bump is not release
  bookkeeping here, it is the delivery mechanism.
- During the §7 validation the delivered bytes are compared with disk
  (`curl -o - '<app>/public-assets/css/kid.css?v=0.1.7' | diff - public-assets/css/kid.css`)
  and the live CSSOM is queried for the `.rain-fx` rules, rather than concluding "it works"
  from a screenshot. Reading the status code **and** the body length is the cheap step that
  separates *not served* from *served but stale* from *served and fine*.
- Locally, a hard reload is required after editing CSS/JS within a version — the documented,
  accepted cost of one global token.

**Avoids 2026-08-11 — *"bumping VERSION locally 503s the dev stack, and it looks like the
whole test suite broke"*.** Teilaufgabe 9 runs
`UPDATE settings SET value = '"0.1.7"' WHERE \`key\` = 'app.version'` in the same step as the
`VERSION` edit, *before* any Playwright run. And the diagnostic is pre-committed: if a later
suite fails broadly across specs this change does not touch, the first action is
`curl -o /dev/null -w '%{http_code}' http://localhost:8090/` — breadth of failure is evidence
about the environment, not about the diff. Test output is written to a file and grepped, never
piped through `tail -N`, so the summary line cannot be lost.

**Not applicable, stated once:** LESSONS 2026-08-14 *"flock is the wrong mutex"* and
*"an ambiguous exception is not evidence of a rollback"* concern the penalty **photo**
filesystem/database split. This change writes no files and runs no compensation — its only
writes are two single-statement `UPDATE`s inside `WriteGate::transaction()` — so there is no
filesystem/database split to get wrong. INV-008 is untouched (see the invariants table).

---

## Rejected alternatives

| Option | Why not |
|---|---|
| Fire confetti from the award/penalty **controllers** (a session flash) | Solves the two entry points named in the request and leaves every other one — negative quick-action templates, `adjustment`, `reversal`, Sidequest approval itself — exactly as silent as today. It is the shape of the bug being reported. Also: a flash is consumed by whichever page renders next, which for a parent-initiated award is a *parent* page. |
| A new `public-assets/js/rain.js` | A new asset file must be emitted with `asset()` from a view **and** added to `sw.js`'s `VERSIONED_PATHS` **or** to `ServiceWorkerShellTest::NOT_PRECACHED` (issue #10 exists precisely because that pairing was missed). For ~30 lines that only ever run on the same page as `celebrate.js`, that is a second network request and a new place for the INV-006 pairing to drift. Extending `celebrate.js` — the file whose entire job is "show the child what just happened" — keeps cohesion and touches no shell contract. |
| Rain as an animated GIF/SVG asset | Same INV-006/precache cost, plus bytes in the release ZIP, plus a raster that cannot follow the theme. CSS costs nothing and is already versioned. |
| An emoji cloud (`🌧️`/`☁️`) | Renders as a *light* cloud on most platforms and differs per OS; the operator asked for a **dark** cloud. Also font-dependent on a page that already ships its own fonts. |
| Drive the badge off the unused `notifications` table | It has `read_at` and child rows are already written — but only for approvals and achievement unlocks, so a plain "Eigene Aktion" award (the operator's example) produces **no** notification row at all. It would miss the very case being asked for. Left untouched. |
| One combined `seen_at` column | Consuming the celebration on `/kid` would clear the Journal badge before the child ever opened the Journal. |
| `children.last_seen_transaction_id` watermark | Skips rows forever under concurrent commits (see §1). |

---

## Teilaufgaben

Executed strictly one after another; each ends green before the next starts.

1. **Migration + watermark-bounded backfill.**
   `app/Database/Migrations/008_transaction_feedback_state.php` with the `information_schema`
   guards, the durable `migration.008.backfill_max_id` settings row and a reversible `down()`.
   RED first: tests 13, 14, 26, 27.
2. **`CelebrationService::feedbackFor()`** — the pure decision table.
   RED first: `tests/Unit/CelebrationMoodTest.php` (tests 1-12).
3. **`CelebrationService::takeUncelebrated()`** — the bounded atomic read-and-consume.
   RED first: tests 15-19, 23, 24 and the mid-flight-insert test 25.
4. **`JournalService::unseenCount()` + `markSeen()`.** RED first: tests 20-22, the saturating
   cap at 99/100/150, and `markSeen()`'s own mid-flight-insert case.
5. **`app/Core/Request::isUserNavigation()`** — the Fetch-metadata guard.
   RED first: `tests/Unit/RequestNavigationTest.php` (test 28).
6. **`KidController`** — the two consumes behind the navigation guard, the seven
   `journalUnseen` keys, the `WriteLockedException` guards, and `markSeen()` only on the
   `all` filter.
7. **Views + CSS + JS** — the nav badge, the rain partial, the `data-celebrate-events`
   marker, the `kid.css` block, the `celebrate.js` selector widening, teardown and sequencing.
8. **`DemoSeeder`** marks its story seen; `lang/de.php` + `lang/en.php` keys.
9. **E2E** `e2e/specs/celebration.spec.ts`, and add it to `.github/workflows/ci.yml`'s e2e
   spec list.
10. **Release bookkeeping** — `VERSION` → 0.1.7, `CHANGELOG.md`, `site/index.html`
   `softwareVersion`, and the dev-stack `settings.app.version` UPDATE.

---

## Edge cases

| Case | Decided behaviour |
|---|---|
| A batch holds both a positive and a negative event | Both play: rain first (~2.6 s), then confetti. Both facts are true and the operator asked for both signals. |
| The child never opens `/kid`, only `/kid/journal` | The Journal mark clears; the celebration stays queued and fires when they next open the castle. The two columns are independent by design. |
| The child opens `/kid/journal?filter=deducted` | The mark is **not** cleared — they have not seen the other entries. |
| A reward redemption (`reward_spend`, negative coins) | **No rain.** The child chose to spend saved Coins; it still counts for the Journal badge. |
| `adjustment` / `reversal` with a negative delta | Rain. These are corrections applied *to* the child, not choices *by* the child. |
| A transaction with `coins_delta = 0` and `xp_delta > 0` | Confetti (something was gained). |
| A transaction with both deltas `0` | No feedback; still counted by the Journal badge, because a new row genuinely appeared. |
| `prefers-reduced-motion: reduce` | No confetti (already), no rain (`display: none`). The Journal badge — the static half of the signal — still shows. |
| A backup/restore is running (`WriteGate` shut) | Every new call is wrapped: the celebration stays queued, the badge renders `0`, the page renders. This change adds **no** new way for a kid page to 500. |
| JavaScript disabled | No confetti (already true today). The rain still plays and still ends, because the CSS animation ends invisible. The badge is server-rendered. |
| CSS animations suppressed | The rain node is removed by the 3 s JS teardown. |
| More than 99 unseen entries | The badge renders `99+`; the count query is capped so it never scans the whole ledger. |
| A fresh install / fresh demo seed | Backfill + `DemoSeeder` marking mean the child's first visit is quiet. |
| An update on an existing install | Backfill means the first post-update visit is quiet. |
| Two tabs load `/kid` at the same moment | `FOR UPDATE` + the gated transaction: exactly one of them celebrates. |
| A parent posts an award **while** `/kid` is mid-consume | The new row is not in the read batch, so it is not marked; it celebrates on the next visit. Guaranteed by the `WHERE id IN (…)` bound, pinned by test 25. |
| A browser prefetches or prerenders `/kid` or `/kid/journal` | `Request::isUserNavigation()` returns false (`Sec-Purpose: prefetch`/`prerender`), nothing is consumed, nothing is cleared. |
| A cross-site page embeds `/kid/journal` as an `<img>` | Blocked twice over: `SameSite=Lax` keeps the session cookie off a subresource request, and `Sec-Fetch-Dest: image` fails the navigation guard. |
| A cross-site page links directly to `/kid/journal` and the child taps it | `Sec-Fetch-Site: cross-site` — the page renders, the badge is **not** cleared. It clears on their next in-app navigation. |
| A legacy browser sends no `Sec-Fetch-*` headers | The guard fails **open** — same behaviour the app already has on `/kid` today. Not a regression, documented as residual risk rather than silent. |
| A child has more unseen events than one chunk (e.g. an install whose backfill was skipped) | The consume loops in chunks of 200, up to 5 000 per page load; the remainder drains on the next visit. No statement ever carries an unbounded `IN (…)` list. |
| `up()` crashes between the `ALTER`s and the backfill | The durable watermark is already stored, so the retry backfills exactly the pre-migration rows and touches nothing newer (test 26). |

---

## Threat model

**Auth surface: unchanged.** No route is added, removed or re-guarded. Every new read and
write is scoped by `$this->childId()` — i.e. `Auth::childId()` from the session, never an id
from the URL — behind the existing `$requireChild` guard, which already revalidates
`children.archived_at IS NULL` per request. There is no cross-child read: the badge count and
the celebration consume both carry `WHERE child_id = ?`.

**Untrusted input.** No new form, no new query parameter, no new POST. Two externally
-influenced values touch the new paths:

- the existing `?filter=` on `/kid/journal`, already validated against
  `JournalService::FILTERS` before use and not passed to the new code — `markSeen()` runs on
  an exact `=== 'all'` comparison;
- the `Sec-Purpose` / `Sec-Fetch-Dest` / `Sec-Fetch-Mode` request headers read by
  `Request::isUserNavigation()`. These are **client-controlled and treated as such**: they are
  compared against fixed lowercase literals, never interpolated, never logged, never stored,
  and they can only ever cause the app to do **less** (skip a clear). An attacker forging them
  cannot clear anybody's badge — they would first need that child's session, at which point
  they can simply open the page. So the guard is a defence against *accidental* clears
  (prefetch, prerender, subresource loads), and it is honest about that: it is not, and is not
  claimed to be, an authorisation control. Authorisation remains `$requireChild` +
  `Auth::childId()`.

**State change on a safe method.** Both new writes happen on GET. This is deliberate,
bounded and argued in §4: idempotent, non-destructive, scoped to the requesting child's own
display state, hiding nothing (INV-001), matching the shipped behaviour of
`AchievementService::takeUnseen()` on the same route, and explicitly contemplated by RFC 9110
§9.2.1. The navigation guard removes the unintentional-trigger cases. No CSRF token is
introduced because there is no state an attacker could reach that they could not reach by
simply loading the page with the session they would already need.

**Output encoding.** The badge renders an integer the application computed, through `e()`;
the `aria-label` goes through `eattr()` and `t()` with a `{count}` placeholder. No new
`/public-assets/` literal, no dynamic `asset()` path — so
`ServiceWorkerShellTest::assertScannable()` stays satisfied. No inline `<script>` or
`<style>` and no `element.style` write, so the CSP at `index.php:131`
(`script-src 'self'; style-src 'self'`) is not relaxed and does not need to be.

**Data sensitivity: none added.** `celebrated_at` / `journal_seen_at` reveal only "this
child has opened their own app", visible only to that child and their parents, both of whom
already see the full ledger. No photo, no upload, no file path.

**Blast radius if abused.** The worst a forged request could achieve is marking one's *own*
entries seen — losing one's own confetti. There is no write amplification: both marking
statements are single `UPDATE`s bounded by `child_id` and an `IS NULL` predicate, and both
sit behind `WriteGate`, so they cannot run during a backup/restore.

**Denial of service.** The two new queries per page load are index-covered
(`ix_transactions_child_celebrated`, `ix_transactions_child_journal`) and the count is
capped, so neither grows with history. The migration's one-time backfill touches a
single-family ledger (hundreds of rows), so the "no long locks on tables > 1M rows" rule is
satisfied by scale, not by luck.

---

## Tests

Every one becomes a real test.

**`tests/Unit/CelebrationMoodTest.php`** (pure, no DB)

1. A single `award` with `coins_delta > 0` → positive, not negative.
2. A single `deduction` with `coins_delta < 0` → negative, not positive.
3. `reward_spend` with `coins_delta < 0` → **neither** (the child's own choice).
4. `milestone_spend` with `coins_delta < 0` → neither.
5. `adjustment` with `coins_delta < 0` → negative.
6. `reversal` with `coins_delta < 0` → negative.
7. `reversal` with `coins_delta > 0` → positive.
8. `xp_delta > 0` with `coins_delta === 0` → positive.
9. `coins_delta === 0 && xp_delta === 0` → neither.
10. A mixed batch (award + deduction) → **both** flags true.
11. An empty batch → both false.
12. Every value of the `transactions.type` enum is covered by at least one case, asserted by
    enumerating the enum in the test so a future type cannot be added silently.
12b. **XP can only be a positive signal** (integration, since it needs the schema): the
    `transactions.xp_delta` column is `INT UNSIGNED`, and `LedgerService::post()` rejects a
    negative `xpDelta` with `InvalidArgumentException`. This is what licenses `feedbackFor()`
    to decide "negative" on Coins alone — if INV-002 is ever relaxed, this test fails first and
    forces the mood rule to be revisited.

**`tests/Integration/TransactionFeedbackTest.php`** (real MariaDB, `connect()`/`wipe()` copied
verbatim from `PenaltyServiceTest`)

13. Migration 008 is re-runnable (`migrate()` twice) and reversible (`rollbackAll()` drops
    both columns and both keys).
14. Rows that existed before the migration are backfilled to `created_at` — i.e. an install
    that updates does not celebrate its own history.
15. A transaction created by `LedgerService::post()` lands with `celebrated_at IS NULL` and
    `journal_seen_at IS NULL`.
16. `takeUncelebrated()` returns the pending events and a second call returns `[]`.
17. `takeUncelebrated()` returns only the given child's events; the sibling's stay `NULL`.
18. `takeUncelebrated()` does not touch `journal_seen_at` (the badge survives the confetti).
19. `takeUncelebrated()` throws `WriteLockedException` when `ops_state.write_locked = 1`,
    and marks nothing.
20. `unseenCount()` counts new transactions and ignores the sibling's; with **99** unseen it
    returns 99, with **100** it returns 100, and with **150** it still returns 100 — the
    saturating sentinel, pinned at both edges.
21. `markSeen()` zeroes the count for that child only, and does not touch `celebrated_at`.
22. `markSeen()` is a no-op when there is nothing unseen (no error, count stays 0).
23. Neither call mutates any economic column: `coins_delta`, `xp_delta`, `type`, `title` and
    `created_at` are byte-identical before and after (INV-001/INV-002 pinned, not assumed).
24. A penalty recorded through `PenaltyService::record()` shows up as an uncelebrated
    negative event — the operator's exact minus-points case, end to end through the domain.
25. **The consume marks only what it read.** A row inserted *between* the read and the update
    keeps `celebrated_at IS NULL`. Driven deterministically by calling the two halves through
    reflection with an insert in between (the shape `PenaltyServiceTest` already uses for its
    advisory-lock tests), so it fails without the `WHERE id IN (…)` bound. Same test for
    `markSeen()`.
26. **The migration's backfill is replay-safe.** Simulate the crash: run the watermark +
    `ALTER`s, insert a NEW transaction, then run `up()` again. The pre-existing rows are
    backfilled and the new row is still `NULL` on both columns — the watermark is what makes
    this pass, and removing it makes it fail.
27. `migration.008.backfill_max_id` is **committed before the columns exist** — assert it is
    present while `information_schema` still reports no `celebrated_at` column, which is what
    pins the watermark → DDL → backfill order against a future refactor. It is stable across a
    re-run, and `down()` removes it only after all DDL has succeeded, so a `down()` that fails
    part-way leaves the watermark intact.
27b. **The backfill excludes concurrent ledger writers.** From a **second connection** (as
    `PenaltyServiceTest` does for the advisory lock), hold `ops_state` with
    `SELECT write_locked FROM ops_state WHERE id = 1 LOCK IN SHARE MODE` inside an open
    transaction, then run the backfill with a short lock-wait timeout: it must **block/fail
    rather than proceed**, proving it does not read `MAX(id)` while a writer is in flight.
    Release, re-run, and assert it then completes and that a transaction committed *after* the
    watermark keeps `celebrated_at IS NULL`.
28. **A backlog larger than one chunk drains correctly.** With `CHUNK + 7` unseen
    transactions, one `takeUncelebrated()` returns them all, marks them all, and a second call
    returns `[]` — proving the loop terminates and the partial final chunk breaks out. Same
    for `markSeen()`.
29. `Request::isUserNavigation()` (unit, in `tests/Unit/`), one case per branch:
    false for `Sec-Purpose: prefetch`, `Sec-Purpose: prerender;anonymous-client-ip`,
    `Purpose: prefetch`, `X-Purpose: preview` and `X-Moz: prefetch`;
    false for `Sec-Fetch-Dest: image` and `Sec-Fetch-Mode: no-cors`;
    false for `Sec-Fetch-Site: cross-site` even with `Dest: document` + `Mode: navigate`;
    **true** for `Dest: document` + `Mode: navigate` with site `same-origin`, `same-site` and
    `none`; and **true** when every header is absent (legacy browser — documented fail-open).

**`e2e/specs/celebration.spec.ts`** (desktop + mobile; unique `${Date.now()}` titles, short
and breakable — INV-001 makes every fixture permanent, LESSONS 2026-08-14)

29. Parent awards through **"Eigene Aktion"** → the child's `/kid` carries
    **`[data-celebrate-events]`** and a `<canvas>` appears (confetti really rendered, not just
    markup present). It must **not** carry `[data-celebrate]` — that attribute stays reserved
    for achievement unlocks, and asserting its absence is what pins "no fanfare was added to
    point awards".
30. Parent records a **Minuspunkt** → the child's `/kid` shows `.rain-fx`, and the element is
    gone within ~4 s (the scene really ends).
31. Reloading `/kid` after either does **not** replay the effect (consume-once, in the real app).
32. The Journal nav item carries `.kid-nav-badge` after a new event, and the link's
    `aria-label` names it.
33. Opening `/kid/journal` clears the badge; it stays cleared on reload.
34. Mobile: with the badge present, `.kid-nav` still has 5 items, none overflow the 390 px
    viewport, every item keeps its ≥ 44 × 48 px touch target, and the badge is not clipped by
    `.kid-nav-item`'s `overflow: hidden` (asserted via `getBoundingClientRect()` containment,
    the measurement discipline of LESSONS 2026-08-11).
35. The existing achievement confetti still works — the untouched path is pinned, not assumed.

**Regression guards already in the suite that must stay green:** the whole PHPUnit suite,
`tests/Unit/ServiceWorkerShellTest.php` (unchanged and must remain so),
`tests/Unit/LandingPageSeoTest.php` (the `VERSION`/`site` pairing), `e2e/specs/kid.spec.ts`
("bottom navigation fits…", "…never overflow the phone viewport"), and the
Domain+Core ≥ 80 % coverage gate.

---

## Files changed

| File | Change |
|---|---|
| `app/Database/Migrations/008_transaction_feedback_state.php` | **new** — two columns, two indexes, watermark-bounded backfill, reversible |
| `app/Core/Request.php` | **new** — `isUserNavigation()`, the Fetch-metadata guard |
| `app/Domain/CelebrationService.php` | **new** — bounded atomic consume + the pure mood rule |
| `app/Domain/JournalService.php` | `unseenCount()`, `markSeen()`, `UNSEEN_CAP` |
| `app/Domain/DemoSeeder.php` | mark the seeded story celebrated + seen |
| `app/Http/Kid/KidController.php` | consume on `home()`, mark on `journal()`, `journalUnseen()` on all 7 kid renders |
| `views/layouts/kid.php` | Journal nav badge + `aria-label`, defensive `?? 0` |
| `views/kid/home.php` | positive **`data-celebrate-events`** marker (never `data-celebrate` — that stays achievements-only, so no fanfare is added) + rain include |
| `views/partials/_rain_cloud.php` | **new** — the overlay markup |
| `public-assets/css/kid.css` | `.kid-nav-badge`, `.rain-*`, keyframes, reduced-motion, `position: relative` on `.kid-nav-item` |
| `public-assets/js/celebrate.js` | rain teardown + confetti sequencing |
| `lang/de.php`, `lang/en.php` | `kidnav.journal_new` |
| `tests/Unit/CelebrationMoodTest.php` | **new** |
| `tests/Unit/RequestNavigationTest.php` | **new** — the Fetch-metadata guard |
| `tests/Integration/TransactionFeedbackTest.php` | **new** |
| `e2e/specs/celebration.spec.ts` | **new** |
| `.github/workflows/ci.yml` | run the new spec |
| `VERSION`, `CHANGELOG.md`, `site/index.html` | 0.1.7 |

Not edited, on purpose: `sw.js`, `tests/Unit/ServiceWorkerShellTest.php`,
`app/Domain/LedgerService.php`, `app/Http/Parent/*`, `views/kid/journal.php`,
`public-assets/js/sounds.js`, `public-assets/vendor/confetti.js`.

---

## Risks

| Risk | Mitigation |
|---|---|
| The backfill is forgotten or wrong → every child is greeted by their whole history at once | Test 14 asserts it against real rows; test 24 proves the live path still queues correctly afterwards. |
| The badge is clipped or overflows the 52 px dock item on a small phone | `.kid-nav-item` keeps `overflow: hidden`; e2e test 30 measures containment and touch targets at 390 px, and `kid.spec.ts`'s existing overflow guard runs unchanged. |
| The new `span` steals `kid.css:161`'s `span:last-child` rule and breaks the label's ellipsis | The badge is the **first** child; test 30 asserts the dock still fits. |
| `markSeen()` on `journal()` makes a previously read-only page fail during a backup | Wrapped in `WriteLockedException` — the page renders, the badge simply stays. |
| Rain overlay strands on screen | Two independent teardowns (CSS end state + 3 s JS removal), and `pointer-events: none` throughout so it can never block a tap even in the impossible case. |
| CSP blocks the animation | No inline style and no `element.style` write anywhere; verified in the real browser during the §7 validation, where a violation would appear in the console. |
| Bumping `VERSION` 503s the dev stack and the whole e2e run reads as "everything broke" | Teilaufgabe 9 updates `settings.app.version` in the same step; LESSONS 2026-08-11 is the reason this is in the plan at all. |
| Two new queries per kid page load | Both index-covered and the count is capped; a single-family ledger is hundreds of rows. |

---

## Acceptance checklist

- [ ] A "Eigene Aktion" award produces confetti on the child's next `/kid` visit — measured in
      the real browser, `<canvas>` present, not just markup.
- [ ] A Minuspunkt produces a dark raining cloud for ~2.5 s that then disappears on its own.
- [ ] A reward redemption produces **no** rain.
- [ ] The Journal nav entry shows a badge when something new is waiting and clears when the
      child opens the Journal.
- [ ] Neither effect replays on reload.
- [ ] Existing achievement confetti is unchanged.
- [ ] Full PHPUnit suite green on PHP 8.2 and 8.3 against MariaDB; Domain+Core coverage ≥ 80 %.
- [ ] `ServiceWorkerShellTest` and `LandingPageSeoTest` green with `sw.js` untouched.
- [ ] Playwright green, desktop + mobile, including the phone-viewport dock assertions.
- [ ] Migration 008 up → down → up leaves the schema and the data consistent.
- [ ] Release ZIP builds; `VERSION`, `CHANGELOG.md` and `site/index.html` all say 0.1.7.
- [ ] Vault updated: `DATABASE.md`, `FILE_MAP.md`, `ARCHITECTURE.md`, `CHANGELOG.md`,
      `LESSONS.md`.
