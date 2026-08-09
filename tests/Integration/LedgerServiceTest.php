<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\LedgerService;
use PHPUnit\Framework\TestCase;

final class LedgerServiceTest extends TestCase
{
    private Db $db;
    private LedgerService $ledger;
    private int $childId;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
        $this->ledger = new LedgerService($this->db);

        $this->db->execute(
            'INSERT INTO children (name, theme, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Emma', 'fantasy']
        );
        $this->childId = $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->wipe();
    }

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

    private function balances(): array
    {
        return $this->db->fetchOne(
            'SELECT coin_balance, xp_total, level FROM children WHERE id = ?', [$this->childId]
        );
    }

    public function testAwardIncreasesCoinsXpAndCachesMatchLedger(): void
    {
        $txId = $this->ledger->post(
            childId: $this->childId,
            coinsDelta: 10,
            xpDelta: 10,
            type: 'award',
            title: 'Geschirr abgeräumt',
            actorUserId: null,
        );

        self::assertGreaterThan(0, $txId);
        $b = $this->balances();
        self::assertSame(10, (int) $b['coin_balance']);
        self::assertSame(10, (int) $b['xp_total']);

        // Cache MUST equal ledger sum (INV-002).
        $sum = $this->db->fetchOne(
            'SELECT COALESCE(SUM(coins_delta),0) AS coins, COALESCE(SUM(xp_delta),0) AS xp
             FROM transactions WHERE child_id = ?', [$this->childId]
        );
        self::assertSame((int) $b['coin_balance'], (int) $sum['coins']);
        self::assertSame((int) $b['xp_total'], (int) $sum['xp']);
    }

    public function testDeductionNeverTouchesXp(): void
    {
        $this->ledger->post($this->childId, 20, 20, 'award', 'Start');
        $this->ledger->post($this->childId, -5, 0, 'deduction', 'Zimmer nicht aufgeräumt');

        $b = $this->balances();
        self::assertSame(15, (int) $b['coin_balance']);
        self::assertSame(20, (int) $b['xp_total'], 'XP never decreases');
    }

    public function testNegativeXpDeltaIsRejectedAtServiceLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->post($this->childId, 0, -1, 'adjustment', 'Bad');
    }

    public function testDeductionBelowZeroIsRejectedByDefault(): void
    {
        $this->ledger->post($this->childId, 10, 10, 'award', 'Start');

        $this->expectException(\FamilyCastel\Domain\InsufficientCoinsException::class);
        $this->ledger->post($this->childId, -11, 0, 'deduction', 'Too much');
    }

    public function testDeductionBelowZeroAllowedWhenPolicyPermits(): void
    {
        $this->ledger->post($this->childId, 10, 10, 'award', 'Start');
        $this->ledger->post($this->childId, -15, 0, 'deduction', 'Konsequenz', allowNegative: true);

        self::assertSame(-5, (int) $this->balances()['coin_balance']);
    }

    public function testAvailableBalanceSubtractsPendingReservations(): void
    {
        $this->ledger->post($this->childId, 100, 0, 'award', 'Start');
        $this->db->execute(
            "INSERT INTO reward_requests (child_id, title, cost_coins, status, created_at)
             VALUES (?, 'Gaming', 60, 'pending', UTC_TIMESTAMP())",
            [$this->childId]
        );

        self::assertSame(100, $this->ledger->balance($this->childId));
        self::assertSame(40, $this->ledger->availableBalance($this->childId));
    }

    public function testSpendRespectsReservationsOfOtherRequests(): void
    {
        $this->ledger->post($this->childId, 100, 0, 'award', 'Start');
        $this->db->execute(
            "INSERT INTO reward_requests (child_id, title, cost_coins, status, created_at)
             VALUES (?, 'Gaming', 60, 'pending', UTC_TIMESTAMP())",
            [$this->childId]
        );

        // Spending 50 would leave 50 < 60 reserved → must fail.
        $this->expectException(\FamilyCastel\Domain\InsufficientCoinsException::class);
        $this->ledger->post($this->childId, -50, 0, 'deduction', 'Too much while reserved');
    }

    public function testIdempotencyKeyPreventsDoublePosting(): void
    {
        $this->ledger->post($this->childId, 10, 10, 'sidequest', 'Quest', idempotencyKey: 'claim:77');

        $this->expectException(\FamilyCastel\Domain\DuplicatePostException::class);
        $this->ledger->post($this->childId, 10, 10, 'sidequest', 'Quest again', idempotencyKey: 'claim:77');
    }

    public function testLevelUpdatesWithXp(): void
    {
        // Level 2 threshold with default curve (base 50, exp 1.6) = 50.
        $this->ledger->post($this->childId, 0, 50, 'award', 'XP boost');
        self::assertSame(2, (int) $this->balances()['level']);
    }

    public function testReverseCreatesCompensationOnceOnly(): void
    {
        $txId = $this->ledger->post($this->childId, 10, 10, 'award', 'Oops');
        $reversalId = $this->ledger->reverse($txId, actorUserId: null);

        $b = $this->balances();
        self::assertSame(0, (int) $b['coin_balance']);
        self::assertSame(10, (int) $b['xp_total'], 'XP is permanent — reversals only touch coins');

        $reversal = $this->db->fetchOne('SELECT * FROM transactions WHERE id = ?', [$reversalId]);
        self::assertSame('reversal', $reversal['type']);
        self::assertSame($txId, (int) $reversal['reversal_of']);

        $this->expectException(\FamilyCastel\Domain\DuplicatePostException::class);
        $this->ledger->reverse($txId, actorUserId: null);
    }

    public function testConcurrentSpendsCannotOverdraw(): void
    {
        $this->ledger->post($this->childId, 60, 0, 'award', 'Start');

        // Two connections racing to spend 60 from a 60-coin balance: exactly
        // one must win. Connection A locks the child row inside an open
        // transaction; connection B must block and then fail its floor check.
        $dbA = $this->connect();
        $dbB = $this->connect();
        $ledgerB = new LedgerService($dbB);

        $dbA->pdo()->beginTransaction();
        $dbA->fetchOne('SELECT write_locked FROM ops_state WHERE id = 1 LOCK IN SHARE MODE');
        $rowA = $dbA->fetchOne('SELECT coin_balance FROM children WHERE id = ? FOR UPDATE', [$this->childId]);
        self::assertSame(60, (int) $rowA['coin_balance']);

        // B starts its spend on another connection — it will block on the
        // child row; give it a short lock timeout so the test stays fast.
        $dbB->execute('SET SESSION innodb_lock_wait_timeout = 1');
        $failed = false;
        try {
            $ledgerB->post($this->childId, -60, 0, 'deduction', 'B spend');
        } catch (\Throwable) {
            $failed = true; // lock wait timeout — B could not sneak past A
        }
        self::assertTrue($failed, 'B must not complete while A holds the row lock');

        // A completes its spend atomically.
        $dbA->execute(
            "INSERT INTO transactions (child_id, coins_delta, xp_delta, type, title, created_at)
             VALUES (?, -60, 0, 'deduction', 'A spend', UTC_TIMESTAMP())",
            [$this->childId]
        );
        $dbA->execute('UPDATE children SET coin_balance = coin_balance - 60 WHERE id = ?', [$this->childId]);
        $dbA->pdo()->commit();

        self::assertSame(0, (int) $this->balances()['coin_balance']);

        // Now B retries on the settled state: must fail the floor check.
        try {
            $ledgerB->post($this->childId, -60, 0, 'deduction', 'B retry');
            self::fail('overdraw must be impossible');
        } catch (\FamilyCastel\Domain\InsufficientCoinsException) {
            // expected
        }
    }

    public function testPublicPostRejectsReversalType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->post($this->childId, -10, 0, 'reversal', 'Sneaky reversal');
    }

    public function testPostOnArchivedChildRejected(): void
    {
        $this->db->execute('UPDATE children SET archived_at = UTC_TIMESTAMP() WHERE id = ?', [$this->childId]);

        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->post($this->childId, 10, 0, 'award', 'Ghost award');
    }

    public function testMagnitudeSanityCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->post($this->childId, 0, 2_000_000, 'award', 'XP forgery');
    }

    public function testGateBlocksNonLedgerMutatorsToo(): void
    {
        $this->db->execute('UPDATE ops_state SET write_locked = 1, updated_at = UTC_TIMESTAMP() WHERE id = 1');

        try {
            $children = new \FamilyCastel\Domain\ChildService($this->db);
            try {
                $children->create(['name' => 'Ghost', 'theme' => 'fantasy']);
                self::fail('ChildService must respect the write gate');
            } catch (\FamilyCastel\Domain\WriteLockedException) {
            }

            $templates = new \FamilyCastel\Domain\TemplateService($this->db);
            try {
                $templates->create(['title' => 'Ghost', 'coins_delta' => 5, 'xp_delta' => 0, 'scope' => 'all']);
                self::fail('TemplateService must respect the write gate');
            } catch (\FamilyCastel\Domain\WriteLockedException) {
            }

            $settings = new \FamilyCastel\Domain\SettingsService($this->db);
            try {
                $settings->set('family.name', 'Ghost');
                self::fail('SettingsService must respect the write gate');
            } catch (\FamilyCastel\Domain\WriteLockedException) {
            }
        } finally {
            $this->db->execute('UPDATE ops_state SET write_locked = 0 WHERE id = 1');
        }

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM point_templates')['c']);
    }

    public function testPostRefusedWhileWriteGateLocked(): void
    {
        $this->db->execute('UPDATE ops_state SET write_locked = 1, updated_at = UTC_TIMESTAMP() WHERE id = 1');

        try {
            $this->ledger->post($this->childId, 10, 0, 'award', 'During maintenance');
            self::fail('write gate must block mutations');
        } catch (\FamilyCastel\Domain\WriteLockedException) {
            // expected
        } finally {
            $this->db->execute('UPDATE ops_state SET write_locked = 0 WHERE id = 1');
        }

        self::assertSame(0, (int) $this->balances()['coin_balance']);
    }
}
