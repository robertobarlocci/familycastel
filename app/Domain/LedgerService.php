<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * THE only path for Coin/XP mutations (INV-002). Every post is one DB
 * transaction under the global lock order (plan §6):
 *   ops_state gate (LOCK IN SHARE MODE, first statement)
 *   → … → children (FOR UPDATE) → transactions INSERT.
 * The ledger is append-only; balances are caches updated in the same
 * transaction; XP is monotonic; reservations (pending reward_requests)
 * reduce the available balance.
 */
final class LedgerService
{
    private const TYPES = [
        'award', 'deduction', 'sidequest', 'suggestion',
        'reward_spend', 'milestone_spend', 'adjustment', 'reversal',
    ];
    /** Sanity ceiling per transaction — protects locks from forged inputs. */
    private const MAX_MAGNITUDE = 100_000;

    private ?LevelService $resolvedLevels;

    public function __construct(
        private readonly Db $db,
        ?LevelService $levels = null,
    ) {
        $this->resolvedLevels = $levels;
    }

    /**
     * Post a ledger entry atomically. Returns the transaction id.
     *
     * @throws WriteLockedException      while update/restore holds the gate
     * @throws InsufficientCoinsException when coins would drop below the floor
     * @throws DuplicatePostException    when the idempotency key already posted
     */
    public function post(
        int $childId,
        int $coinsDelta,
        int $xpDelta,
        string $type,
        string $title,
        ?int $actorUserId = null,
        ?string $description = null,
        ?string $comment = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $idempotencyKey = null,
        bool $allowNegative = false,
    ): int {
        // Reversals are ONLY creatable via reverse() — its guards (coins-only
        // exact inverse, no reversal-of-reversal, same child) must be
        // impossible to bypass through the public API.
        if ($type === 'reversal') {
            throw new \InvalidArgumentException('Reversals must go through reverse().');
        }

        return $this->postInternal(
            $childId, $coinsDelta, $xpDelta, $type, $title, $actorUserId,
            $description, $comment, $sourceType, $sourceId, $idempotencyKey,
            null, $allowNegative
        );
    }

