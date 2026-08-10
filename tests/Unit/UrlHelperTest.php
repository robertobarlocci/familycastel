<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\Assets;
use FamilyCastel\Core\BasePath;
use PHPUnit\Framework\TestCase;

final class UrlHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        BasePath::set('');
        BasePath::setPrettyUrls(true);
        Assets::setVersion('0');
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

    public function testAssetHelperAddsTheVersionTokenAtDomainRoot(): void
    {
        BasePath::set('');
        Assets::setVersion('0.1.3');

        self::assertSame('/public-assets/css/app.css?v=0.1.3', asset('/public-assets/css/app.css'));
        self::assertSame('/public-assets/js/navigation.js?v=0.1.3', asset('/public-assets/js/navigation.js'));
    }

    public function testAssetHelperKeepsTheSubdirectoryPrefix(): void
    {
        BasePath::set('/family');
        Assets::setVersion('0.1.3');

        self::assertSame('/family/public-assets/css/app.css?v=0.1.3', asset('/public-assets/css/app.css'));
    }

    /**
     * The production install runs in ?r= mode (issue #4). A versioned asset must
     * stay a DIRECT file there: routing it through index.php?r= would 404 it,
     * which is how a whole site loses its styling.
     */
    public function testVersionedAssetsStayDirectFilesInQueryRoutingMode(): void
    {
        BasePath::set('/family');
        BasePath::setPrettyUrls(false);
        Assets::setVersion('0.1.3');

        self::assertSame('/family/public-assets/css/app.css?v=0.1.3', asset('/public-assets/css/app.css'));
        self::assertSame('/family/sw.js?v=0.1.3', asset('/sw.js'));
    }

    /** url() itself must not gain a token — only asset() versions. */
    public function testUrlHelperStaysUnversioned(): void
    {
        BasePath::set('');
        Assets::setVersion('0.1.3');

        self::assertSame('/public-assets/icons/icon.svg', url('/public-assets/icons/icon.svg'));
        self::assertSame('/sw.js', url('/sw.js'));
    }
}
