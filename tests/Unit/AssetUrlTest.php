<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\Assets;
use FamilyCastel\Core\BasePath;
use PHPUnit\Framework\TestCase;

/**
 * The asset version token. It exists so a released CSS/JS change actually
 * reaches a returning browser: without it a cached stylesheet is reused under
 * Apache's heuristic freshness (no Cache-Control ships), which is what made a
 * phone render the parent portal with the pre-v0.1.1 desktop navbar.
 */
final class AssetUrlTest extends TestCase
{
    protected function setUp(): void
    {
        Assets::setVersion('0.1.3');
    }

    protected function tearDown(): void
    {
        BasePath::set('');
        BasePath::setPrettyUrls(true);
        Assets::setVersion('0.1.3');
    }

    public function testVersionIsTheValueThatWasSet(): void
    {
        Assets::setVersion('1.2.3');
        self::assertSame('1.2.3', Assets::version());
    }

    public function testEmptyVersionFallsBackInsteadOfEmittingABareToken(): void
    {
        Assets::setVersion('');
        self::assertSame('0', Assets::version());
    }

    public function testVersionMadeEmptyBySanitisationFallsBack(): void
    {
        Assets::setVersion('!!!');
        self::assertSame('0', Assets::version());
    }

    /**
     * VERSION is a file on disk, so it is an input like any other: a corrupt
     * one must never be able to close the attribute it is rendered into.
     */
    public function testVersionIsSanitisedToAnAllowlist(): void
    {
        Assets::setVersion("0.1.3\n \"x");
        self::assertSame('0.1.3x', Assets::version());

        Assets::setVersion('0.1.3"><script>');
        self::assertSame('0.1.3script', Assets::version());
    }

    public function testAssetAppendsTheTokenAtDomainRoot(): void
    {
        BasePath::set('');
        self::assertSame(
            '/public-assets/css/app.css?v=0.1.3',
            Assets::url('/public-assets/css/app.css')
        );
    }

    public function testAssetJoinsAnExistingQueryWithAmpersand(): void
    {
        BasePath::set('');
        self::assertSame(
            '/public-assets/css/app.css?a=1&v=0.1.3',
            Assets::url('/public-assets/css/app.css?a=1')
        );
    }
}
