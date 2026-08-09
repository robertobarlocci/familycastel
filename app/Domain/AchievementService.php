<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Permanent, cosmetic achievements (no Coins — brief). sync() evaluates all
 * active rules against a child's metrics and unlocks anything newly reached;
 * UNIQUE(child_id, achievement_id) makes unlocks exactly-once. Unlocks are
 * never removed (INV-001).
 */
final class AchievementService
{
    public function __construct(
        private readonly Db $db,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    /**
     * Evaluate + unlock. Returns the newly unlocked achievement rows.
     * Called after every award/approval — cheap (one metrics query).
     *
     * @return list<array<string, mixed>>
     */
    public function sync(int $childId): array
    {
        $metrics = $this->metrics($childId);
        $unlocked = [];

        $definitions = $this->db->fetchAll('SELECT * FROM achievements WHERE is_active = 1');
        foreach ($definitions as $achievement) {
            $rule = json_decode((string) $achievement['rule'], true);
            $metric = (string) ($rule['metric'] ?? '');
            $threshold = (int) ($rule['threshold'] ?? PHP_INT_MAX);
            if (!isset($metrics[$metric]) || $metrics[$metric] < $threshold) {
                continue;
            }

            $inserted = WriteGate::transaction($this->db, function (Db $db) use ($childId, $achievement): int {
                try {
                    return $db->execute(
                        'INSERT INTO child_achievements (child_id, achievement_id, unlocked_at)
                         VALUES (?, ?, UTC_TIMESTAMP())',
                        [$childId, $achievement['id']]
                    );
                } catch (\PDOException $e) {
                    if (($e->errorInfo[0] ?? '') === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                        return 0; // already unlocked — exactly-once
                    }
                    throw $e;
                }
            });

            if ($inserted > 0) {
                $unlocked[] = $achievement;
                $this->notifications?->notify('child', $childId, 'achievement_unlocked', [
                    'code' => $achievement['code'],
                    'rarity' => $achievement['rarity'],
                ]);
            }
        }

        return $unlocked;
    }

    /** @return array<string, int> */
    public function metrics(int $childId): array
    {
        $child = $this->db->fetchOne('SELECT xp_total, level FROM children WHERE id = ?', [$childId]);
        $lifetime = $this->db->fetchOne(
            'SELECT COALESCE(SUM(CASE WHEN coins_delta > 0 THEN coins_delta ELSE 0 END), 0) AS earned
             FROM transactions WHERE child_id = ?',
            [$childId]
        );
        $quests = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM sidequest_claims WHERE child_id = ? AND status = 'approved'",
            [$childId]
        );

        return [
            'lifetime_coins' => (int) ($lifetime['earned'] ?? 0),
            'xp_total' => (int) ($child['xp_total'] ?? 0),
            'level' => (int) ($child['level'] ?? 1),
            'sidequests_approved' => (int) ($quests['c'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> unlocked, newest first */
    public function unlockedFor(int $childId): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, ca.unlocked_at, ca.seen_at FROM child_achievements ca
             JOIN achievements a ON a.id = ca.achievement_id
             WHERE ca.child_id = ? ORDER BY ca.unlocked_at DESC',
            [$childId]
        );
    }

    /** @return list<array<string, mixed>> all active definitions with unlock state */
    public function allWithState(int $childId): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, ca.unlocked_at FROM achievements a
             LEFT JOIN child_achievements ca ON ca.achievement_id = a.id AND ca.child_id = ?
             WHERE a.is_active = 1
             ORDER BY ca.unlocked_at IS NULL, FIELD(a.rarity, \'legendary\',\'epic\',\'rare\',\'common\'), a.id',
            [$childId]
        );
    }

    /** Mark fresh unlocks as seen (celebration shown once — no animation fatigue). */
    public function markSeen(int $childId): void
    {
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE child_achievements SET seen_at = UTC_TIMESTAMP() WHERE child_id = ? AND seen_at IS NULL',
            [$childId]
        ));
    }

    /** @return list<array<string, mixed>> unlocked but not yet celebrated */
    public function unseenFor(int $childId): array
    {
        return $this->db->fetchAll(
            'SELECT a.* FROM child_achievements ca
             JOIN achievements a ON a.id = ca.achievement_id
             WHERE ca.child_id = ? AND ca.seen_at IS NULL',
            [$childId]
        );
    }

    /**
     * Atomically read-and-consume the celebration queue: rows are locked and
     * marked seen in ONE gated transaction, so concurrent page loads can
     * never both celebrate the same unlock.
     *
     * @return list<array<string, mixed>>
     */
    public function takeUnseen(int $childId): array
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($childId): array {
            $rows = $db->fetchAll(
                'SELECT a.*, ca.id AS ca_id FROM child_achievements ca
                 JOIN achievements a ON a.id = ca.achievement_id
                 WHERE ca.child_id = ? AND ca.seen_at IS NULL FOR UPDATE',
                [$childId]
            );
            if ($rows !== []) {
                $db->execute(
                    'UPDATE child_achievements SET seen_at = UTC_TIMESTAMP() WHERE child_id = ? AND seen_at IS NULL',
                    [$childId]
                );
            }

            return $rows;
        });
    }
}
