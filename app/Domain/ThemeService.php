<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

/**
 * Theme metadata: level titles and world tiers per theme. Themes are data —
 * adding one later means a new entry here + a world partial + CSS palette,
 * zero core-logic changes (plan §11). All artwork is original.
 */
final class ThemeService
{
    public const THEMES = ['fantasy', 'football'];

    /** Level → title key thresholds (highest matching wins). */
    private const TITLES = [
        'fantasy' => [
            1 => 'title.fantasy.villager',
            3 => 'title.fantasy.apprentice',
            5 => 'title.fantasy.squire',
            10 => 'title.fantasy.knight',
            15 => 'title.fantasy.champion',
            20 => 'title.fantasy.commander',
            30 => 'title.fantasy.hero',
            40 => 'title.fantasy.legend',
        ],
        'football' => [
            1 => 'title.football.rookie',
            3 => 'title.football.academy',
            5 => 'title.football.starter',
            10 => 'title.football.professional',
            15 => 'title.football.star',
            20 => 'title.football.captain',
            30 => 'title.football.champion',
            40 => 'title.football.legend',
        ],
    ];

    /** World growth tiers: reaching these levels visibly upgrades the world. */
    public const WORLD_TIERS = [1, 5, 10, 20, 40];

    public static function isValid(string $theme): bool
    {
        return in_array($theme, self::THEMES, true);
    }

    public static function titleKey(string $theme, int $level): string
    {
        $titles = self::TITLES[self::isValid($theme) ? $theme : 'fantasy'];
        $best = reset($titles);
        foreach ($titles as $minLevel => $key) {
            if ($level >= $minLevel) {
                $best = $key;
            }
        }

        return $best;
    }

    /** 1..5 — which world stage the level has unlocked. */
    public static function worldTier(int $level): int
    {
        $tier = 1;
        foreach (self::WORLD_TIERS as $index => $minLevel) {
            if ($level >= $minLevel) {
                $tier = $index + 1;
            }
        }

        return $tier;
    }

    /** Level at which the NEXT world stage unlocks (null at max). */
    public static function nextWorldTierLevel(int $level): ?int
    {
        foreach (self::WORLD_TIERS as $minLevel) {
            if ($level < $minLevel) {
                return $minLevel;
            }
        }

        return null;
    }

    /**
     * @param list<string>|null $allowedJson decoded children.allowed_themes
     * @return list<string>
     */
    public static function allowedThemes(?string $allowedJson): array
    {
        $decoded = json_decode((string) $allowedJson, true);
        if (!is_array($decoded)) {
            return self::THEMES;
        }
        $valid = array_values(array_intersect($decoded, self::THEMES));

        return $valid === [] ? self::THEMES : $valid;
    }
}
