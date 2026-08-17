<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * Seen-state for ledger events: has the child had their confetti (or their
 * rain cloud) for this transaction, and have they looked at it in the Journal?
 *
 * Two nullable timestamps ON `transactions`, not a side table (migration 007
 * chose the other way for photos, deliberately): a photo is optional 1:1 data
 * with its own constraints — unique per transaction, unique per path, a
 * filesystem reference — while "has this child seen this row" is one nullable
 * timestamp on a row that already has exactly one child. A side table here
 * would enforce nothing. It is also the shape `child_achievements.seen_at`
 * already uses for exactly this problem, and that is what the existing
 * confetti reads. INV-001/INV-002 are untouched: nothing is deleted and no
 * economic column is ever written.
 *
 * TWO columns and not one, because they are consumed at different moments and
 * must not clear each other: the celebration on /kid, the Journal badge on
 * /kid/journal. One column would destroy the badge before the child ever
 * opened the Journal.
 *
 * ---------------------------------------------------------------------------
 * ORDER: watermark -> DDL -> backfill. All three steps matter.
 *
 * The backfill exists because without it the first page load after an update
 * would consume the child's entire history at once — confetti AND rain — and
 * advertise "99+" on an install where nothing new happened.
 *
 * It is bounded by a DURABLE watermark stored in `settings` and captured
 * BEFORE any DDL, because MariaDB DDL is not transactional and up() can die
 * between the ALTERs and the UPDATE. Guarding on "do the columns exist?" is
 * wrong in both directions: a crash after the ALTER leaves history
 * un-backfilled forever, and an unguarded re-run would consume genuinely new
 * events on every later migrate(). With the watermark fixed first, every crash
 * point retries against the SAME boundary.
 *
 * The watermark is captured under an EXCLUSIVE lock on ops_state, because
 * AUTO_INCREMENT ids follow allocation order, not commit order: a transaction
 * can reserve id 100, we can read MAX(id) = 105, and it can then commit — at
 * which point the backfill would mark a brand-new event as already seen. Every
 * domain mutation starts with WriteGate::assertOpen(), which takes
 * `LOCK IN SHARE MODE` on that same row and holds it to COMMIT, so an
 * exclusive lock (a) waits for every in-flight writer to commit and (b) keeps
 * new ones out while held. MAX(id) read behind it is a true commit boundary.
 *
 * A plain row lock, released by COMMIT/ROLLBACK — deliberately NOT
 * `ops_state.write_locked = 1`, whose stuck-flag failure mode would leave the
 * family unable to write anything until somebody noticed, and deliberately not
 * LOCK TABLES, which needs a privilege we cannot assume on shared hosting
 * (INV-003).
 */
return static fn (Db $db) => new class($db) extends Migration {
    private const WATERMARK_KEY = 'migration.008.backfill_max_id';

    public function up(): void
    {
        $watermark = $this->fixWatermark();

        $this->addColumn('celebrated_at', 'ADD COLUMN celebrated_at DATETIME NULL AFTER created_at');
        $this->addColumn('journal_seen_at', 'ADD COLUMN journal_seen_at DATETIME NULL AFTER celebrated_at');

        $this->addIndex('ix_transactions_child_celebrated', 'ADD KEY ix_transactions_child_celebrated (child_id, celebrated_at)');
        $this->addIndex('ix_transactions_child_journal', 'ADD KEY ix_transactions_child_journal (child_id, journal_seen_at)');

        // Idempotent and bounded: it can only ever match rows that existed when
        // the migration first started, however many times it is replayed.
        $this->db->execute(
            'UPDATE transactions
                SET celebrated_at = created_at, journal_seen_at = created_at
              WHERE id <= ? AND (celebrated_at IS NULL OR journal_seen_at IS NULL)',
            [$watermark]
        );
    }

    public function down(): void
    {
        $this->dropIndex('ix_transactions_child_celebrated');
        $this->dropIndex('ix_transactions_child_journal');

        $this->dropColumn('celebrated_at');
        $this->dropColumn('journal_seen_at');

        // LAST, and only once every DDL above has succeeded. The reverse order
        // is a trap: a crash after deleting the key but before dropping the
        // columns would let the next up() capture a NEW, much higher MAX(id)
        // and silently consume every event that arrived in between.
        $this->db->execute('DELETE FROM settings WHERE `key` = ?', [self::WATERMARK_KEY]);
    }

    /**
     * Read the durable backfill boundary, capturing it once if this is the
     * first run. Committed before any DDL — see the file docblock.
     */
    private function fixWatermark(): int
    {
        $existing = $this->db->fetchOne('SELECT `value` FROM settings WHERE `key` = ?', [self::WATERMARK_KEY]);
        if ($existing !== null) {
            return (int) json_decode((string) $existing['value'], true);
        }

        return $this->db->transaction(function (Db $db): int {
            // Drains in-flight ledger writers and keeps new ones out until we
            // commit, so MAX(id) below is a commit boundary and not just an
            // allocation boundary.
            $gate = $db->fetchOne('SELECT write_locked FROM ops_state WHERE id = 1 FOR UPDATE');
            if ($gate === null) {
                // Fail CLOSED, exactly as WriteGate does: a missing gate row
                // means an inconsistent install, and we must not guess a
                // boundary we cannot prove.
                throw new \RuntimeException('ops_state gate row missing — refusing to capture the backfill watermark.');
            }

            // Re-read inside the lock: a concurrent migration runner may have
            // captured it between our first read and this transaction.
            $row = $db->fetchOne('SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE', [self::WATERMARK_KEY]);
            if ($row !== null) {
                return (int) json_decode((string) $row['value'], true);
            }

            $max = (int) $db->fetchOne('SELECT COALESCE(MAX(id), 0) AS m FROM transactions')['m'];
            $db->execute(
                'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())',
                [self::WATERMARK_KEY, json_encode($max, JSON_THROW_ON_ERROR)]
            );

            return $max;
        });
    }

    private function addColumn(string $column, string $clause): void
    {
        if ($this->columnExists($column)) {
            return;
        }
        $this->db->execute('ALTER TABLE transactions ' . $clause);
    }

    private function dropColumn(string $column): void
    {
        if (!$this->columnExists($column)) {
            return;
        }
        $this->db->execute('ALTER TABLE transactions DROP COLUMN ' . $column);
    }

    private function addIndex(string $index, string $clause): void
    {
        if ($this->indexExists($index)) {
            return;
        }
        $this->db->execute('ALTER TABLE transactions ' . $clause);
    }

    private function dropIndex(string $index): void
    {
        if (!$this->indexExists($index)) {
            return;
        }
        $this->db->execute('ALTER TABLE transactions DROP INDEX ' . $index);
    }

    /**
     * information_schema probes rather than MariaDB's `IF NOT EXISTS` on ALTER:
     * that extension does not exist in MySQL, and the release must run on both
     * (INV-003). Same idiom as migration 003.
     */
    private function columnExists(string $column): bool
    {
        return $this->db->fetchOne(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = ?",
            [$column]
        ) !== null;
    }

    private function indexExists(string $index): bool
    {
        return $this->db->fetchOne(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = ?",
            [$index]
        ) !== null;
    }
};
