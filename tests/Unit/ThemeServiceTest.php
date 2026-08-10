<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Domain\ThemeService;
use PHPUnit\Framework\TestCase;

final class ThemeServiceTest extends TestCase
{
    public function testValidThemes(): void
    {
        self::assertTrue(ThemeService::isValid('fantasy'));
        self::assertTrue(ThemeService::isValid('football'));
        self::assertFalse(ThemeService::isValid('office-suite'));
        self::assertFalse(ThemeService::isValid(''));
    }

    public function testTitleKeyProgressesWithLevel(): void
    {
        $low = ThemeService::titleKey('fantasy', 1);
        $mid = ThemeService::titleKey('fantasy', 20);
        $top = ThemeService::titleKey('fantasy', 500);
        self::assertNotSame($low, $mid);
        self::assertNotSame($mid, $top);
        self::assertStringStartsWith('title.fantasy.', $low);
        self::assertStringStartsWith('title.football.', ThemeService::titleKey('football', 3));
    }

    public function testWorldTiersMatchThePlan(): void
    {
        // Plan §11: tiers at levels 1 / 5 / 10 / 20 / 40.
        self::assertSame(1, ThemeService::worldTier(1));
        self::assertSame(1, ThemeService::worldTier(4));
        self::assertSame(2, ThemeService::worldTier(5));
        self::assertSame(3, ThemeService::worldTier(10));
        self::assertSame(4, ThemeService::worldTier(20));
        self::assertSame(5, ThemeService::worldTier(40));
        self::assertSame(5, ThemeService::worldTier(500), 'top tier is a plateau');
    }

    public function testNextWorldTierLevel(): void
    {
        self::assertSame(5, ThemeService::nextWorldTierLevel(1));
        self::assertSame(10, ThemeService::nextWorldTierLevel(7));
        self::assertSame(40, ThemeService::nextWorldTierLevel(20));
        self::assertNull(ThemeService::nextWorldTierLevel(40), 'no tier beyond the last');
        self::assertNull(ThemeService::nextWorldTierLevel(99));
    }

    public function testAllowedThemesParsesJsonAllowlist(): void
    {
        self::assertSame(['fantasy', 'football'], ThemeService::allowedThemes(null), 'null = everything');
        self::assertSame(['fantasy'], ThemeService::allowedThemes('["fantasy"]'));
        self::assertSame(['fantasy', 'football'], ThemeService::allowedThemes('not json'), 'garbage falls open to all');
        self::assertSame(
            ['football'],
            ThemeService::allowedThemes('["football", "made-up"]'),
            'unknown entries are dropped'
        );
    }
}
