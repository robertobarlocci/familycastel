<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\CelebrationService;
use FamilyCastel\Domain\JournalService;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\PenaltyPhotoStore;
use FamilyCastel\Domain\PenaltyService;
use FamilyCastel\Domain\WriteLockedException;
use PHPUnit\Framework\TestCase;

/**
 * Seen-state for ledger events, against a real MariaDB.
 *
 * The interesting assertions are not "a flag flips" but the ones about what
 * must NEVER happen: a row marked seen without having been returned, a backfill
 * that swallows an event nobody has seen yet, and any mutation of the economic
 * columns (INV-001 / INV-002).
 */
final class TransactionFeedbackTest extends TestCase
{
    private const WATERMARK_KEY = 'migration.008.backfill_max_id';

    private Db $db;
    private LedgerService $ledger;
    private CelebrationService $celebrations;
    private JournalService $journal;
    private int $childId;
    private int $otherChildId;
    private int $parentId;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();

        $this->ledger = new LedgerService($this->db);
        $this->celebrations = new CelebrationService($this->db);
        $this->journal = new JournalService($this->db);

        $this->db->execute(
            'INSERT INTO children (name, theme, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Emma', 'fantasy']
        );
        $this->childId = $this->db->lastInsertId();

        $this->db->execute(
            'INSERT INTO children (name, theme, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Noah', 'football']
        );
        $this->otherChildId = $this->db->lastInsertId();

        $this->db->execute(
            'INSERT INTO users (name, username, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Demo Parent', 'demo-parent-' . bin2hex(random_bytes(4)), password_hash('x', PASSWORD_DEFAULT), 'parent']
        );
        $this->parentId = $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->wipe();
    }

    // ---------------------------------------------------------------- helpers

    private function connect(): Db
    {
        return Db::fromParams(
            host: getenv('FC_TEST_DB_HOST') ?: '127.0.0.1',
            port: (int) (getenv('FC_TEST_DB_PORT') ?: 3306),
            name: getenv('FC_TEST_DB_NAME') ?: 'familycastel_test',
            user: getenv('FC_TEST_DB_USER') ?: 'fc',
            password: getenv('FC_TEST_DB_PASS') ?: 'fc-dev-password',
        );
    }

    private function wipe(): void
    {
        $db = $this->db ?? $this->connect();
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->fetchAll('SHOW TABLES') as $row) {
            $table = str_replace('`', '``', (string) array_values($row)[0]);
            $db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
        $db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, FC_ROOT . '/app/Database/Migrations');
    }

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

    private function watermark(): ?int
    {
        $row = $this->db->fetchOne('SELECT `value` FROM settings WHERE `key` = ?', [self::WATERMARK_KEY]);

        return $row === null ? null : (int) json_decode((string) $row['value'], true);
    }

    /** @return array{celebrated_at: ?string, journal_seen_at: ?string} */
    private function marks(int $transactionId): array
    {
        $row = (array) $this->db->fetchOne(
            'SELECT celebrated_at, journal_seen_at FROM transactions WHERE id = ?',
            [$transactionId]
        );

        return ['celebrated_at' => $row['celebrated_at'], 'journal_seen_at' => $row['journal_seen_at']];
    }

    private function award(int $childId, int $coins = 5, int $xp = 5, string $title = 'Geschirr abgeräumt'): int
    {
        return $this->ledger->post(
            childId: $childId,
            coinsDelta: $coins,
            xpDelta: $xp,
            type: $coins >= 0 ? 'award' : 'deduction',
            title: $title,
            actorUserId: $this->parentId,
        );
    }

    // ------------------------------------------------- test 13: migration 008

    public function testMigration008IsReRunnableAndReversible(): void
    {
        self::assertTrue($this->columnExists('celebrated_at'));
        self::assertTrue($this->columnExists('journal_seen_at'));
        self::assertTrue($this->indexExists('ix_transactions_child_celebrated'));
        self::assertTrue($this->indexExists('ix_transactions_child_journal'));

        $this->migrator()->migrate();   // must be a no-op, not an error
        self::assertTrue($this->columnExists('celebrated_at'));

        // down() in isolation — rollbackAll() would also drop `settings`, and
        // then "the watermark is gone" would be true for the wrong reason.
        $migration = $this->migration008();
        $migration->down();

        self::assertFalse($this->columnExists('celebrated_at'), 'down() must drop celebrated_at');
        self::assertFalse($this->columnExists('journal_seen_at'), 'down() must drop journal_seen_at');
        self::assertFalse($this->indexExists('ix_transactions_child_celebrated'));
        self::assertFalse($this->indexExists('ix_transactions_child_journal'));
        self::assertNull($this->watermark(), 'down() must remove the watermark');

        $migration->down();             // idempotent — a retry must not throw
        $migration->up();               // and up() restores everything

        self::assertTrue($this->columnExists('celebrated_at'));
        self::assertTrue($this->indexExists('ix_transactions_child_journal'));
        self::assertNotNull($this->watermark());
    }

    private function migration008(): \FamilyCastel\Database\Migration
    {
        foreach ($this->migrator()->all() as $file) {
            if ($file->version() === '008') {
                return $file->instantiate($this->db);
            }
        }

        self::fail('migration 008 not found');
    }

    // ------------------------------------------- test 14: the backfill itself

    /** Rewind ONLY migration 008, leaving every other table and its data alone. */
    private function rewind008(): void
    {
        $this->db->execute('ALTER TABLE transactions DROP INDEX ix_transactions_child_celebrated');
        $this->db->execute('ALTER TABLE transactions DROP INDEX ix_transactions_child_journal');
        $this->db->execute('ALTER TABLE transactions DROP COLUMN celebrated_at, DROP COLUMN journal_seen_at');
        $this->db->execute('DELETE FROM schema_migrations WHERE version = ?', ['008']);
        $this->db->execute('DELETE FROM settings WHERE `key` = ?', [self::WATERMARK_KEY]);
    }

    public function testExistingHistoryIsBackfilledAsAlreadySeen(): void
    {
        // An install that predates the feature: real history, then the columns
        // arrive. rollbackAll() is deliberately NOT used — it would drop the
        // children and users these rows point at.
        $first = $this->award($this->childId, 5, 5, 'Alte Geschichte');
        $second = $this->award($this->childId, 3, 0, 'Noch aeltere Geschichte');

        $this->rewind008();
        $this->migrator()->migrate();

        self::assertNotNull($this->marks($first)['celebrated_at'], 'pre-existing history must not celebrate');
        self::assertNotNull($this->marks($first)['journal_seen_at'], 'pre-existing history must not be "new"');
        self::assertNotNull($this->marks($second)['celebrated_at']);
        self::assertSame(0, $this->journal->unseenCount($this->childId));
        self::assertSame([], $this->celebrations->takeUncelebrated($this->childId));
    }

    // ------------------------------- test 15: new transactions start unmarked

    public function testANewTransactionStartsUnseenOnBothColumns(): void
    {
        $marks = $this->marks($this->award($this->childId));

        self::assertNull($marks['celebrated_at']);
        self::assertNull($marks['journal_seen_at']);
    }

    // ------------------------------------------ tests 16-19: the consume

    public function testTakeUncelebratedReturnsPendingEventsExactlyOnce(): void
    {
        $this->award($this->childId, 5, 5);
        $this->award($this->childId, -2, 0, 'Zaehne nicht geputzt');

        $first = $this->celebrations->takeUncelebrated($this->childId);
        self::assertCount(2, $first);

        $second = $this->celebrations->takeUncelebrated($this->childId);
        self::assertSame([], $second, 'a consumed event must never celebrate twice');
    }

    public function testTakeUncelebratedIsScopedToOneChild(): void
    {
        $mine = $this->award($this->childId);
        $theirs = $this->award($this->otherChildId);

        $this->celebrations->takeUncelebrated($this->childId);

        self::assertNotNull($this->marks($mine)['celebrated_at']);
        self::assertNull($this->marks($theirs)['celebrated_at'], 'a sibling\'s events must be untouched');
    }

    public function testTakeUncelebratedDoesNotClearTheJournalMark(): void
    {
        $id = $this->award($this->childId);

        $this->celebrations->takeUncelebrated($this->childId);

        self::assertNotNull($this->marks($id)['celebrated_at']);
        self::assertNull($this->marks($id)['journal_seen_at'], 'the badge must survive the confetti');
        self::assertSame(1, $this->journal->unseenCount($this->childId));
    }

    public function testAClosedWriteGateRefusesTheConsumeAndMarksNothing(): void
    {
        $id = $this->award($this->childId);
        $this->db->execute('UPDATE ops_state SET write_locked = 1 WHERE id = 1');

        try {
            $this->celebrations->takeUncelebrated($this->childId);
            self::fail('expected the write gate to refuse the consume');
        } catch (WriteLockedException) {
            self::assertNull($this->marks($id)['celebrated_at']);
        } finally {
            $this->db->execute('UPDATE ops_state SET write_locked = 0 WHERE id = 1');
        }
    }

    // --------------------------------------------- tests 20-22: the badge

    public function testUnseenCountSaturatesAtTheCapAndIgnoresSiblings(): void
    {
        self::assertSame(0, $this->journal->unseenCount($this->childId));

        $this->award($this->otherChildId);
        self::assertSame(0, $this->journal->unseenCount($this->childId), 'a sibling\'s events are not mine');

        for ($i = 0; $i < JournalService::UNSEEN_CAP; $i++) {
            $this->award($this->childId, 1, 0, 'Punkt ' . $i);
        }
        self::assertSame(JournalService::UNSEEN_CAP, $this->journal->unseenCount($this->childId));

        $this->award($this->childId, 1, 0, 'Einer zu viel');
        self::assertSame(
            JournalService::UNSEEN_CAP + 1,
            $this->journal->unseenCount($this->childId),
            'exactly at the cap + 1 the sentinel kicks in'
        );

        for ($i = 0; $i < 50; $i++) {
            $this->award($this->childId, 1, 0, 'Weiterer Punkt ' . $i);
        }
        self::assertSame(
            JournalService::UNSEEN_CAP + 1,
            $this->journal->unseenCount($this->childId),
            'the count saturates — it never reports the true number above the cap'
        );
    }

    public function testMarkSeenClearsOnlyThisChildAndKeepsTheCelebration(): void
    {
        $mine = $this->award($this->childId);
        $theirs = $this->award($this->otherChildId);

        $this->journal->markSeen($this->childId);

        self::assertSame(0, $this->journal->unseenCount($this->childId));
        self::assertSame(1, $this->journal->unseenCount($this->otherChildId));
        self::assertNotNull($this->marks($mine)['journal_seen_at']);
        self::assertNull($this->marks($mine)['celebrated_at'], 'opening the Journal must not eat the confetti');
        self::assertNull($this->marks($theirs)['journal_seen_at']);
    }

    public function testMarkSeenIsANoOpWhenNothingIsUnseen(): void
    {
        $this->journal->markSeen($this->childId);
        $this->journal->markSeen($this->childId);

        self::assertSame(0, $this->journal->unseenCount($this->childId));
    }

    // ------------------------------------- test 23: INV-001 / INV-002 pinned

    public function testNeitherCallMutatesAnyEconomicColumn(): void
    {
        $id = $this->award($this->childId, 7, 4, 'Unveraenderlich');
        $before = (array) $this->db->fetchOne(
            'SELECT child_id, coins_delta, xp_delta, type, title, description, comment,
                    actor_user_id, source_type, source_id, idempotency_key, reversal_of, created_at
             FROM transactions WHERE id = ?',
            [$id]
        );

        $this->celebrations->takeUncelebrated($this->childId);
        $this->journal->markSeen($this->childId);

        $after = (array) $this->db->fetchOne(
            'SELECT child_id, coins_delta, xp_delta, type, title, description, comment,
                    actor_user_id, source_type, source_id, idempotency_key, reversal_of, created_at
             FROM transactions WHERE id = ?',
            [$id]
        );

        self::assertSame($before, $after, 'seen-state must never touch the ledger itself');
        self::assertSame(
            1,
            (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transactions WHERE id = ?', [$id])['c'],
            'INV-001: nothing is ever deleted'
        );
    }

    // ------------------------------------ test 24: the operator's own case

    public function testAPenaltyShowsUpAsAnUncelebratedNegativeEvent(): void
    {
        $this->award($this->childId, 100, 0, 'Startguthaben');
        $this->celebrations->takeUncelebrated($this->childId);
        $this->journal->markSeen($this->childId);

        $uploads = sys_get_temp_dir() . '/fc-feedback-' . bin2hex(random_bytes(6));
        mkdir($uploads, 0777, true);

        try {
            (new PenaltyService($this->db, new PenaltyPhotoStore($uploads)))->record(
                $this->childId,
                2,
                'Zaehne nicht geputzt',
                null,
                null,
                $this->parentId,
                null,
                false
            );

            $events = $this->celebrations->takeUncelebrated($this->childId);

            self::assertCount(1, $events);
            self::assertSame('deduction', $events[0]['type']);
            self::assertSame(-2, (int) $events[0]['coins_delta']);
            self::assertSame(
                ['positive' => false, 'negative' => true],
                CelebrationService::feedbackFor($events),
                'a Minuspunkt must rain'
            );
            self::assertSame(1, $this->journal->unseenCount($this->childId));
        } finally {
            @rmdir($uploads . '/penalties');
            @rmdir($uploads);
        }
    }

    // ------------------- test 25: the consume marks ONLY what it read

    /**
     * The bug this pins: `UPDATE ... WHERE celebrated_at IS NULL` re-evaluates
     * its predicate at UPDATE time, so a row inserted between the read and the
     * write is marked seen without ever being returned — the child silently
     * loses that celebration. Driven deterministically by calling the two
     * halves through the seam rather than waiting for a real race.
     */
    public function testAMidFlightInsertIsNeverMarkedWithoutBeingReturned(): void
    {
        $early = $this->award($this->childId, 5, 5, 'Vorher');

        // The insert happens on OUR connection, from inside the consume's own
        // transaction, right after the chunk was read. That is deliberate: the
        // `FOR UPDATE` scan takes next-key locks on (child_id, celebrated_at),
        // so a genuinely concurrent insert from a second connection BLOCKS
        // until we commit (verified: it times out at innodb_lock_wait_timeout).
        // Staging it here reproduces the exact ordering the bounded UPDATE has
        // to survive — read, then a new row appears, then mark — without
        // waiting on a lock that makes the real race impossible anyway.
        $seen = [];
        $late = null;
        $events = $this->celebrations->takeUncelebrated(
            $this->childId,
            function (array $rows) use (&$seen, &$late): void {
                $seen = $rows;
                $this->db->execute(
                    'INSERT INTO transactions (child_id, coins_delta, xp_delta, type, title, created_at)
                     VALUES (?, 4, 0, ?, ?, UTC_TIMESTAMP())',
                    [$this->childId, 'award', 'Mitten drin']
                );
                $late = $this->db->lastInsertId();
            }
        );

        self::assertCount(1, $seen, 'only the pre-existing row may be read');
        self::assertNotNull($this->marks($early)['celebrated_at']);
        self::assertNull(
            $this->marks((int) $late)['celebrated_at'],
            'a row that arrived after the read must NOT be marked — an unbounded '
            . 'UPDATE ... WHERE celebrated_at IS NULL would swallow it silently'
        );

        // The consumed batch is what the child was actually shown; the late row
        // is still pending and celebrates on the next visit.
        self::assertNotContains((int) $late, array_map('intval', array_column($events, 'id')));
        $next = $this->celebrations->takeUncelebrated($this->childId);
        self::assertCount(1, $next, 'the late row celebrates on the next visit');
        self::assertSame((int) $late, (int) $next[0]['id']);
    }

    // -------------------------------- tests 26/27: replay-safe migration

    public function testTheBackfillIsReplaySafeAfterACrashBetweenDdlAndDml(): void
    {
        // Rewind to just before 008, but leave the watermark in place — this is
        // exactly the state a crash after the DDL leaves behind.
        $old = $this->award($this->childId, 5, 5, 'Vor der Migration');
        $watermarkBefore = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) AS m FROM transactions')['m'];

        $this->db->execute('UPDATE transactions SET celebrated_at = NULL, journal_seen_at = NULL');
        $this->db->execute('DELETE FROM schema_migrations WHERE version = ?', ['008']);
        $this->db->execute(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [self::WATERMARK_KEY, json_encode($watermarkBefore)]
        );

        // A genuinely NEW event arrives before the retry.
        $fresh = $this->award($this->childId, 6, 0, 'Nach dem Absturz');

        $this->migrator()->migrate();

        self::assertNotNull($this->marks($old)['celebrated_at'], 'pre-migration history is backfilled');
        self::assertNull(
            $this->marks($fresh)['celebrated_at'],
            'an event that arrived after the watermark must NOT be swallowed by a replay'
        );
        self::assertSame($watermarkBefore, $this->watermark(), 'the watermark is stable across a replay');
    }

    public function testTheWatermarkIsCapturedFromTheStateBeforeTheMigration(): void
    {
        self::assertNotNull($this->watermark(), 'a completed migration leaves its watermark behind');

        $before = $this->award($this->childId, 5, 5, 'Vor der Neu-Migration');
        $this->rewind008();
        $this->migrator()->migrate();

        self::assertSame($before, $this->watermark(), 'the watermark is MAX(id) at capture time');
        self::assertNotNull($this->marks($before)['celebrated_at'], 'and everything up to it is backfilled');
    }

    // ------------------------------ test 28: a backlog larger than one chunk

    public function testABacklogLargerThanOneChunkDrainsCompletely(): void
    {
        $total = CelebrationService::CHUNK + 7;
        for ($i = 0; $i < $total; $i++) {
            $this->award($this->childId, 1, 0, 'Rueckstand ' . $i);
        }

        $events = $this->celebrations->takeUncelebrated($this->childId);

        self::assertCount($total, $events, 'the chunk loop must drain the whole backlog');
        self::assertSame([], $this->celebrations->takeUncelebrated($this->childId));

        // Same for the journal mark.
        for ($i = 0; $i < $total; $i++) {
            $this->award($this->childId, 1, 0, 'Zweiter Rueckstand ' . $i);
        }
        $this->journal->markSeen($this->childId);
        self::assertSame(0, $this->journal->unseenCount($this->childId));
    }

    // ------------------------- test 12b: XP can only ever be a positive signal

    public function testXpCanNeverBeNegative(): void
    {
        $column = (array) $this->db->fetchOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'xp_delta'"
        );
        self::assertStringContainsString(
            'unsigned',
            strtolower((string) $column['COLUMN_TYPE']),
            'INV-002: xp_delta is UNSIGNED, which is what licenses feedbackFor() to decide "negative" on Coins alone'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->post($this->childId, 0, -1, 'award', 'XP zurueck');
    }
}
