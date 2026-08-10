<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\BasePath;
use PHPUnit\Framework\TestCase;

final class UrlHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        BasePath::set('');
        BasePath::setPrettyUrls(true);
    }

    public function testUrlAtDomainRoot(): void
    {
        BasePath::set('');
        self::assertSame('/kid', url('/kid'));
        self::assertSame('/', url('/'));
        self::assertSame('/public-assets/css/app.css', url('/public-assets/css/app.css'));
    }

    public function testUrlInSubdirectory(): void
    {
        BasePath::set('/family');
        self::assertSame('/family/kid', url('/kid'));
        self::assertSame('/family/', url('/'));
    }

    public function testBasePathDetectionFromScriptName(): void
    {
        self::assertSame('', BasePath::detect('/index.php'));
        self::assertSame('/family', BasePath::detect('/family/index.php'));
        self::assertSame('/a/b', BasePath::detect('/a/b/index.php'));
        self::assertSame('', BasePath::detect('/update.php'));
    }

    public function testUrlNeverProducesDoubleSlashes(): void
    {
        BasePath::set('/family/');
        self::assertSame('/family/kid', url('/kid'));
        BasePath::set('/');
        self::assertSame('/kid', url('/kid'));
    }

    public function testUninstalledAppAlwaysBootsWithQueryRouting(): void
    {
        self::assertFalse(BasePath::shouldUsePrettyUrls(false, true));
        self::assertFalse(BasePath::shouldUsePrettyUrls(false, false));
    }

    public function testInstalledAppUsesItsDetectedRoutingMode(): void
    {
        self::assertTrue(BasePath::shouldUsePrettyUrls(true, true));
        self::assertFalse(BasePath::shouldUsePrettyUrls(true, false));
    }

    public function testQueryRoutingPreservesParametersAndDirectAssets(): void
    {
        BasePath::set('/family');
        BasePath::setPrettyUrls(false);

        self::assertFalse(BasePath::prettyUrls());
        self::assertSame('/family/index.php?r=%2Fkid%2Frewards&filter=open', url('/kid/rewards?filter=open'));
        self::assertSame('/family/public-assets/css/app.css', url('/public-assets/css/app.css'));
        self::assertSame('/family/sw.js', url('/sw.js'));
        self::assertSame('/family/offline.html', url('/offline.html'));
        self::assertSame('/family/update.php', url('/update.php'));
    }
}
