<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use PHPUnit\Framework\TestCase;

final class DbConnectionTest extends TestCase
{
    public function testConnectsToMariaDbAndRunsPreparedQuery(): void
    {
        $db = Db::fromParams(
            host: getenv('FC_TEST_DB_HOST') ?: '127.0.0.1',
            port: (int) (getenv('FC_TEST_DB_PORT') ?: 3306),
            name: getenv('FC_TEST_DB_NAME') ?: 'familycastel_test',
            user: getenv('FC_TEST_DB_USER') ?: 'fc',
            password: getenv('FC_TEST_DB_PASS') ?: 'fc-dev-password',
        );

        $row = $db->fetchOne('SELECT ? + ? AS sum, VERSION() AS version', [20, 22]);

        self::assertSame(42, (int) $row['sum']);
        self::assertNotEmpty($row['version']);
        self::assertStringContainsString('MariaDB', $row['version']);
    }

    public function testExceptionsDoNotLeakCredentials(): void
    {
        try {
            Db::fromParams('db', 3306, 'familycastel_test', 'fc', 'WRONG-password-xyz');
            self::fail('Expected connection failure');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('WRONG-password-xyz', $e->getMessage());
        }
    }
}
