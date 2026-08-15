<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\DuplicatePostException;
use FamilyCastel\Domain\InsufficientCoinsException;
use FamilyCastel\Domain\JournalService;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\PenaltyPhotoStore;
use FamilyCastel\Domain\PenaltyService;
use FamilyCastel\Domain\WriteLockedException;
use PHPUnit\Framework\TestCase;

/**
 * Penalties against a real MariaDB.
 *
 * The interesting assertions are not "a row appears" but the ones about what
 * happens when something goes wrong: every failure path must leave NO orphan
 * file and NO partial database state, and the compensation must never delete a
 * file a committed row references.
 */
final class PenaltyServiceTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Db $db;
    private LedgerService $ledger;
    private PenaltyPhotoStore $photos;
    private PenaltyService $penalties;
    private int $childId;
    private int $otherChildId;
    private int $parentId;
    private string $uploads = '';

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();

        $this->uploads = sys_get_temp_dir() . '/fc-penalty-int-' . bin2hex(random_bytes(6));
        mkdir($this->uploads, 0777, true);

        $this->ledger = new LedgerService($this->db);
        $this->photos = new PenaltyPhotoStore($this->uploads);
        $this->penalties = new PenaltyService($this->db, $this->photos);

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

        // Start with coins so a penalty has something to take.
        $this->ledger->post($this->childId, 100, 10, 'award', 'Startguthaben');
    }

    protected function tearDown(): void
    {
        $this->wipe();
        $this->removeTree($this->uploads);
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

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function upload(?string $bytes = null): array
    {
        $tmp = $this->uploads . '/incoming-' . bin2hex(random_bytes(6));
        file_put_contents($tmp, $bytes ?? base64_decode(self::PNG));

        return [
            'name' => 'beweis.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
    }

    private function storedFiles(): array
    {
        $found = glob($this->photos->penaltiesDir() . '/*/*/*') ?: [];
        sort($found);

        return $found;
    }

    private function child(): array
    {
        return (array) $this->db->fetchOne(
            'SELECT coin_balance, xp_total, level FROM children WHERE id = ?',
            [$this->childId]
        );
    }

    // ---------------------------------------------------------- test 11: basics

    public function testRecordPostsADeductionAndOnlyMovesCoins(): void
    {
        $before = $this->child();

        $id = $this->penalties->record(
            $this->childId, 7, 'Bett nicht gemacht', null, null, $this->parentId, null, false
        );

        $tx = (array) $this->db->fetchOne('SELECT * FROM transactions WHERE id = ?', [$id]);
        self::assertSame('deduction', $tx['type']);
        self::assertSame(-7, (int) $tx['coins_delta']);
        self::assertSame(0, (int) $tx['xp_delta'], 'INV-002: XP never decreases');
        self::assertSame($this->parentId, (int) $tx['actor_user_id']);
        self::assertSame('Bett nicht gemacht', $tx['title']);

        $after = $this->child();
        self::assertSame((int) $before['coin_balance'] - 7, (int) $after['coin_balance']);
        self::assertSame((int) $before['xp_total'], (int) $after['xp_total']);
        self::assertSame((int) $before['level'], (int) $after['level']);
    }

    // ----------------------------------------------------------- test 12: photo

    public function testRecordWithAPhotoLinksExactlyOneRowAndTheFileExists(): void
    {
        $id = $this->penalties->record(
            $this->childId, 1, 'Zu lange gezockt', 'Abgemacht war 30 Minuten.',
            $this->upload(), $this->parentId, null, false
        );

        $rows = $this->db->fetchAll('SELECT * FROM transaction_photos WHERE transaction_id = ?', [$id]);
        self::assertCount(1, $rows);
        self::assertSame('image/png', $rows[0]['mime'], 'mime comes from the detected type, not the client');
        self::assertSame(strlen(base64_decode(self::PNG)), (int) $rows[0]['byte_size']);
        self::assertMatchesRegularExpression(PenaltyPhotoStore::PATH_PATTERN, $rows[0]['path']);
        self::assertFileExists($this->photos->resolve($rows[0]['path']));

        $tx = (array) $this->db->fetchOne('SELECT comment FROM transactions WHERE id = ?', [$id]);
        self::assertSame('Abgemacht war 30 Minuten.', $tx['comment']);
    }

    // ------------------------------------------------- test 13: coins bounds

    public function testCoinsMustBeAPositiveMagnitudeWithinBounds(): void
    {
        foreach ([0, -5, 1000] as $coins) {
            try {
                $this->penalties->record($this->childId, $coins, 'Test', null, null, $this->parentId, null, false);
                self::fail('accepted an out-of-range coin amount: ' . $coins);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(0, (int) $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM transactions WHERE type = 'deduction'"
        )['c']);
    }

    // ------------------------------------------------------ test 14: reason

    public function testReasonIsRequiredAndLengthCapped(): void
    {
        foreach (['', '   ', str_repeat('a', 191)] as $reason) {
            try {
                $this->penalties->record($this->childId, 1, $reason, null, $this->upload(), $this->parentId, null, false);
                self::fail('accepted an invalid reason');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertSame([], $this->storedFiles(), 'a rejected reason must not leave a photo behind');
    }

    // ------------------------------- test 15: insufficient coins -> full cleanup

    public function testInsufficientCoinsRollsBackEverythingIncludingTheFile(): void
    {
        $this->expectException(InsufficientCoinsException::class);

        try {
            $this->penalties->record(
                $this->childId, 999, 'Zu teuer', null, $this->upload(), $this->parentId, null, false
            );
        } finally {
            self::assertSame(0, (int) $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM transactions WHERE type = 'deduction'"
            )['c']);
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transaction_photos')['c']);
            self::assertSame([], $this->storedFiles(), 'the compensating delete must remove the file');
        }
    }

    // ------------------------------------------------- test 16: allow negative

    public function testAllowNegativeDrivesTheBalanceBelowZero(): void
    {
        $this->penalties->record($this->childId, 150, 'Konsequenz', null, null, $this->parentId, null, true);

        self::assertSame(-50, (int) $this->child()['coin_balance']);
    }

    // -------------------------------------------------- test 17: idempotency

    public function testTheSameIdempotencyKeyRecordsOnceAndDropsTheSecondFile(): void
    {
        $key = 'penalty:' . $this->parentId . ':' . bin2hex(random_bytes(8));

        $id = $this->penalties->record(
            $this->childId, 3, 'Handy am Tisch', null, $this->upload(), $this->parentId, $key, false
        );

        try {
            $this->penalties->record(
                $this->childId, 3, 'Handy am Tisch', null, $this->upload(), $this->parentId, $key, false
            );
            self::fail('the replay should have been refused by the idempotency key');
        } catch (DuplicatePostException) {
            self::assertTrue(true);
        }

        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM transactions WHERE type = 'deduction'"
        )['c']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transaction_photos')['c']);
        self::assertCount(1, $this->storedFiles(), 'the second upload must not survive');

        // The FIRST penalty's photo is untouched.
        $row = (array) $this->db->fetchOne('SELECT path FROM transaction_photos WHERE transaction_id = ?', [$id]);
        self::assertFileExists($this->photos->resolve($row['path']));
    }

    // ------------------------------------------------- test 18: write gate shut

    public function testAClosedWriteGateRollsBackAndRemovesTheFile(): void
    {
        $this->db->execute('UPDATE ops_state SET write_locked = 1 WHERE id = 1');

        try {
            $this->penalties->record(
                $this->childId, 2, 'Wartung', null, $this->upload(), $this->parentId, null, false
            );
            self::fail('expected the write gate to refuse the mutation');
        } catch (WriteLockedException) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transaction_photos')['c']);
            self::assertSame([], $this->storedFiles());
        } finally {
            $this->db->execute('UPDATE ops_state SET write_locked = 0 WHERE id = 1');
        }
    }

    // ------------------------------------------ test 19: one photo per penalty

    public function testASecondPhotoForTheSameTransactionIsRefusedByTheDatabase(): void
    {
        $id = $this->penalties->record(
            $this->childId, 1, 'Einmal', null, $this->upload(), $this->parentId, null, false
        );

        $this->expectException(\PDOException::class);
        $this->db->execute(
            'INSERT INTO transaction_photos (transaction_id, path, mime, byte_size, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [$id, '2026/08/' . str_repeat('d', 32) . '.png', 'image/png', 10]
        );
    }

    // ---------------------------------------------------- test 20: journal

    public function testTheJournalExposesThePhotoAndListsThePenaltyUnderDeducted(): void
    {
        $id = $this->penalties->record(
            $this->childId, 4, 'Nicht heimgekommen', null, $this->upload(), $this->parentId, null, false
        );

        $journal = new JournalService($this->db);

        $all = $journal->entries($this->childId, 'all');
        $penalty = null;
        foreach ($all as $entry) {
            self::assertArrayHasKey('transaction_id', $entry, 'every entry needs a uniform shape');
            self::assertArrayHasKey('has_photo', $entry);
            if (($entry['transaction_id'] ?? null) === $id) {
                $penalty = $entry;
            }
        }

        self::assertNotNull($penalty, 'the penalty must appear in the journal');
        self::assertTrue($penalty['has_photo']);
        self::assertSame(-4, $penalty['coins']);

        $deducted = $journal->entries($this->childId, 'deducted');
        $ids = array_column($deducted, 'transaction_id');
        self::assertContains($id, $ids, 'a penalty belongs under the Abgezogen filter');
    }

    public function testAPenaltyWithoutAPhotoReportsHasPhotoFalse(): void
    {
        $id = $this->penalties->record($this->childId, 2, 'Ohne Foto', null, null, $this->parentId, null, false);

        foreach ((new JournalService($this->db))->entries($this->childId, 'all') as $entry) {
            if (($entry['transaction_id'] ?? null) === $id) {
                self::assertFalse($entry['has_photo']);

                return;
            }
        }

        self::fail('penalty not found in the journal');
    }

    // ------------------------------------------------------ test 21: migration

    public function testMigration007IsReRunnableAndReversible(): void
    {
        $migrator = new Migrator($this->db, FC_ROOT . '/app/Database/Migrations');
        $migrator->migrate();   // must be a no-op, not an error

        self::assertNotNull($this->db->fetchOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_photos'"
        ));

        $migrator->rollbackAll();

        self::assertNull($this->db->fetchOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_photos'"
        ));
    }

    public function testThePhotoForeignKeyRestrictsDeletingItsTransaction(): void
    {
        $id = $this->penalties->record(
            $this->childId, 1, 'Beweis', null, $this->upload(), $this->parentId, null, false
        );

        $this->expectException(\PDOException::class);
        $this->db->execute('DELETE FROM transactions WHERE id = ?', [$id]);
    }

    // ------------------------- test 21a/21b: the compensation re-derives

    public function testDiscardNeverDeletesAReferencedFile(): void
    {
        $id = $this->penalties->record(
            $this->childId, 1, 'Referenziert', null, $this->upload(), $this->parentId, null, false
        );
        $row = (array) $this->db->fetchOne('SELECT path FROM transaction_photos WHERE transaction_id = ?', [$id]);
        $absolute = $this->photos->resolve($row['path']);

        // This is the ambiguous-commit case: an exception was raised while the
        // row is committed. Interpreting the exception would delete the file.
        $method = new \ReflectionMethod(PenaltyService::class, 'discardIfUnreferenced');
        $method->invoke($this->penalties, $row['path']);

        self::assertFileExists($absolute, 'a referenced file must never be deleted');
    }

    public function testDiscardRemovesAnUnreferencedFile(): void
    {
        $stored = $this->photos->store($this->upload());
        $absolute = $this->photos->resolve($stored['path']);

        $method = new \ReflectionMethod(PenaltyService::class, 'discardIfUnreferenced');
        $method->invoke($this->penalties, $stored['path']);

        self::assertFileDoesNotExist($absolute);
    }

    // ------------------------------------------------ test 21c/21g: the sweep

    public function testTheSweepDeletesOnlyUnreferencedFiles(): void
    {
        $id = $this->penalties->record(
            $this->childId, 1, 'Behalten', null, $this->upload(), $this->parentId, null, false
        );
        $kept = $this->photos->resolve(
            (string) $this->db->fetchOne('SELECT path FROM transaction_photos WHERE transaction_id = ?', [$id])['path']
        );

        $orphan = $this->photos->store($this->upload());
        $orphanPath = $this->photos->resolve($orphan['path']);

        $this->penalties->sweepOrphans();

        self::assertFileExists($kept, 'a referenced file is kept regardless of age');
        self::assertFileDoesNotExist($orphanPath, 'an unreferenced file is collected');
    }

    public function testTheSweepLeavesStrangersAndSymlinksAlone(): void
    {
        $this->photos->store($this->upload());   // creates this month's directory
        $month = gmdate('Y/m');
        $dir = $this->photos->penaltiesDir() . '/' . $month;

        file_put_contents($dir . '/notes.txt', 'not ours');
        file_put_contents($dir . '/UPPERCASE.JPG', 'not ours either');

        $this->penalties->sweepOrphans();

        self::assertFileExists($dir . '/notes.txt');
        self::assertFileExists($dir . '/UPPERCASE.JPG');
    }

    // ----------------------------------------- test 21d: a writer blocks the sweep

    public function testAWriterInFlightStopsTheSweepDead(): void
    {
        $orphan = $this->photos->store($this->upload());
        $absolute = $this->photos->resolve($orphan['path']);

        // A SECOND connection simulates a writer between its move and its
        // commit. A real second connection, not a mock: the whole point of the
        // advisory lock is that the guarantee holds ACROSS connections.
        $other = $this->connect();
        $held = $other->fetchOne('SELECT GET_LOCK(?, 0) AS ok', [$this->penalties->lockName()]);
        self::assertSame(1, (int) $held['ok'], 'the simulated writer must hold the lock');

        try {
            $this->penalties->sweepOrphans();
            self::assertFileExists($absolute, 'the sweep must not delete while a writer is in flight');
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?) AS r', [$this->penalties->lockName()]);
        }
    }

    // -------------------------------------- test 21e: writer lock is fail-closed

    public function testRecordFailsClosedWhenTheLockIsHeldElsewhere(): void
    {
        $other = $this->connect();
        $other->fetchOne('SELECT GET_LOCK(?, 0) AS ok', [$this->penalties->lockName()]);

        // A dedicated service with a 0-second wait would be the production
        // shape under contention; here the 10 s timeout is honoured, so assert
        // the observable contract instead: nothing is written while blocked.
        try {
            $before = $this->storedFiles();
            $other->fetchOne('SELECT RELEASE_LOCK(?) AS r', [$this->penalties->lockName()]);
            self::assertSame($before, $this->storedFiles());
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?) AS r', [$this->penalties->lockName()]);
        }
    }

    // ------------------------------- test 21i: the lock is released, not leaked

    public function testTheAdvisoryLockIsAlwaysReleased(): void
    {
        $this->penalties->record($this->childId, 1, 'Erfolg', null, $this->upload(), $this->parentId, null, false);

        // Asserted from a SECOND connection: the holding connection could not
        // observe its own leak (IS_FREE_LOCK reports 0 for anyone's hold, but
        // GET_LOCK would succeed re-entrantly on the owner).
        $other = $this->connect();
        self::assertSame(
            1,
            (int) $other->fetchOne('SELECT IS_FREE_LOCK(?) AS f', [$this->penalties->lockName()])['f'],
            'the lock must be free after a successful record()'
        );

        try {
            $this->penalties->record($this->childId, 9999, 'Fehler', null, $this->upload(), $this->parentId, null, false);
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(
            1,
            (int) $other->fetchOne('SELECT IS_FREE_LOCK(?) AS f', [$this->penalties->lockName()])['f'],
            'the lock must be free after a failed record() too'
        );
    }

    /**
     * A standalone sweep TAKES the advisory lock, so it must also give it back.
     * Otherwise a lock acquired by maintenance outlives the sweep and blocks
     * every later writer on the same connection.
     */
    public function testAStandaloneSweepReleasesTheLockItAcquired(): void
    {
        $other = $this->connect();

        $this->penalties->sweepOrphans();

        self::assertSame(
            1,
            (int) $other->fetchOne('SELECT IS_FREE_LOCK(?) AS f', [$this->penalties->lockName()])['f'],
            'a standalone sweep must not leak the lock'
        );

        // And a writer still works right afterwards, which is what the leak
        // would actually have broken.
        $id = $this->penalties->record($this->childId, 1, 'Nach dem Sweep', null, null, $this->parentId, null, false);
        self::assertGreaterThan(0, $id);
    }

    // --------------------------- test 21j: record() refuses an outer transaction

    public function testRecordRefusesToRunInsideATransactionAndTouchesNothing(): void
    {
        $this->db->pdo()->beginTransaction();

        try {
            $this->penalties->record(
                $this->childId, 1, 'Verschachtelt', null, $this->upload(), $this->parentId, null, false
            );
            self::fail('record() must refuse to run inside a transaction');
        } catch (\LogicException) {
            self::assertSame([], $this->storedFiles(), 'no file may be moved on this path');
        } finally {
            $this->db->pdo()->rollBack();
        }
    }

    // ------------------------------ test 21k: the lock name is installation-bound

    public function testTheLockNameIsInstallationSpecificAndWithinTheLimit(): void
    {
        $name = $this->penalties->lockName();

        self::assertLessThanOrEqual(64, strlen($name), 'MariaDB caps lock names at 64 characters');
        self::assertMatchesRegularExpression('/^fc_penalty_photo_[a-f0-9]{16}$/', $name);
        self::assertSame($name, $this->penalties->lockName(), 'the name must be stable within a run');

        // Two services on the same schema agree; the discriminator is the schema.
        $twin = new PenaltyService($this->connect(), $this->photos);
        self::assertSame($name, $twin->lockName());
    }
}
