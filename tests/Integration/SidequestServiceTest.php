<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\SidequestService;
use PHPUnit\Framework\TestCase;

final class SidequestServiceTest extends TestCase
{
    private Db $db;
    private SidequestService $quests;
    private int $emma;
    private int $noah;
    private int $parentId;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
        $this->quests = new SidequestService($this->db);

        $this->db->execute(
            'INSERT INTO users (name, username, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Alex', 'alex', password_hash('x-long-password-1', PASSWORD_DEFAULT), 'parent']
        );
        $this->parentId = $this->db->lastInsertId();

        foreach ([['Emma', 'fantasy'], ['Noah', 'football']] as [$name, $theme]) {
            $this->db->execute(
                'INSERT INTO children (name, theme, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$name, $theme]
            );
        }
        $this->emma = (int) $this->db->fetchOne('SELECT id FROM children WHERE name = ?', ['Emma'])['id'];
        $this->noah = (int) $this->db->fetchOne('SELECT id FROM children WHERE name = ?', ['Noah'])['id'];
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

    private function createQuest(array $overrides = []): int
    {
        return $this->quests->create($overrides + [
            'title' => 'Geschirrspüler ausräumen',
            'coins_reward' => 10,
            'xp_reward' => 10,
            'type' => 'once',
            'ownership' => 'first_come',
        ], $this->parentId);
    }

    public function testCreateAndListAvailableForChild(): void
    {
        $this->createQuest();
        $available = $this->quests->availableFor($this->emma);
        self::assertCount(1, $available);
        self::assertSame('Geschirrspüler ausräumen', $available[0]['title']);
    }

    public function testFirstComeClaimBlocksSecondChild(): void
    {
        $questId = $this->createQuest();

        $claimA = $this->quests->accept($questId, $this->emma);
        self::assertGreaterThan(0, $claimA);

        $this->expectException(\FamilyCastel\Domain\QuestUnavailableException::class);
        $this->quests->accept($questId, $this->noah);
    }

    public function testPerChildQuestAllowsBothChildren(): void
    {
        $questId = $this->createQuest(['ownership' => 'per_child']);

        self::assertGreaterThan(0, $this->quests->accept($questId, $this->emma));
        self::assertGreaterThan(0, $this->quests->accept($questId, $this->noah));
    }

    public function testSameChildCannotDoubleAccept(): void
    {
        $questId = $this->createQuest(['ownership' => 'per_child']);
        $this->quests->accept($questId, $this->emma);

        $this->expectException(\FamilyCastel\Domain\QuestUnavailableException::class);
        $this->quests->accept($questId, $this->emma);
    }

    public function testAssignedQuestOnlyForAssignedChild(): void
    {
        $questId = $this->createQuest(['ownership' => 'assigned', 'assigned_child_ids' => [$this->noah]]);

        self::assertCount(0, $this->quests->availableFor($this->emma));
        self::assertCount(1, $this->quests->availableFor($this->noah));

        $this->expectException(\FamilyCastel\Domain\QuestUnavailableException::class);
        $this->quests->accept($questId, $this->emma);
    }

    public function testDailyQuestReclaimableNextDayButNotSameDay(): void
    {
        $questId = $this->createQuest(['type' => 'daily', 'ownership' => 'per_child']);
        $this->quests->accept($questId, $this->emma);

        // Same bucket (today) → blocked.
        try {
            $this->quests->accept($questId, $this->emma);
            self::fail('same-day double accept must fail');
        } catch (\FamilyCastel\Domain\QuestUnavailableException) {
        }

        // Simulate yesterday's claim: move bucket back, slot frees via bucket key.
        $this->db->execute(
            "UPDATE sidequest_claims SET recurrence_bucket = '2020-01-01' WHERE sidequest_id = ?", [$questId]
        );
        $this->db->execute(
            "UPDATE sidequest_claim_slots SET recurrence_bucket = '2020-01-01' WHERE sidequest_id = ?", [$questId]
        );

        self::assertGreaterThan(0, $this->quests->accept($questId, $this->emma), 'new day → new bucket → acceptable');
    }

    public function testExpiredQuestNotAvailableAndNotAcceptable(): void
    {
        $questId = $this->createQuest(['expires_at' => '2020-01-01 00:00:00']);

        self::assertCount(0, $this->quests->availableFor($this->emma));
        $this->expectException(\FamilyCastel\Domain\QuestUnavailableException::class);
        $this->quests->accept($questId, $this->emma);
    }

    public function testCompleteAndApproveGrantsCoinsExactlyOnce(): void
    {
        $questId = $this->createQuest();
        $claimId = $this->quests->accept($questId, $this->emma);

        $this->quests->markCompleted($claimId, $this->emma);
        $this->quests->approve($claimId, $this->parentId);

        $child = $this->db->fetchOne('SELECT coin_balance, xp_total FROM children WHERE id = ?', [$this->emma]);
        self::assertSame(10, (int) $child['coin_balance']);
        self::assertSame(10, (int) $child['xp_total']);

        $claim = $this->db->fetchOne('SELECT * FROM sidequest_claims WHERE id = ?', [$claimId]);
        self::assertSame('approved', $claim['status']);
        self::assertNotNull($claim['transaction_id']);

        // Double-approve: idempotent no-op (status guard) — never a second tx.
        $this->quests->approve($claimId, $this->parentId);
        $count = $this->db->fetchOne('SELECT COUNT(*) AS c FROM transactions WHERE child_id = ?', [$this->emma]);
        self::assertSame(1, (int) $count['c']);
    }

    public function testApproveWithModifiedReward(): void
    {
        $questId = $this->createQuest();
        $claimId = $this->quests->accept($questId, $this->emma);
        $this->quests->markCompleted($claimId, $this->emma);

        $this->quests->approve($claimId, $this->parentId, coinsOverride: 5, xpOverride: 5, comment: 'Halb gemacht');

        $child = $this->db->fetchOne('SELECT coin_balance FROM children WHERE id = ?', [$this->emma]);
        self::assertSame(5, (int) $child['coin_balance']);

        $claim = $this->db->fetchOne('SELECT * FROM sidequest_claims WHERE id = ?', [$claimId]);
        self::assertSame(5, (int) $claim['approved_coins']);
        self::assertSame(10, (int) $claim['coins_reward'], 'original snapshot preserved');
        self::assertSame('Halb gemacht', $claim['parent_comment']);
    }

    public function testRejectFreesFirstComeSlot(): void
    {
        $questId = $this->createQuest();
        $claimId = $this->quests->accept($questId, $this->emma);
        $this->quests->markCompleted($claimId, $this->emma);
        $this->quests->reject($claimId, $this->parentId, 'Nicht gemacht');

        $claim = $this->db->fetchOne('SELECT * FROM sidequest_claims WHERE id = ?', [$claimId]);
        self::assertSame('rejected', $claim['status']);

        // Slot freed → Noah can now claim (history row for Emma remains — INV-001).
        self::assertGreaterThan(0, $this->quests->accept($questId, $this->noah));
        self::assertSame(0, (int) $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM transactions WHERE child_id = ?', [$this->emma]
        )['c']);
    }

    public function testChildCancelFreesSlot(): void
    {
        $questId = $this->createQuest();
        $claimId = $this->quests->accept($questId, $this->emma);
        $this->quests->cancel($claimId, $this->emma);

        self::assertGreaterThan(0, $this->quests->accept($questId, $this->noah));
    }

    public function testCompleteOnlyByClaimOwner(): void
    {
        $questId = $this->createQuest();
        $claimId = $this->quests->accept($questId, $this->emma);

        $this->expectException(\InvalidArgumentException::class);
        $this->quests->markCompleted($claimId, $this->noah);
    }

    public function testApproveRequiresCompletedPendingStatus(): void
    {
        $questId = $this->createQuest();
        $claimId = $this->quests->accept($questId, $this->emma);

        // Not yet completed → approval is a no-op, no coins.
        $this->quests->approve($claimId, $this->parentId);
        self::assertSame(0, (int) $this->db->fetchOne(
            'SELECT coin_balance FROM children WHERE id = ?', [$this->emma]
        )['coin_balance']);
    }

    public function testOnceQuestStaysConsumedAfterApproval(): void
    {
        $questId = $this->createQuest(); // type 'once', first_come
        $claimId = $this->quests->accept($questId, $this->emma);
        $this->quests->markCompleted($claimId, $this->emma);
        $this->quests->approve($claimId, $this->parentId);

        // A 'once' quest must NOT reopen after approval.
        $this->expectException(\FamilyCastel\Domain\QuestUnavailableException::class);
        $this->quests->accept($questId, $this->noah);
    }

    public function testDailyQuestStaysConsumedForItsBucketAfterApproval(): void
    {
        $questId = $this->createQuest(['type' => 'daily', 'ownership' => 'per_child']);
        $claimId = $this->quests->accept($questId, $this->emma);
        $this->quests->markCompleted($claimId, $this->emma);
        $this->quests->approve($claimId, $this->parentId);

        $this->expectException(\FamilyCastel\Domain\QuestUnavailableException::class);
        $this->quests->accept($questId, $this->emma); // same day → still consumed
    }

    public function testRepeatingQuestReopensAfterApproval(): void
    {
        $questId = $this->createQuest(['type' => 'repeating', 'ownership' => 'per_child']);
        $claimId = $this->quests->accept($questId, $this->emma);
        $this->quests->markCompleted($claimId, $this->emma);
        $this->quests->approve($claimId, $this->parentId);

        self::assertGreaterThan(0, $this->quests->accept($questId, $this->emma), 'repeating reopens');
    }

    public function testArchivedQuestKeepsHistoryVisible(): void
    {
        $questId = $this->createQuest();
        $claimId = $this->quests->accept($questId, $this->emma);
        $this->quests->markCompleted($claimId, $this->emma);
        $this->quests->approve($claimId, $this->parentId);

        $this->quests->archiveQuest($questId);

        // Quest hidden from availability, but the claim (with snapshots) remains.
        self::assertCount(0, $this->quests->availableFor($this->noah));
        $claim = $this->db->fetchOne('SELECT title, coins_reward FROM sidequest_claims WHERE id = ?', [$claimId]);
        self::assertSame('Geschirrspüler ausräumen', $claim['title']);
    }
}
