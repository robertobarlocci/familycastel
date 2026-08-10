<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\AuditService;
use FamilyCastel\Domain\JournalService;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\MilestoneService;
use FamilyCastel\Domain\SettingsService;
use FamilyCastel\Domain\SuggestionService;
use PHPUnit\Framework\TestCase;

final class MilestoneSuggestionJournalTest extends TestCase
{
    private Db $db;
    private int $childId;
    private int $parentId;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();

        $this->db->execute(
            "INSERT INTO users (name, username, password_hash, role, created_at, updated_at)
             VALUES ('P', 'p', ?, 'parent', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [password_hash('x', PASSWORD_DEFAULT)]
        );
        $this->parentId = $this->db->lastInsertId();
        $this->db->execute(
            "INSERT INTO children (name, theme, created_at, updated_at) VALUES ('Emma', 'fantasy', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $this->childId = $this->db->lastInsertId();
        (new LedgerService($this->db))->post($this->childId, 500, 100, 'award', 'Startkapital');
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
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
            $table = str_replace('`', '``', (string) array_values($row)[0]);
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function milestones(): MilestoneService
    {
        return new MilestoneService($this->db);
    }

    // ------------------------------------------------------------ milestones

    public function testMilestoneLifecycleCreateProgressClaim(): void
    {
        $id = $this->milestones()->create($this->childId, 'Nintendo Switch 2', 300);

        $progress = $this->milestones()->progress($id);
        self::assertSame(300, (int) $progress['target']);
        self::assertSame(300, (int) $progress['current'], 'progress caps at the target');
        self::assertSame(0, (int) $progress['remaining']);

        $this->milestones()->claim($id, $this->parentId);

        // Claiming SPENDS the coins through the ledger (INV-002).
        $balance = (int) $this->db->fetchOne(
            'SELECT coin_balance FROM children WHERE id = ?', [$this->childId]
        )['coin_balance'];
        self::assertSame(200, $balance);
        $status = $this->db->fetchOne('SELECT status FROM milestones WHERE id = ?', [$id])['status'];
        self::assertSame('claimed', $status);
    }

    public function testMilestoneClaimRefusedWithoutEnoughCoins(): void
    {
        $id = $this->milestones()->create($this->childId, 'Zu teuer', 10_000);
        $this->expectException(\Throwable::class);
        $this->milestones()->claim($id, $this->parentId);
    }

    public function testMilestoneWishFlow(): void
    {
        $requestId = $this->milestones()->submitWish($this->childId, 'Lego Burg', 400);
        self::assertNotEmpty($this->milestones()->pendingWishes());

        self::assertTrue($this->milestones()->approveWish($requestId, $this->parentId, targetOverride: 350));
        self::assertFalse($this->milestones()->approveWish($requestId, $this->parentId), 'second decision is a no-op');

        $active = $this->milestones()->activeFor($this->childId);
        $titles = array_column($active, 'title');
        self::assertContains('Lego Burg', $titles);
    }

    public function testMilestoneWishReject(): void
    {
        $requestId = $this->milestones()->submitWish($this->childId, 'Nope', 100);
        self::assertTrue($this->milestones()->rejectWish($requestId, $this->parentId, 'zu früh'));
        self::assertFalse($this->milestones()->rejectWish($requestId, $this->parentId));
        self::assertSame([], $this->milestones()->pendingWishes());
    }

    public function testMilestoneArchiveKeepsRow(): void
    {
        $id = $this->milestones()->create($this->childId, 'Später', 100);
        $this->milestones()->archive($id);
        // INV-001: archived, never deleted.
        $row = $this->db->fetchOne('SELECT status FROM milestones WHERE id = ?', [$id]);
        self::assertNotNull($row);
        self::assertSame('archived', $row['status']);
    }

    // ------------------------------------------------------------ suggestions

    public function testSuggestionApproveAwardsThroughLedger(): void
    {
        $suggestions = new SuggestionService($this->db);
        $id = $suggestions->submit($this->childId, 'Keller aufräumen', 20, 'freiwillig!');
        self::assertNotEmpty($suggestions->pending());

        self::assertTrue($suggestions->approve($id, $this->parentId, coins: 25, xp: 15));
        self::assertFalse($suggestions->approve($id, $this->parentId, coins: 25, xp: 15), 'idempotent decision');

        $balance = (int) $this->db->fetchOne('SELECT coin_balance FROM children WHERE id = ?', [$this->childId])['coin_balance'];
        self::assertSame(525, $balance);
        self::assertNotEmpty($suggestions->forChild($this->childId));
    }

    public function testSuggestionReject(): void
    {
        $suggestions = new SuggestionService($this->db);
        $id = $suggestions->submit($this->childId, 'Pizza bestellen', 999, null);
        self::assertTrue($suggestions->reject($id, $this->parentId, 'nein.'));
        self::assertFalse($suggestions->reject($id, $this->parentId));
        $balance = (int) $this->db->fetchOne('SELECT coin_balance FROM children WHERE id = ?', [$this->childId])['coin_balance'];
        self::assertSame(500, $balance, 'rejection never touches the ledger');
    }

    // ------------------------------------------------------------ journal

    public function testJournalFiltersEntries(): void
    {
        $ledger = new LedgerService($this->db);
        $ledger->post($this->childId, -30, 0, 'reward_spend', 'Kinoabend');
        $ledger->post($this->childId, 10, 5, 'sidequest', 'Zimmer');

        $journal = new JournalService($this->db);
        $all = $journal->entries($this->childId);
        self::assertGreaterThanOrEqual(3, count($all));

        $spent = $journal->entries($this->childId, 'spent');
        self::assertNotEmpty($spent);
        foreach ($spent as $entry) {
            self::assertLessThan(0, (int) $entry['coins']);
        }

        $earned = $journal->entries($this->childId, 'earned');
        self::assertNotEmpty($earned);
        foreach ($earned as $entry) {
            if ($entry['status'] === 'posted') {
                self::assertGreaterThan(0, (int) $entry['coins']);
            }
        }

        self::assertSame([], $journal->entries(999999), 'unknown child has no entries');
    }

    // ------------------------------------------------------------ settings + audit

    public function testSettingsRoundTripAndOverwrite(): void
    {
        $settings = new SettingsService($this->db);
        self::assertSame('fallback', $settings->get('ghost.key', 'fallback'));
        $settings->set('app.test_value', ['a' => 1]);
        self::assertSame(['a' => 1], $settings->get('app.test_value'));
        $settings->set('app.test_value', 'now-a-string');
        self::assertSame('now-a-string', $settings->get('app.test_value'));
    }

    public function testAuditLogWritesRow(): void
    {
        (new AuditService($this->db))->log('user', $this->parentId, 'test.action', details: ['k' => 'v'], ip: '10.0.0.1');
        $row = $this->db->fetchOne('SELECT action, ip FROM audit_log ORDER BY id DESC LIMIT 1');
        self::assertSame('test.action', $row['action']);
        self::assertSame('10.0.0.1', $row['ip']);
    }
}
