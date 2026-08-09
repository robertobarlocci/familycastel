<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fc-config-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config.php');
        @unlink($this->dir . '/installed.lock');
        @rmdir($this->dir);
    }

    public function testNotInstalledWhenConfigMissing(): void
    {
        $config = new Config($this->dir);
        self::assertFalse($config->isInstalled());
    }

    public function testNotInstalledWhenLockMissing(): void
    {
        file_put_contents($this->dir . '/config.php', "<?php return ['db' => []];");
        $config = new Config($this->dir);
        self::assertFalse($config->isInstalled());
    }

    public function testInstalledWhenConfigAndLockExist(): void
    {
        file_put_contents($this->dir . '/config.php', "<?php return ['db' => ['host' => 'x']];");
        file_put_contents($this->dir . '/installed.lock', '{"installed_at":"2026-08-09"}');
        $config = new Config($this->dir);
        self::assertTrue($config->isInstalled());
    }

    public function testGetReadsDottedKeysWithDefault(): void
    {
        file_put_contents(
            $this->dir . '/config.php',
            "<?php return ['db' => ['host' => 'localhost', 'port' => 3306], 'app' => ['locale' => 'de']];"
        );
        $config = new Config($this->dir);
        self::assertSame('localhost', $config->get('db.host'));
        self::assertSame(3306, $config->get('db.port'));
        self::assertSame('de', $config->get('app.locale'));
        self::assertSame('fallback', $config->get('missing.key', 'fallback'));
    }
}
