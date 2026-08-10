<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\InsufficientCoinsException;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\MilestoneService;
use FamilyCastel\Domain\RewardService;
use FamilyCastel\Domain\SuggestionService;
use PHPUnit\Framework\TestCase;

/** Suggestions, rewards (reservation!), milestones — the approval economy. */
final class ApprovalFlowsTest extends TestCase
{
    private Db $db;
    private LedgerService $ledger;
    private int $emma;
    private int $parentId;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
        $this->ledger = new LedgerService($this->db);

        $this->db->execute(
            'INSERT INTO users (name, username, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Alex', 'alex', password_hash('x-long-password-1', PASSWORD_DEFAULT), 'parent']
        );
        $this->parentId = $this->db->lastInsertId();
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

    // ---------------------------------------------------------- suggestions

    public function testSuggestionApproveWithModifiedCoins(): void
    {
        $suggestions = new SuggestionService($this->db);
        $id = $suggestions->submit($this->emma, 'Garten gegossen', 8, 'War viel Arbeit!');

        $suggestions->approve($id, $this->parentId, coins: 5, xp: 5, comment: 'Top!');

        $child = $this->db->fetchOne('SELECT coin_balance, xp_total FROM children WHERE id = ?', [$this->emma]);
        self::assertSame(5, (int) $child['coin_balance']);
        $row = $this->db->fetchOne('SELECT * FROM suggestions WHERE id = ?', [$id]);
        self::assertSame('approved', $row['status']);
        self::assertSame(8, (int) $row['suggested_coins'], 'original suggestion preserved');
        self::assertSame(5, (int) $row['approved_coins']);

        // Idempotent double-approve.
        $suggestions->approve($id, $this->parentId, coins: 5, xp: 5);
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM transactions WHERE child_id = ?', [$this->emma]
        )['c']);
    }

    public function testSuggestionRejectKeepsRowForever(): void
    {
        $suggestions = new SuggestionService($this->db);
        $id = $suggestions->submit($this->emma, 'Nichts getan', 100, null);
        $suggestions->reject($id, $this->parentId, 'Netter Versuch 😄');

        $row = $this->db->fetchOne('SELECT * FROM suggestions WHERE id = ?', [$id]);
        self::assertSame('rejected', $row['status']);
        self::assertSame('Netter Versuch 😄', $row['parent_comment']);
        self::assertSame(0, (int) $this->db->fetchOne(
            'SELECT coin_balance FROM children WHERE id = ?', [$this->emma]
        )['coin_balance']);
    }

    // ---------------------------------------------------------- rewards

    public function testRewardRequestReservesCoins(): void
    {
        $this->ledger->post($this->emma, 100, 0, 'award', 'Start');
        $rewards = new RewardService($this->db);
        $rewardId = $rewards->createReward(['title' => '30 Minuten Gaming', 'cost_coins' => 60, 'duration_minutes' => 30]);

        $rewards->request($rewardId, $this->emma);

        self::assertSame(100, $this->ledger->balance($this->emma), 'reservation does not spend');
        self::assertSame(40, $this->ledger->availableBalance($this->emma));
    }

    public function testCannotReserveMoreThanAvailable(): void
    {
        $this->ledger->post($this->emma, 60, 0, 'award', 'Start');
        $rewards = new RewardService($this->db);
        $rewardId = $rewards->createReward(['title' => 'Gaming', 'cost_coins' => 60]);

        $rewards->request($rewardId, $this->emma);

        // Second identical request must fail — the same coins can't be reserved twice.
        $this->expectException(InsufficientCoinsException::class);
        $rewards->request($rewardId, $this->emma);
    }

    public function testApproveSpendsReservedCoinsExactlyOnce(): void
    {
        $this->ledger->post($this->emma, 100, 0, 'award', 'Start');
        $rewards = new RewardService($this->db);
        $rewardId = $rewards->createReward(['title' => 'Gaming', 'cost_coins' => 60]);
        $requestId = $rewards->request($rewardId, $this->emma);

        $rewards->approve($requestId, $this->parentId);

        self::assertSame(40, $this->ledger->balance($this->emma));
        self::assertSame(40, $this->ledger->availableBalance($this->emma), 'reservation consumed');

        $rewards->approve($requestId, $this->parentId); // idempotent
        self::assertSame(40, $this->ledger->balance($this->emma));

        $request = $this->db->fetchOne('SELECT * FROM reward_requests WHERE id = ?', [$requestId]);
        self::assertSame('approved', $request['status']);
        self::assertNotNull($request['transaction_id']);
    }

    public function testRejectReleasesReservation(): void
    {
        $this->ledger->post($this->emma, 60, 0, 'award', 'Start');
        $rewards = new RewardService($this->db);
        $rewardId = $rewards->createReward(['title' => 'Gaming', 'cost_coins' => 60]);
        $requestId = $rewards->request($rewardId, $this->emma);

        $rewards->reject($requestId, $this->parentId, 'Heute nicht');

        self::assertSame(60, $this->ledger->availableBalance($this->emma), 'coins available again');
        self::assertSame(60, $this->ledger->balance($this->emma));
    }

    public function testCustomRewardRequest(): void
    {
        $this->ledger->post($this->emma, 100, 0, 'award', 'Start');
        $rewards = new RewardService($this->db);

        $requestId = $rewards->requestCustom($this->emma, 'Minecraft spielen', 45);
        $rewards->approve($requestId, $this->parentId, costOverride: 45);

        self::assertSame(55, $this->ledger->balance($this->emma));
        $request = $this->db->fetchOne('SELECT * FROM reward_requests WHERE id = ?', [$requestId]);
        self::assertSame('Minecraft spielen', $request['title']);
        self::assertNull($request['reward_id']);
    }

    public function testCustomRequestReservesZeroUntilParentSetsCost(): void
    {
        $rewards = new RewardService($this->db);
        // Emma has 0 coins; a custom WISH must still be submittable.
        $requestId = $rewards->requestCustom($this->emma, 'Kino', null);
        self::assertSame(0, $this->ledger->availableBalance($this->emma));

        // Approving with a cost she can't afford fails gracefully; request stays pending.
        try {
            $rewards->approve($requestId, $this->parentId, costOverride: 50);
            self::fail('unaffordable approval must fail');
        } catch (InsufficientCoinsException) {
        }
        $request = $this->db->fetchOne('SELECT status FROM reward_requests WHERE id = ?', [$requestId]);
        self::assertSame('pending', $request['status'], 'failed approval rolls back to pending');
    }

    public function testApprovalPreservesRequestCostSnapshot(): void
    {
        $this->ledger->post($this->emma, 100, 0, 'award', 'Start');
        $rewards = new RewardService($this->db);
        $rewardId = $rewards->createReward(['title' => 'Gaming', 'cost_coins' => 60]);
        $requestId = $rewards->request($rewardId, $this->emma);

        $rewards->approve($requestId, $this->parentId, costOverride: 40, comment: 'Kürzer heute');

        $request = $this->db->fetchOne('SELECT * FROM reward_requests WHERE id = ?', [$requestId]);
        self::assertSame(60, (int) $request['cost_coins'], 'request-time snapshot preserved (INV-001)');
        self::assertSame(40, (int) $request['approved_cost_coins']);
        self::assertSame(60, $this->ledger->balance($this->emma), '100 - 40 spent');
    }

    // ---------------------------------------------------------- milestones

    public function testMilestoneProgressAndSpendClaim(): void
    {
        $milestones = new MilestoneService($this->db);
        $id = $milestones->create($this->emma, 'Nintendo Switch 2', 1000, 'spend');

        $this->ledger->post($this->emma, 340, 0, 'award', 'Sparen');
        $progress = $milestones->progress($id);
        self::assertSame(340, $progress['current']);
        self::assertSame(1000, $progress['target']);
        self::assertSame(34, (int) round($progress['fraction'] * 100));

        // Claiming needs full funds.
        try {
            $milestones->claim($id, $this->parentId);
            self::fail('claim without funds must fail');
        } catch (InsufficientCoinsException) {
        }

        $this->ledger->post($this->emma, 660, 0, 'award', 'Mehr Sparen');
        $milestones->claim($id, $this->parentId);

        self::assertSame(0, $this->ledger->balance($this->emma));
        $milestone = $this->db->fetchOne('SELECT * FROM milestones WHERE id = ?', [$id]);
        self::assertSame('claimed', $milestone['status']);
        self::assertNotNull($milestone['transaction_id']);

        // Idempotent.
        $milestones->claim($id, $this->parentId);
        self::assertSame(0, $this->ledger->balance($this->emma));
    }

    public function testProgressOnlyMilestoneClaimSpendsNothing(): void
    {
        $milestones = new MilestoneService($this->db);
        $id = $milestones->create($this->emma, 'Stadionbesuch', 500, 'progress_only');
        $this->ledger->post($this->emma, 500, 0, 'award', 'Sparen');

        $milestones->claim($id, $this->parentId);

        self::assertSame(500, $this->ledger->balance($this->emma), 'progress_only never spends');
        self::assertSame('claimed', $this->db->fetchOne(
            'SELECT status FROM milestones WHERE id = ?', [$id]
        )['status']);
    }

    public function testMilestoneWishlistApprovalCreatesMilestone(): void
    {
        $milestones = new MilestoneService($this->db);
        $requestId = $milestones->submitWish($this->emma, 'LEGO Technic Set', 700);

        $milestones->approveWish($requestId, $this->parentId, targetOverride: 650);

        $request = $this->db->fetchOne('SELECT * FROM milestone_requests WHERE id = ?', [$requestId]);
        self::assertSame('approved', $request['status']);
        self::assertNotNull($request['milestone_id']);

        $milestone = $this->db->fetchOne('SELECT * FROM milestones WHERE id = ?', [$request['milestone_id']]);
        self::assertSame('LEGO Technic Set', $milestone['title']);
        self::assertSame(650, (int) $milestone['target_coins']);
        self::assertSame(700, (int) $request['suggested_coins'], 'original wish preserved');
    }
}
