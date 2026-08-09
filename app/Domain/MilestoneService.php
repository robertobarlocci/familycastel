<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Long-term goals ("Switch 2 — 1000 Coins"). Progress is measured on the
 * child's CURRENT coin balance (savings model). Claiming a 'spend' milestone
 * deducts the target through the ledger; 'progress_only' just celebrates.
 * Wishes (milestone_requests) are approved into milestones by parents.
 */
final class MilestoneService
{
    public function __construct(
        private readonly Db $db,
        private readonly ?LedgerService $ledger = null,
    ) {
    }

    public function create(int $childId, string $title, int $targetCoins, ?string $spendMode = null): int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Milestone title is required (max 190 characters).');
        }
        if ($targetCoins < 1 || $targetCoins > 1_000_000) {
            throw new \InvalidArgumentException('Milestone target must be between 1 and 1000000 Coins.');
        }
        $spendMode ??= (string) (new SettingsService($this->db))->get('milestones.default_spend_mode', 'spend');
        if (!in_array($spendMode, ['spend', 'progress_only'], true)) {
            throw new \InvalidArgumentException('Unknown spend mode.');
        }

        return WriteGate::transaction($this->db, function (Db $db) use ($childId, $title, $targetCoins, $spendMode): int {
            $child = $db->fetchOne('SELECT id FROM children WHERE id = ? AND archived_at IS NULL', [$childId]);
            if ($child === null) {
                throw new \InvalidArgumentException('Child not found or archived.');
            }
            $db->execute(
                'INSERT INTO milestones (child_id, title, target_coins, spend_mode, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$childId, $title, $targetCoins, $spendMode]
            );

            return $db->lastInsertId();
        });
    }

    public function archive(int $milestoneId): void
    {
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            "UPDATE milestones SET status = 'archived', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'active'",
            [$milestoneId]
        ));
    }

    /** @return array{current: int, target: int, fraction: float, remaining: int} */
    public function progress(int $milestoneId): array
    {
        $milestone = $this->db->fetchOne('SELECT * FROM milestones WHERE id = ?', [$milestoneId]);
        if ($milestone === null) {
            throw new \InvalidArgumentException('Milestone not found.');
        }
        $balance = (int) ($this->db->fetchOne(
            'SELECT coin_balance FROM children WHERE id = ?', [$milestone['child_id']]
        )['coin_balance'] ?? 0);
        $target = (int) $milestone['target_coins'];
        $current = max(0, min($balance, $target));

        return [
            'current' => $current,
            'target' => $target,
            'fraction' => $target > 0 ? $current / $target : 0.0,
            'remaining' => max(0, $target - $balance),
        ];
    }

    /**
     * Parent claims a reached milestone. 'spend' deducts the target through
     * the ledger (idempotent via status guard + unique key); 'progress_only'
     * only flips the status.
     */
    public function claim(int $milestoneId, int $parentId): void
    {
        WriteGate::transaction($this->db, function (Db $db) use ($milestoneId, $parentId): void {
            $milestone = $db->fetchOne('SELECT * FROM milestones WHERE id = ? FOR UPDATE', [$milestoneId]);
            if ($milestone === null) {
                throw new \InvalidArgumentException('Milestone not found.');
            }

            $updated = $db->execute(
                "UPDATE milestones SET status = 'claimed', claimed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'active'",
                [$milestoneId]
            );
            if ($updated === 0) {
                return; // already claimed/archived — idempotent
            }

            if ($milestone['spend_mode'] === 'spend') {
                $ledger = $this->ledger ?? new LedgerService($db);
                $txId = $ledger->post(
                    childId: (int) $milestone['child_id'],
                    coinsDelta: -(int) $milestone['target_coins'],
                    xpDelta: 0,
                    type: 'milestone_spend',
                    title: (string) $milestone['title'],
                    actorUserId: $parentId,
                    sourceType: 'milestone',
                    sourceId: $milestoneId,
                    idempotencyKey: 'milestone:' . $milestoneId,
                );
                $db->execute('UPDATE milestones SET transaction_id = ? WHERE id = ?', [$txId, $milestoneId]);
            }
        });
    }

    public function submitWish(int $childId, string $title, int $suggestedCoins): int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Please describe your wish (max 190 characters).');
        }
        if ($suggestedCoins < 1 || $suggestedCoins > 1_000_000) {
            throw new \InvalidArgumentException('Suggested cost out of range.');
        }

        return WriteGate::transaction($this->db, function (Db $db) use ($childId, $title, $suggestedCoins): int {
            $child = $db->fetchOne('SELECT id FROM children WHERE id = ? AND archived_at IS NULL', [$childId]);
            if ($child === null) {
                throw new \InvalidArgumentException('Child not found or archived.');
            }
            $db->execute(
                'INSERT INTO milestone_requests (child_id, title, suggested_coins, status, created_at)
                 VALUES (?, ?, ?, \'pending\', UTC_TIMESTAMP())',
                [$childId, $title, $suggestedCoins]
            );

            return $db->lastInsertId();
        });
    }

    /** @return bool whether this call actually decided the wish */
    public function approveWish(int $requestId, int $parentId, ?int $targetOverride = null, ?string $comment = null): bool
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($requestId, $parentId, $targetOverride, $comment): bool {
            $request = $db->fetchOne('SELECT * FROM milestone_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($request === null) {
                throw new \InvalidArgumentException('Wish not found.');
            }

            $updated = $db->execute(
                "UPDATE milestone_requests
                 SET status = 'approved', parent_comment = ?, decided_by = ?, decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'pending'",
                [$comment, $parentId, $requestId]
            );
            if ($updated === 0) {
                return false;
            }

            $milestoneId = $this->create(
                (int) $request['child_id'],
                (string) $request['title'],
                $targetOverride ?? (int) $request['suggested_coins'],
            );
            $db->execute('UPDATE milestone_requests SET milestone_id = ? WHERE id = ?', [$milestoneId, $requestId]);

            return true;
        });
    }

    /** @return bool whether this call actually decided the wish */
    public function rejectWish(int $requestId, int $parentId, ?string $comment = null): bool
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($requestId, $parentId, $comment): bool {
            return $db->execute(
                "UPDATE milestone_requests
                 SET status = 'rejected', parent_comment = ?, decided_by = ?, decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'pending'",
                [$comment, $parentId, $requestId]
            ) > 0;
        });
    }

    /** @return list<array<string, mixed>> active milestones for a child with progress */
    public function activeFor(int $childId): array
    {
        $milestones = $this->db->fetchAll(
            "SELECT * FROM milestones WHERE child_id = ? AND status = 'active' ORDER BY target_coins",
            [$childId]
        );
        $balance = (int) ($this->db->fetchOne(
            'SELECT coin_balance FROM children WHERE id = ?', [$childId]
        )['coin_balance'] ?? 0);

        return array_map(static function (array $m) use ($balance): array {
            $target = (int) $m['target_coins'];
            $current = max(0, min($balance, $target));
            $m['progress_current'] = $current;
            $m['progress_fraction'] = $target > 0 ? $current / $target : 0.0;
            $m['progress_remaining'] = max(0, $target - $balance);

            return $m;
        }, $milestones);
    }

    /** @return list<array<string, mixed>> */
    public function pendingWishes(): array
    {
        return $this->db->fetchAll(
            "SELECT r.*, c.name AS child_name FROM milestone_requests r
             JOIN children c ON c.id = r.child_id
             WHERE r.status = 'pending' ORDER BY r.created_at"
        );
    }
}
