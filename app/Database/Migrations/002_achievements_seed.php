<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * Default achievement definitions. Rules are JSON {metric, threshold}:
 * metrics: lifetime_coins (sum of positive coin deltas), xp_total,
 * sidequests_approved, level. Cosmetic — no Coins granted (brief).
 */
return static fn (Db $db) => new class($db) extends Migration {
    private const ACHIEVEMENTS = [
        ['first_coins',      'achievement.first_coins',      'common',    ['metric' => 'lifetime_coins', 'threshold' => 1]],
        ['coins_100',        'achievement.coins_100',        'common',    ['metric' => 'lifetime_coins', 'threshold' => 100]],
        ['coins_1000',       'achievement.coins_1000',       'rare',      ['metric' => 'lifetime_coins', 'threshold' => 1000]],
        ['coins_5000',       'achievement.coins_5000',       'epic',      ['metric' => 'lifetime_coins', 'threshold' => 5000]],
        ['xp_100',           'achievement.xp_100',           'common',    ['metric' => 'xp_total', 'threshold' => 100]],
        ['xp_1000',          'achievement.xp_1000',          'rare',      ['metric' => 'xp_total', 'threshold' => 1000]],
        ['xp_10000',         'achievement.xp_10000',         'legendary', ['metric' => 'xp_total', 'threshold' => 10000]],
        ['quest_1',          'achievement.quest_1',          'common',    ['metric' => 'sidequests_approved', 'threshold' => 1]],
        ['quest_10',         'achievement.quest_10',         'common',    ['metric' => 'sidequests_approved', 'threshold' => 10]],
        ['quest_50',         'achievement.quest_50',         'rare',      ['metric' => 'sidequests_approved', 'threshold' => 50]],
        ['quest_100',        'achievement.quest_100',        'epic',      ['metric' => 'sidequests_approved', 'threshold' => 100]],
        ['level_5',          'achievement.level_5',          'common',    ['metric' => 'level', 'threshold' => 5]],
        ['level_10',         'achievement.level_10',         'rare',      ['metric' => 'level', 'threshold' => 10]],
        ['level_25',         'achievement.level_25',         'epic',      ['metric' => 'level', 'threshold' => 25]],
        ['level_40',         'achievement.level_40',         'legendary', ['metric' => 'level', 'threshold' => 40]],
    ];

    public function up(): void
    {
        foreach (self::ACHIEVEMENTS as [$code, $key, $rarity, $rule]) {
            $this->db->execute(
                'INSERT INTO achievements (code, title_key, description_key, rarity, rule, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE title_key = VALUES(title_key)',
                [$code, $key . '.title', $key . '.desc', $rarity, json_encode($rule, JSON_THROW_ON_ERROR)]
            );
        }
    }

    public function down(): void
    {
        $codes = array_column(self::ACHIEVEMENTS, 0);
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        // Guarded: only definitions nobody has unlocked can be removed.
        $this->db->execute(
            "DELETE FROM achievements WHERE code IN ({$placeholders})
             AND id NOT IN (SELECT achievement_id FROM child_achievements)",
            $codes
        );
    }
};
