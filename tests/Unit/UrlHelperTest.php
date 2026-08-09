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
}
