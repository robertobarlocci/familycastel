<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Config;
use FamilyCastel\Core\Db;
use FamilyCastel\Install\Installer;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $configDir;
    private Db $db;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/fc-installer-' . bin2hex(random_bytes(4));
        mkdir($this->configDir, 0775, true);
        file_put_contents($this->configDir . '/CAN_INSTALL', '');

        $this->db = $this->connect();
        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
        array_map(unlink(...), glob($this->configDir . '/*') ?: []);
        @rmdir($this->configDir);
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

    private function dropAllTables(): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
            $table = str_replace('`', '``', (string) array_values($row)[0]);
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function installParams(): array
    {
        return [
            'db' => [
                'host' => getenv('FC_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('FC_TEST_DB_PORT') ?: 3306),
                'name' => getenv('FC_TEST_DB_NAME') ?: 'familycastel_test',
                'user' => getenv('FC_TEST_DB_USER') ?: 'fc',
                'password' => getenv('FC_TEST_DB_PASS') ?: 'fc-dev-password',
                'prefix' => '',
            ],
            'family' => ['name' => 'Familie Test', 'locale' => 'de', 'timezone' => 'Europe/Zurich'],
            'parent' => ['name' => 'Alex', 'username' => 'alex', 'password' => 'super-secret-password-1'],
        ];
    }

    public function testPerformInstallsCompletely(): void
    {
        $installer = new Installer($this->configDir, FC_ROOT . '/app/Database/Migrations');
        $installer->perform($this->installParams());

        // Config written and valid
        $config = new Config($this->configDir);
        self::assertTrue($config->isInstalled());
        self::assertSame('de', $config->get('app.locale'));
        self::assertNotSame('GENERATED-BY-INSTALLER', $config->get('app.secret'));
        self::assertSame(64, strlen((string) $config->get('app.secret')));

        // Lock written, CAN_INSTALL consumed
        self::assertFileExists($this->configDir . '/installed.lock');
        self::assertFileDoesNotExist($this->configDir . '/CAN_INSTALL');

        // Schema migrated, parent created with hashed password
        $user = $this->db->fetchOne('SELECT * FROM users WHERE username = ?', ['alex']);
        self::assertNotNull($user);
        self::assertTrue(password_verify('super-secret-password-1', $user['password_hash']));

        // Family settings stored
        $setting = $this->db->fetchOne('SELECT `value` FROM settings WHERE `key` = ?', ['family.name']);
        self::assertNotNull($setting);
        self::assertSame('Familie Test', json_decode($setting['value'], true));
    }

    public function testPerformRefusesWhenAlreadyInstalled(): void
    {
        $installer = new Installer($this->configDir, FC_ROOT . '/app/Database/Migrations');
        $installer->perform($this->installParams());

        $this->expectException(\RuntimeException::class);
        $installer->perform($this->installParams());
    }

    public function testPerformRefusesWithoutCanInstallMarker(): void
    {
        unlink($this->configDir . '/CAN_INSTALL');
        $installer = new Installer($this->configDir, FC_ROOT . '/app/Database/Migrations');

        $this->expectException(\RuntimeException::class);
        $installer->perform($this->installParams());
    }

    public function testPerformRefusesWeakPassword(): void
    {
        $params = $this->installParams();
        $params['parent']['password'] = 'short';
        $installer = new Installer($this->configDir, FC_ROOT . '/app/Database/Migrations');

        $this->expectException(\InvalidArgumentException::class);
        $installer->perform($params);
    }

    public function testConfigFileIsValidPhpReturningArray(): void
    {
        $installer = new Installer($this->configDir, FC_ROOT . '/app/Database/Migrations');
        $installer->perform($this->installParams());

        $loaded = require $this->configDir . '/config.php';
        self::assertIsArray($loaded);
        self::assertArrayHasKey('db', $loaded);
        self::assertArrayHasKey('app', $loaded);
    }
}
