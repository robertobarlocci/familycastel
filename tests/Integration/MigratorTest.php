<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private Db $db;

    protected function setUp(): void
    {
        $this->db = Db::fromParams(
            host: getenv('FC_TEST_DB_HOST') ?: '127.0.0.1',
            port: (int) (getenv('FC_TEST_DB_PORT') ?: 3306),
            name: getenv('FC_TEST_DB_NAME') ?: 'familycastel_test',
            user: getenv('FC_TEST_DB_USER') ?: 'fc',
            password: getenv('FC_TEST_DB_PASS') ?: 'fc-dev-password',
        );
        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
    }

    private function dropAllTables(): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
            // Identifier comes from the DB itself, but keep the hardcoded-identifier
            // contract intact: escape backticks before interpolating.
            $table = str_replace('`', '``', (string) array_values($row)[0]);
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, FC_ROOT . '/app/Database/Migrations');
    }

    public function testFreshDatabaseHasAllPendingMigrations(): void
    {
        $pending = $this->migrator()->pending();
        self::assertNotEmpty($pending);
        self::assertSame('001', $pending[0]->version());
    }

    public function testMigrateAppliesAllAndRecordsVersions(): void
    {
        $applied = $this->migrator()->migrate();
        self::assertNotEmpty($applied);

        // Re-running is a no-op (idempotent).
        self::assertSame([], $this->migrator()->migrate());
        self::assertSame([], $this->migrator()->pending());

        $rows = $this->db->fetchAll('SELECT version FROM schema_migrations ORDER BY version');
        self::assertSame(
            array_map(fn ($m) => $m->version(), $this->migrator()->all()),
            array_column($rows, 'version')
        );
    }

    public function testInitialSchemaCreatesCoreTables(): void
    {
        $this->migrator()->migrate();

        $tables = array_map(fn ($r) => array_values($r)[0], $this->db->fetchAll('SHOW TABLES'));
        foreach ([
            'settings', 'users', 'children', 'auth_tokens', 'login_attempts',
            'transactions', 'point_templates', 'sidequests', 'sidequest_claims',
            'sidequest_claim_slots', 'suggestions', 'rewards', 'reward_requests',
            'milestones', 'milestone_requests', 'achievements', 'child_achievements',
            'notifications', 'audit_log', 'schema_migrations', 'update_history',
            'backup_history', 'ops_state',
        ] as $table) {
            self::assertContains($table, $tables, "missing table: {$table}");
        }
    }

    public function testOpsStateSeededWithUnlockedGate(): void
    {
        $this->migrator()->migrate();
        $row = $this->db->fetchOne('SELECT write_locked FROM ops_state WHERE id = 1');
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['write_locked']);
    }

    public function testLedgerRejectsNegativeXp(): void
    {
        $this->migrator()->migrate();
        $childId = $this->seedChild();

        $this->expectException(\PDOException::class);
        $this->db->execute(
            'INSERT INTO transactions (child_id, coins_delta, xp_delta, type, title, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$childId, 5, -5, 'award', 'Bad XP']
        );
    }

    public function testLedgerIdempotencyKeyIsUnique(): void
    {
        $this->migrator()->migrate();
        $childId = $this->seedChild();

        $insert = 'INSERT INTO transactions (child_id, coins_delta, xp_delta, type, title, idempotency_key, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())';
        $this->db->execute($insert, [$childId, 10, 10, 'award', 'First', 'sidequest_claim:1']);

        $this->expectException(\PDOException::class);
        $this->db->execute($insert, [$childId, 10, 10, 'award', 'Duplicate', 'sidequest_claim:1']);
    }

    public function testReversalOfIsUnique(): void
    {
        $this->migrator()->migrate();
        $childId = $this->seedChild();

        $this->db->execute(
            'INSERT INTO transactions (child_id, coins_delta, xp_delta, type, title, created_at)
             VALUES (?, 10, 0, ?, ?, UTC_TIMESTAMP())',
            [$childId, 'award', 'Original']
        );
        $originalId = $this->db->lastInsertId();

        $reversal = 'INSERT INTO transactions (child_id, coins_delta, xp_delta, type, title, reversal_of, created_at)
                     VALUES (?, -10, 0, ?, ?, ?, UTC_TIMESTAMP())';
        $this->db->execute($reversal, [$childId, 'reversal', 'Reversal 1', $originalId]);

        $this->expectException(\PDOException::class);
        $this->db->execute($reversal, [$childId, 'reversal', 'Reversal 2', $originalId]);
    }

    public function testClaimSlotUniquenessEnforcesFirstCome(): void
    {
        $this->migrator()->migrate();
        $childA = $this->seedChild('Emma');
        $childB = $this->seedChild('Noah');
        $questId = $this->seedSidequest();

        $claim = 'INSERT INTO sidequest_claims (sidequest_id, child_id, recurrence_bucket, status, title, coins_reward, xp_reward, accepted_at, created_at)
                  VALUES (?, ?, ?, ?, ?, 10, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP())';
        $slot = 'INSERT INTO sidequest_claim_slots (sidequest_id, recurrence_bucket, scope_key, claim_id)
                 VALUES (?, ?, ?, ?)';

        $this->db->execute($claim, [$questId, $childA, '', 'accepted', 'Quest']);
        $claimA = $this->db->lastInsertId();
        $this->db->execute($slot, [$questId, '', 'g', $claimA]);

        $this->db->execute($claim, [$questId, $childB, '', 'accepted', 'Quest']);
        $claimB = $this->db->lastInsertId();

        $this->expectException(\PDOException::class);
        $this->db->execute($slot, [$questId, '', 'g', $claimB]);
    }

    public function testDownRollsBackCleanly(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate();
        $migrator->rollbackAll();

        $tables = array_map(fn ($r) => array_values($r)[0], $this->db->fetchAll('SHOW TABLES'));
        self::assertSame(['schema_migrations'], $tables);
        self::assertSame([], $this->db->fetchAll('SELECT * FROM schema_migrations'));
    }

    private function seedChild(string $name = 'Emma'): int
    {
        $this->db->execute(
            'INSERT INTO children (name, theme, created_at, updated_at)
             VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$name, 'fantasy']
        );

        return $this->db->lastInsertId();
    }

    private function seedSidequest(): int
    {
        $this->db->execute(
            "INSERT INTO sidequests (title, coins_reward, xp_reward, type, ownership, status, created_at, updated_at)
             VALUES (?, 10, 10, 'once', 'first_come', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            ['Geschirrspüler ausräumen']
        );

        return $this->db->lastInsertId();
    }
}
