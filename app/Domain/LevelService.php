<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

/**
 * Configurable XP curve (plan §4): cumulative XP required to REACH level n is
 * round(base * (n-1)^exponent); level 1 = 0 XP. Defined here once — never
 * hardcoded anywhere else.
 */
final class LevelService
{
    /** Hard cap — keeps levelForXp O(1)-ish and lock hold times bounded. */
    public const MAX_LEVEL = 500;

    private readonly int $base;
    private readonly float $exponent;

    public function __construct(int $base = 50, float $exponent = 1.6)
    {
        // Clamp unvalidated settings input — a curve of base 0 / exponent 0
        // would make every XP value level 500 and a negative exponent would
        // break monotonicity.
        $this->base = max(1, min(1_000_000, $base));
        $this->exponent = max(1.0, min(3.0, $exponent));
    }

    public function xpForLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        return (int) round($this->base * (($level - 1) ** $this->exponent));
    }

    public function levelForXp(int $xp): int
    {
        $level = 1;
        while ($level < self::MAX_LEVEL && $this->xpForLevel($level + 1) <= $xp) {
            $level++;
        }

        return $level;
    }

    /** @return array{level: int, level_xp: int, next_level_xp: int, fraction: float} */
    public function progress(int $xp): array
    {
        $level = $this->levelForXp($xp);
        $current = $this->xpForLevel($level);
        $next = $this->xpForLevel($level + 1);
        $span = max(1, $next - $current);

        return [
            'level' => $level,
            'level_xp' => $current,
            'next_level_xp' => $next,
            'fraction' => ($xp - $current) / $span,
        ];
    }

    public static function fromSettings(SettingsService $settings): self
    {
        $curve = $settings->get('economy.xp_curve', ['base' => 50, 'exponent' => 1.6]);

        return new self(
            base: (int) ($curve['base'] ?? 50),
            exponent: (float) ($curve['exponent'] ?? 1.6),
        );
    }
}
