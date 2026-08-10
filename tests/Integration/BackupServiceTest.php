<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\BackupService;
use FamilyCastel\Domain\LedgerService;
use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private Db $db;
    private string $storageDir;
    private BackupService $backups;

    protected function setUp(): void
    {
        $this->db = $this->connect();
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();

        $this->storageDir = sys_get_temp_dir() . '/fc-backup-' . bin2hex(random_bytes(4));
        mkdir($this->storageDir . '/backups', 0775, true);
        mkdir($this->storageDir . '/config-src', 0775, true);
        file_put_contents($this->storageDir . '/config-src/config.php', "<?php return ['db' => ['host' => 'test']];");
        mkdir($this->storageDir . '/uploads-src', 0775, true);
        file_put_contents($this->storageDir . '/uploads-src/photo.txt', 'pretend-upload');

        $this->backups = new BackupService(
            $this->db,
            backupsDir: $this->storageDir . '/backups',
            configFile: $this->storageDir . '/config-src/config.php',
            uploadsDir: $this->storageDir . '/uploads-src',
        );
    }

    protected function tearDown(): void
    {
        $this->wipe();
        exec('rm -rf ' . escapeshellarg($this->storageDir));
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

    private function seedData(): int
    {
        $this->db->execute(
            "INSERT INTO children (name, theme, created_at, updated_at) VALUES ('Emma', 'fantasy', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $childId = $this->db->lastInsertId();
        (new LedgerService($this->db))->post($childId, 42, 42, 'award', "Test's \"tricky\" title 🪙\nwith newline");

        return $childId;
    }

    public function testCreateBackupProducesZipWithMetaAndDump(): void
    {
        $this->seedData();
        $info = $this->backups->create('manual', createdBy: null);

        self::assertFileExists($info['zip']);
        self::assertFileExists(dirname($info['zip']) . '/meta.json');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($info['zip']) === true);
        self::assertNotFalse($zip->locateName('database.sql'));
        self::assertNotFalse($zip->locateName('meta.json'));
        self::assertNotFalse($zip->locateName('config/config.php'));
        self::assertNotFalse($zip->locateName('uploads/photo.txt'));
        $meta = json_decode($zip->getFromName('meta.json'), true);
        self::assertSame('manual', $meta['kind']);
        self::assertNotEmpty($meta['schema_version']);
        $zip->close();
    }

    public function testRestoreRoundTripReproducesData(): void
    {
        $childId = $this->seedData();
        $info = $this->backups->create('manual', createdBy: null);

        // Mutate after the backup: this data must disappear on restore.
        (new LedgerService($this->db))->post($childId, 100, 100, 'award', 'After backup');
        self::assertSame(142, (int) $this->db->fetchOne(
            'SELECT coin_balance FROM children WHERE id = ?', [$childId]
        )['coin_balance']);

        $this->backups->restoreDatabase($info['zip']);

        $child = $this->db->fetchOne('SELECT * FROM children WHERE name = ?', ['Emma']);
        self::assertSame(42, (int) $child['coin_balance']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM transactions')['c']);
        $tx = $this->db->fetchOne('SELECT title FROM transactions LIMIT 1');
        self::assertSame("Test's \"tricky\" title 🪙\nwith newline", $tx['title'], 'escaping survives round-trip');
    }

    public function testBinaryDataSurvivesRoundTrip(): void
    {
        $this->seedData();
        // Simulate binary content (sha-like token hash bytes) via auth_tokens…
        $binary = random_bytes(32);
        $this->db->execute(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())',
            ['test.binary', json_encode(base64_encode($binary))]
        );
        // …and a NUL byte in a text column — valid utf8mb4, but it forces the
        // dump onto the hex-literal path (invalid UTF-8 can't enter utf8mb4
        // columns at all, so NUL is the storable worst case).
        $this->db->execute(
            "INSERT INTO children (name, theme, created_at, updated_at) VALUES (?, 'fantasy', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            ["Bin\0ary\u{1F409}name"]
        );

        $info = $this->backups->create('manual', createdBy: null);
        $this->db->execute('DELETE FROM settings WHERE `key` = ?', ['test.binary']);
        $this->backups->restoreDatabase($info['zip']);

        $restored = $this->db->fetchOne('SELECT `value` FROM settings WHERE `key` = ?', ['test.binary']);
        self::assertNotNull($restored);
        self::assertSame($binary, base64_decode(json_decode($restored['value'], true)));
        $child = $this->db->fetchOne('SELECT name FROM children WHERE name LIKE ?', ['Bin%']);
        self::assertNotNull($child);
        self::assertSame("Bin\0ary\u{1F409}name", $child['name'], 'NUL byte + 4-byte emoji survive');
    }

    public function testListFromFilesystemIsSourceOfTruth(): void
    {
        $this->seedData();
        $this->backups->create('manual', createdBy: null);
        $this->backups->create('emergency', createdBy: null);

        // Even with an empty backup_history table, the filesystem lists both.
        $this->db->execute('DELETE FROM backup_history');
        $list = $this->backups->listFromFilesystem();
        self::assertCount(2, $list);
        $kinds = array_column($list, 'kind');
        sort($kinds);
        self::assertSame(['emergency', 'manual'], $kinds);
    }

    public function testRetentionNeverPrunesEmergency(): void
    {
        $this->seedData();
        for ($i = 0; $i < 3; $i++) {
            $this->backups->create('pre_update', createdBy: null);
        }
        $this->backups->create('emergency', createdBy: null);

        $this->backups->pruneRetention(keepPreUpdate: 2);

        $list = $this->backups->listFromFilesystem();
        $kinds = array_count_values(array_column($list, 'kind'));
        self::assertSame(2, $kinds['pre_update'] ?? 0, 'oldest pre_update pruned');
        self::assertSame(1, $kinds['emergency'] ?? 0, 'emergency NEVER pruned');
    }

    public function testRestoreRejectsForeignZip(): void
    {
        $foreign = $this->storageDir . '/foreign.zip';
        $zip = new \ZipArchive();
        $zip->open($foreign, \ZipArchive::CREATE);
        $zip->addFromString('random.txt', 'not a family castel backup');
        $zip->close();

        $this->expectException(\InvalidArgumentException::class);
        $this->backups->restoreDatabase($foreign);
    }

    public function testRestoreRejectsZipSlipPaths(): void
    {
        $evil = $this->storageDir . '/evil.zip';
        $zip = new \ZipArchive();
        $zip->open($evil, \ZipArchive::CREATE);
        $zip->addFromString('meta.json', json_encode(['app' => 'Family Castel', 'kind' => 'manual', 'schema_version' => '001', 'app_version' => '0.1.0', 'created_at' => 'x']));
        $zip->addFromString('database.sql', 'SELECT 1;');
        $zip->addFromString('../../../tmp/evil.txt', 'escape attempt');
        $zip->close();

        $this->expectException(\InvalidArgumentException::class);
        $this->backups->extractUploads($evil, $this->storageDir . '/restore-target');
    }
}
