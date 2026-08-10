<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Domain\LevelService;
use PHPUnit\Framework\TestCase;

final class LevelServiceTest extends TestCase
{
    private LevelService $levels;

    protected function setUp(): void
    {
        // Default curve: XP needed to REACH level n = round(50 * (n-1)^1.6),
        // cumulative — level 1 = 0 XP.
        $this->levels = new LevelService(base: 50, exponent: 1.6);
    }

    public function testLevelOneAtZeroXp(): void
    {
        self::assertSame(1, $this->levels->levelForXp(0));
    }

    public function testThresholdsAreMonotonic(): void
    {
        $previous = -1;
        for ($level = 1; $level <= 60; $level++) {
            $threshold = $this->levels->xpForLevel($level);
            self::assertGreaterThan($previous, $threshold, "level {$level}");
            $previous = $threshold;
        }
    }

    public function testLevelForXpMatchesThresholds(): void
    {
        foreach ([2, 5, 10, 25, 40] as $level) {
            $threshold = $this->levels->xpForLevel($level);
            self::assertSame($level, $this->levels->levelForXp($threshold));
            self::assertSame($level - 1, $this->levels->levelForXp($threshold - 1));
        }
    }

    public function testProgressWithinLevel(): void
    {
        $xpAt5 = $this->levels->xpForLevel(5);
        $xpAt6 = $this->levels->xpForLevel(6);
        $midway = intdiv($xpAt5 + $xpAt6, 2);

        $progress = $this->levels->progress($midway);
        self::assertSame(5, $progress['level']);
        self::assertSame($xpAt6, $progress['next_level_xp']);
        self::assertGreaterThan(0.4, $progress['fraction']);
        self::assertLessThan(0.6, $progress['fraction']);
    }

    public function testHugeXpDoesNotLoopForever(): void
    {
        self::assertGreaterThan(50, $this->levels->levelForXp(100_000_000));
    }
}
