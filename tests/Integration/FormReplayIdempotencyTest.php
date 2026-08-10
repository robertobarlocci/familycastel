<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\DuplicatePostException;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\RewardService;
use FamilyCastel\Domain\TemplateService;
use PHPUnit\Framework\TestCase;

/**
 * Final-quality-check Q9 (Codex): an accidental double-submit of the SAME
 * form (double-click, browser replay, refresh-resend) must never duplicate
 * a Coin effect. Every coin-bearing form carries a one-time operation nonce
 * that becomes an idempotency key.
 */
final class FormReplayIdempotencyTest extends TestCase
{
    private Db $db;
    private int $childId;
    private int $templateId;
    private int $rewardId;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();

        $this->db->execute(
            "INSERT INTO children (name, theme, created_at, updated_at) VALUES ('Emma', 'fantasy', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $this->childId = $this->db->lastInsertId();
        (new LedgerService($this->db))->post($this->childId, 100, 0, 'award', 'Start');

        $this->db->execute(
            "INSERT INTO point_templates (title, coins_delta, xp_delta, scope, created_at, updated_at)
             VALUES ('Zimmer aufgeräumt', 5, 10, 'all', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $this->templateId = $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO rewards (title, cost_coins, status, created_at, updated_at)
             VALUES ('Kinoabend', 30, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $this->rewardId = $this->db->lastInsertId();
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

    public function testTemplateAwardReplayPostsExactlyOnce(): void
    {
        $templates = new TemplateService($this->db);
        $key = 'op:1:' . bin2hex(random_bytes(8));

        $templates->apply($this->templateId, $this->childId, null, idempotencyKey: $key);
        try {
            $templates->apply($this->templateId, $this->childId, null, idempotencyKey: $key);
            self::fail('replay must surface as DuplicatePostException');
        } catch (DuplicatePostException) {
            // expected — the controller maps this to a success flash
        }

        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM transactions WHERE type = 'award' AND title = 'Zimmer aufgeräumt'"
        )['c'];
        self::assertSame(1, $count, 'one form nonce = one ledger entry');
        $balance = (int) $this->db->fetchOne('SELECT coin_balance FROM children WHERE id = ?', [$this->childId])['coin_balance'];
        self::assertSame(105, $balance);
    }

    public function testDifferentNoncesPostSeparately(): void
    {
        $templates = new TemplateService($this->db);
        $templates->apply($this->templateId, $this->childId, null, idempotencyKey: 'op:1:' . bin2hex(random_bytes(8)));
        $templates->apply($this->templateId, $this->childId, null, idempotencyKey: 'op:1:' . bin2hex(random_bytes(8)));
        $balance = (int) $this->db->fetchOne('SELECT coin_balance FROM children WHERE id = ?', [$this->childId])['coin_balance'];
        self::assertSame(110, $balance, 'two deliberate awards remain two awards');
    }

    public function testRewardRequestReplayCreatesOneRequest(): void
    {
        $rewards = new RewardService($this->db);
        $key = 'req:' . $this->childId . ':' . bin2hex(random_bytes(8));

        $first = $rewards->request($this->rewardId, $this->childId, requestKey: $key);
        $second = $rewards->request($this->rewardId, $this->childId, requestKey: $key);
        self::assertSame($first, $second, 'replay returns the SAME request');

        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM reward_requests WHERE child_id = ?", [$this->childId]
        )['c'];
        self::assertSame(1, $count, 'one nonce = one pending request/reservation');
    }

    public function testRewardRequestWithoutKeyStillWorks(): void
    {
        $rewards = new RewardService($this->db);
        $rewards->request($this->rewardId, $this->childId);
        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM reward_requests WHERE child_id = ?', [$this->childId]
        )['c'];
        self::assertSame(1, $count);
    }
}
