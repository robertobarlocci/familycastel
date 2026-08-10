<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\ChildService;
use PHPUnit\Framework\TestCase;

final class ChildServiceTest extends TestCase
{
    private Db $db;
    private ChildService $children;

    protected function setUp(): void
    {
        $this->db = Db::fromParams(
            host: getenv('FC_TEST_DB_HOST') ?: '127.0.0.1',
            port: (int) (getenv('FC_TEST_DB_PORT') ?: 3306),
            name: getenv('FC_TEST_DB_NAME') ?: 'familycastel_test',
            user: getenv('FC_TEST_DB_USER') ?: 'fc',
            password: getenv('FC_TEST_DB_PASS') ?: 'fc-dev-password',
        );
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
        $this->children = new ChildService($this->db);
    }

    protected function tearDown(): void
    {
        $this->wipe();
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

    public function testCreateChildWithDefaults(): void
    {
        $id = $this->children->create(['name' => 'Emma', 'theme' => 'fantasy', 'character_key' => 'knight']);
        $child = $this->children->find($id);

        self::assertNotNull($child);
        self::assertSame('Emma', $child['name']);
        self::assertSame(1, (int) $child['level']);
        self::assertSame(0, (int) $child['coin_balance']);
        self::assertSame(0, (int) $child['xp_total']);
        self::assertNull($child['pin_hash']);
    }

    public function testCreateValidatesNameAndTheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->children->create(['name' => '', 'theme' => 'fantasy']);
    }

    public function testCreateRejectsUnknownTheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->children->create(['name' => 'X', 'theme' => 'space-pirates']);
    }

    public function testUpdateChangesEditableFields(): void
    {
        $id = $this->children->create(['name' => 'Emma', 'theme' => 'fantasy']);
        $this->children->update($id, [
            'name' => 'Emma L.',
            'theme' => 'football',
            'character_key' => 'striker',
            'sound_enabled' => false,
        ]);

        $child = $this->children->find($id);
        self::assertSame('Emma L.', $child['name']);
        self::assertSame('football', $child['theme']);
        self::assertSame('striker', $child['character_key']);
        self::assertSame(0, (int) $child['sound_enabled']);
    }

    public function testPinSetAndClear(): void
    {
        $id = $this->children->create(['name' => 'Emma', 'theme' => 'fantasy']);

        $this->children->setPin($id, '4711');
        $child = $this->children->find($id);
        self::assertTrue(password_verify('4711', $child['pin_hash']));

        $this->children->clearPin($id);
        self::assertNull($this->children->find($id)['pin_hash']);
    }

    public function testPinMustBeFourToSixDigits(): void
    {
        $id = $this->children->create(['name' => 'Emma', 'theme' => 'fantasy']);
        $this->expectException(\InvalidArgumentException::class);
        $this->children->setPin($id, '12');
    }

    public function testArchiveHidesFromActiveListButNeverDeletes(): void
    {
        $emma = $this->children->create(['name' => 'Emma', 'theme' => 'fantasy']);
        $noah = $this->children->create(['name' => 'Noah', 'theme' => 'football']);

        $this->children->archive($emma);

        $active = $this->children->listActive();
        self::assertCount(1, $active);
        self::assertSame('Noah', $active[0]['name']);

        // Archived, not deleted (INV-001): row remains, history intact.
        $archived = $this->children->find($emma);
        self::assertNotNull($archived);
        self::assertNotNull($archived['archived_at']);

        self::assertCount(2, $this->children->listAll());
    }

    public function testArchiveRevokesQrTokens(): void
    {
        $id = $this->children->create(['name' => 'Emma', 'theme' => 'fantasy']);
        $this->db->execute(
            'INSERT INTO users (name, username, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Alex', 'alex', password_hash('x-long-password-1', PASSWORD_DEFAULT), 'parent']
        );
        $parentId = (int) $this->db->fetchOne('SELECT id FROM users LIMIT 1')['id'];

        $auth = new \FamilyCastel\Domain\AuthService($this->db);
        $token = $auth->regenerateChildToken($id, $parentId);
        self::assertNotNull($auth->childForToken($token));

        $this->children->archive($id);
        self::assertNull($auth->childForToken($token), 'archive must revoke QR access');
        $revoked = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM auth_tokens WHERE child_id = ? AND revoked_at IS NULL', [$id]
        );
        self::assertSame(0, (int) $revoked['c']);
    }
}