    private function postInternal(
        int $childId,
        int $coinsDelta,
        int $xpDelta,
        string $type,
        string $title,
        ?int $actorUserId,
        ?string $description,
        ?string $comment,
        ?string $sourceType,
        ?int $sourceId,
        ?string $idempotencyKey,
        ?int $reversalOf,
        bool $allowNegative,
    ): int {
        if ($xpDelta < 0) {
            throw new \InvalidArgumentException('XP never decreases (INV-002).');
        }
        if (abs($coinsDelta) > self::MAX_MAGNITUDE || $xpDelta > self::MAX_MAGNITUDE) {
            throw new \InvalidArgumentException('Amount exceeds the sanity limit.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown transaction type: ' . $type);
        }
        if (trim($title) === '') {
            throw new \InvalidArgumentException('Transaction title is required.');
        }

        return $this->db->transaction(function (Db $db) use (
            $childId, $coinsDelta, $xpDelta, $type, $title, $actorUserId,
            $description, $comment, $sourceType, $sourceId, $idempotencyKey,
            $reversalOf, $allowNegative
        ): int {
            // 1. Hard write gate — fail-closed, lock held to COMMIT.
            WriteGate::assertOpen($db);

            // 2. Per-child mutex + current balances. Archived children are
            // read-only (INV-001: their history stays; nothing new happens).
            $child = $db->fetchOne(
                'SELECT id, coin_balance, xp_total FROM children WHERE id = ? AND archived_at IS NULL FOR UPDATE',
                [$childId]
            );
            if ($child === null) {
                throw new \InvalidArgumentException('Child not found or archived.');
            }

            // 3. Floor check on the AVAILABLE balance (reservations bind).
            if ($coinsDelta < 0 && !$allowNegative) {
                $reserved = $this->pendingReservations($db, $childId);
                $available = (int) $child['coin_balance'] - $reserved;
                if ($available + $coinsDelta < 0) {
                    throw new InsufficientCoinsException(
                        'Insufficient coins: available ' . $available . ', requested ' . $coinsDelta
                    );
                }
            }

            // 4. Append the ledger row (idempotency enforced by UNIQUE keys).
            try {
                $db->execute(
                    'INSERT INTO transactions
                        (child_id, coins_delta, xp_delta, type, title, description, comment,
                         actor_user_id, source_type, source_id, idempotency_key, reversal_of, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [
                        $childId, $coinsDelta, $xpDelta, $type, mb_substr($title, 0, 190),
                        $description, $comment, $actorUserId, $sourceType, $sourceId,
                        $idempotencyKey, $reversalOf,
                    ]
                );
            } catch (\PDOException $e) {
                // Robust duplicate detection: SQLSTATE 23000 + driver errno
                // 1062 (duplicate entry) FIRST, constraint name second.
                $isDuplicate = ($e->errorInfo[0] ?? '') === '23000'
                    && (int) ($e->errorInfo[1] ?? 0) === 1062
                    && (str_contains($e->getMessage(), 'uq_transactions_idempotency')
                        || str_contains($e->getMessage(), 'uq_transactions_reversal'));
                if ($isDuplicate) {
                    throw new DuplicatePostException('This action was already recorded.', previous: $e);
                }
                throw $e;
            }
            $txId = $db->lastInsertId();

            // 5. Update caches in the SAME transaction (level from new XP).
            $newXp = (int) $child['xp_total'] + $xpDelta;
            $levels = $this->levels();
            $db->execute(
                'UPDATE children
                 SET coin_balance = coin_balance + ?, xp_total = ?, level = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?',
                [$coinsDelta, $newXp, $levels->levelForXp($newXp), $childId]
            );

            return $txId;
        });
    }

    /**
     * Compensate a transaction's COINS exactly once (XP is permanent).
     * Positive awards reverse to deductions and vice versa.
     */
    public function reverse(int $transactionId, ?int $actorUserId): int
    {
        // Re-read inside the same transaction as the post — the guards and the
        // compensation must see one consistent state.
        return $this->db->transaction(function (Db $db) use ($transactionId, $actorUserId): int {
            WriteGate::assertOpen($db);

            $original = $db->fetchOne('SELECT * FROM transactions WHERE id = ?', [$transactionId]);
            if ($original === null) {
                throw new \InvalidArgumentException('Transaction not found.');
            }
            if ($original['type'] === 'reversal') {
                throw new \InvalidArgumentException('A reversal cannot be reversed.');
            }

            return $this->postInternal(
                childId: (int) $original['child_id'],
                coinsDelta: -(int) $original['coins_delta'],
                xpDelta: 0,
                type: 'reversal',
                title: 'Rückgängig: ' . $original['title'],
                actorUserId: $actorUserId,
                description: null,
                comment: null,
                sourceType: 'transaction',
                sourceId: $transactionId,
                idempotencyKey: null,
                reversalOf: $transactionId,
                allowNegative: true, // undoing an award may legitimately go below reservations
            );
        });
    }

    private function levels(): LevelService
    {
        // Settings-driven curve by default — hardcoding it here would silently
        // ignore the family's configured thresholds.
        return $this->resolvedLevels ??= LevelService::fromSettings(new SettingsService($this->db));
    }

    public function balance(int $childId): int
    {
        $row = $this->db->fetchOne('SELECT coin_balance FROM children WHERE id = ?', [$childId]);

        return (int) ($row['coin_balance'] ?? 0);
    }

    public function availableBalance(int $childId): int
    {
        return $this->balance($childId) - $this->pendingReservations($this->db, $childId);
    }

    /** @return list<array<string, mixed>> newest first */
    public function history(int $childId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM transactions WHERE child_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$childId, $limit, $offset]
        );
    }

    private function pendingReservations(Db $db, int $childId): int
    {
        $row = $db->fetchOne(
            "SELECT COALESCE(SUM(cost_coins), 0) AS reserved
             FROM reward_requests WHERE child_id = ? AND status = 'pending'",
            [$childId]
        );

        return (int) $row['reserved'];
    }
}
