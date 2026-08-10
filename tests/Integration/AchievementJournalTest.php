<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\AchievementService;
use FamilyCastel\Domain\JournalService;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\NotificationService;
use FamilyCastel\Domain\RewardService;
use PHPUnit\Framework\TestCase;

final class AchievementJournalTest extends TestCase
{
    private Db $db;
    private int $emma;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();

        $this->db->execute(
            'INSERT INTO children (name, theme, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Emma', 'fantasy']
        );
        $this->emma = $this->db->lastInsertId();
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

    public function testMigrationSeedsAchievementDefinitions(): void
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) AS c FROM achievements');
        self::assertGreaterThanOrEqual(15, (int) $count['c']);
    }

    public function testSyncUnlocksExactlyOnceAndNotifies(): void
    {
        $ledger = new LedgerService($this->db);
        $notifications = new NotificationService($this->db);
        $achievements = new AchievementService($this->db, $notifications);

        $ledger->post($this->emma, 120, 120, 'award', 'Grosser Einsatz');

        $first = $achievements->sync($this->emma);
        $codes = array_column($first, 'code');
        self::assertContains('first_coins', $codes);
        self::assertContains('coins_100', $codes);
        self::assertContains('xp_100', $codes);

        // Second sync unlocks nothing new.
        self::assertSame([], $achievements->sync($this->emma));

        // Notifications created for the child.
        $unread = $notifications->unreadFor('child', $this->emma);
        self::assertNotEmpty($unread);
        self::assertSame('achievement_unlocked', $unread[0]['type']);

        // Unseen → celebration queue → seen.
        self::assertNotEmpty($achievements->unseenFor($this->emma));
        $achievements->markSeen($this->emma);
        self::assertSame([], $achievements->unseenFor($this->emma));
    }

    public function testJournalMergesLedgerAndPendingItems(): void
    {
        $ledger = new LedgerService($this->db);
        $ledger->post($this->emma, 50, 50, 'award', 'Start');

        $rewards = new RewardService($this->db);
        $rewardId = $rewards->createReward(['title' => 'Gaming', 'cost_coins' => 30]);
        $rewards->request($rewardId, $this->emma);

        $journal = new JournalService($this->db);

        $all = $journal->entries($this->emma, 'all');
        self::assertCount(2, $all);

        $pending = $journal->entries($this->emma, 'pending');
        self::assertCount(1, $pending);
        self::assertSame('reward_request', $pending[0]['kind']);
        self::assertSame(-30, $pending[0]['coins']);

        $earned = $journal->entries($this->emma, 'earned');
        self::assertCount(1, $earned);
        self::assertSame('Start', $earned[0]['title']);
    }

    public function testPendingCountsForParentBadge(): void
    {
        $ledger = new LedgerService($this->db);
        $ledger->post($this->emma, 100, 0, 'award', 'Start');
        $rewards = new RewardService($this->db);
        $rewardId = $rewards->createReward(['title' => 'Gaming', 'cost_coins' => 30]);
        $rewards->request($rewardId, $this->emma);

        $counts = (new NotificationService($this->db))->pendingCounts();
        self::assertSame(1, $counts['rewards']);
        self::assertSame(0, $counts['sidequests']);
    }
}
