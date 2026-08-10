<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\DemoSeeder;
use PHPUnit\Framework\TestCase;

/**
 * The demo family is the product's first impression AND the brief's exact
 * acceptance data: Emma (Fantasy, L7, 245 Coins) and Noah (Football, L5,
 * 182 Coins) with living history and pending decisions.
 */
final class DemoSeederTest extends TestCase
{
    private Db $db;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
        $this->db->execute(
            "INSERT INTO users (name, username, password_hash, role, created_at, updated_at)
             VALUES ('Demo', 'demo-parent', ?, 'parent', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [password_hash('x', PASSWORD_DEFAULT)]
        );
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

    public function testSeedsTheBriefExactFamily(): void
    {
        self::assertTrue((new DemoSeeder($this->db))->run(1));

        $children = $this->db->fetchAll('SELECT name, theme, level, coin_balance, xp_total FROM children ORDER BY id');
        self::assertCount(2, $children);

        [$emma, $noah] = $children;
        self::assertSame(['Emma', 'fantasy', 7, 245, 900],
            [$emma['name'], $emma['theme'], (int) $emma['level'], (int) $emma['coin_balance'], (int) $emma['xp_total']]);
        self::assertSame(['Noah', 'football', 5, 182, 497],
            [$noah['name'], $noah['theme'], (int) $noah['level'], (int) $noah['coin_balance'], (int) $noah['xp_total']]);

        // INV-002: balances derive from the ledger — recompute and compare.
        foreach ([1 => 245, 2 => 182] as $childId => $expected) {
            $sum = (int) $this->db->fetchOne(
                'SELECT COALESCE(SUM(coins_delta), 0) AS s FROM transactions WHERE child_id = ?',
                [$childId]
            )['s'];
            self::assertSame($expected, $sum, "child {$childId} balance is ledger-derived");
        }

        // Living history + pending decisions for the approvals demo.
        self::assertGreaterThanOrEqual(30, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transactions')['c']);
        self::assertGreaterThanOrEqual(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM reward_requests WHERE status = 'pending'"
        )['c'], 'Noah has a pending reward request');
        self::assertGreaterThanOrEqual(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM milestones'
        )['c'], 'Emma saves toward the Switch 2 milestone');
    }

    public function testRefusesToSeedANonEmptyFamily(): void
    {
        $this->db->execute(
            "INSERT INTO children (name, theme, created_at, updated_at) VALUES ('Real Kid', 'fantasy', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        self::assertFalse((new DemoSeeder($this->db))->run(1), 'never seed into a family with real children');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM children')['c']);
    }

    public function testSeedingTwiceIsANoOp(): void
    {
        self::assertTrue((new DemoSeeder($this->db))->run(1));
        $txCount = (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transactions')['c'];
        self::assertFalse((new DemoSeeder($this->db))->run(1));
        self::assertSame($txCount, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transactions')['c']);
    }
}
