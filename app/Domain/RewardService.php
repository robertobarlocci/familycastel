<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Rewards + redemption. A pending reward_request IS the reservation: it
 * reduces the available balance (LedgerService) without spending. Reservation
 * creation checks affordability under the child row lock — two tabs cannot
 * reserve the same coins twice (plan §6). Approval flips the status FIRST
 * (releasing its own reservation) and then spends through the ledger; if the
 * spend fails (forced deduction consumed funds) the whole transaction rolls
 * back and the request stays pending with an explanation for the parent.
 */
final class RewardService
{
    public function __construct(
        private readonly Db $db,
        private readonly ?LedgerService $ledger = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createReward(array $data): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Reward title is required (max 190 characters).');
        }
        $cost = (int) ($data['cost_coins'] ?? 0);
        if ($cost < 1 || $cost > 100_000) {
            throw new \InvalidArgumentException('Reward cost must be between 1 and 100000 Coins.');
        }
        $duration = ($data['duration_minutes'] ?? '') !== '' ? max(1, (int) $data['duration_minutes']) : null;

        return WriteGate::transaction($this->db, function (Db $db) use ($title, $data, $cost, $duration): int {
            $db->execute(
                'INSERT INTO rewards (title, description, cost_coins, duration_minutes, icon, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [
                    $title,
                    trim((string) ($data['description'] ?? '')) ?: null,
                    $cost,
                    $duration,
                    trim((string) ($data['icon'] ?? '')) ?: null,
                ]
            );

            return $db->lastInsertId();
        });
    }

    public function archiveReward(int $rewardId): void
    {
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            "UPDATE rewards SET status = 'archived', archived_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?",
            [$rewardId]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function activeRewards(): array
    {
        return $this->db->fetchAll("SELECT * FROM rewards WHERE status = 'active' ORDER BY cost_coins, title");
    }

    /**
     * Child redeems a catalog reward → pending request that RESERVES coins.
     * Affordability is checked under the child row lock (race-free).
     */
    public function request(int $rewardId, int $childId): int
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($rewardId, $childId): int {
            $reward = $db->fetchOne(
                "SELECT * FROM rewards WHERE id = ? AND status = 'active' FOR UPDATE", [$rewardId]
            );
            if ($reward === null) {
                throw new \InvalidArgumentException('Reward not available.');
            }

            return $this->createRequest(
                $db,
                $childId,
                (int) $reward['id'],
                (string) $reward['title'],
                (int) $reward['cost_coins'],
                $reward['duration_minutes'] !== null ? (int) $reward['duration_minutes'] : null,
            );
        });
    }

    /** Child proposes a custom reward (cost may be unknown → 0 until parent sets it). */
    public function requestCustom(int $childId, string $title, ?int $durationMinutes): int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Please describe your wish (max 190 characters).');
        }

        return WriteGate::transaction($this->db, function (Db $db) use ($childId, $title, $durationMinutes): int {
            return $this->createRequest($db, $childId, null, $title, 0, $durationMinutes);
        });
    }

    /**
     * Approve: status flip first (own reservation released), then spend.
     * A failed spend rolls the flip back — the request stays pending.
     */
    /** @return bool whether this call actually decided the request */
    public function approve(int $requestId, int $parentId, ?int $costOverride = null, ?string $comment = null): bool
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($requestId, $parentId, $costOverride, $comment): bool {
            $request = $db->fetchOne('SELECT * FROM reward_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($request === null) {
                throw new \InvalidArgumentException('Request not found.');
            }

            $cost = $costOverride ?? (int) $request['cost_coins'];
            if ($cost < 0 || $cost > 100_000) {
                throw new \InvalidArgumentException('Cost out of range.');
            }

            // cost_coins stays the request-time SNAPSHOT (INV-001) — the
            // parent's decision lands in approved_cost_coins.
            $updated = $db->execute(
                "UPDATE reward_requests
                 SET status = 'approved', approved_cost_coins = ?, parent_comment = ?,
                     decided_by = ?, decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'pending'",
                [$cost, $comment, $parentId, $requestId]
            );
            if ($updated === 0) {
                return false; // already decided — idempotent
            }

            if ($cost > 0) {
                $ledger = $this->ledger ?? new LedgerService($db);
                $txId = $ledger->post(
                    childId: (int) $request['child_id'],
                    coinsDelta: -$cost,
                    xpDelta: 0,
                    type: 'reward_spend',
                    title: (string) $request['title'],
                    actorUserId: $parentId,
                    comment: $comment,
                    sourceType: 'reward_request',
                    sourceId: $requestId,
                    idempotencyKey: 'reward_request:' . $requestId,
                );
                $db->execute('UPDATE reward_requests SET transaction_id = ? WHERE id = ?', [$txId, $requestId]);
            }

            return true;
        });
    }

    /** @return bool whether this call actually decided the request */
    public function reject(int $requestId, int $parentId, ?string $comment = null): bool
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($requestId, $parentId, $comment): bool {
            return $db->execute(
                "UPDATE reward_requests
                 SET status = 'rejected', parent_comment = ?, decided_by = ?, decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'pending'",
                [$comment, $parentId, $requestId]
            ) > 0;
        });
    }

    /** Child withdraws their own pending request (releases the reservation). */
    public function cancel(int $requestId, int $childId): void
    {
        WriteGate::transaction($this->db, function (Db $db) use ($requestId, $childId): void {
            $db->execute(
                "UPDATE reward_requests
                 SET status = 'cancelled', decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND child_id = ? AND status = 'pending'",
                [$requestId, $childId]
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        return $this->db->fetchAll(
            "SELECT r.*, c.name AS child_name FROM reward_requests r
             JOIN children c ON c.id = r.child_id
             WHERE r.status = 'pending' ORDER BY r.created_at"
        );
    }

    /** @return list<array<string, mixed>> */
    public function forChild(int $childId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM reward_requests WHERE child_id = ? ORDER BY id DESC LIMIT ?',
            [$childId, $limit]
        );
    }

    private function createRequest(
        Db $db,
        int $childId,
        ?int $rewardId,
        string $title,
        int $cost,
        ?int $durationMinutes,
    ): int {
        // Child row lock = reservation mutex (same lock the ledger uses).
        $child = $db->fetchOne(
            'SELECT id, coin_balance FROM children WHERE id = ? AND archived_at IS NULL FOR UPDATE',
            [$childId]
        );
        if ($child === null) {
            throw new \InvalidArgumentException('Child not found or archived.');
        }

        if ($cost > 0) {
            $reserved = (int) $db->fetchOne(
                "SELECT COALESCE(SUM(cost_coins), 0) AS r FROM reward_requests
                 WHERE child_id = ? AND status = 'pending'",
                [$childId]
            )['r'];
            $available = (int) $child['coin_balance'] - $reserved;
            if ($available < $cost) {
                throw new InsufficientCoinsException(
                    'Not enough available coins: ' . $available . ' available, ' . $cost . ' needed.'
                );
            }
        }

        $db->execute(
            'INSERT INTO reward_requests
                (reward_id, child_id, title, cost_coins, duration_minutes, status, created_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', UTC_TIMESTAMP())',
            [$rewardId, $childId, $title, $cost, $durationMinutes]
        );

        return $db->lastInsertId();
    }
}
