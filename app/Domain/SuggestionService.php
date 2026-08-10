<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Child activity proposals ("Garten gegossen — 8 Coins?"). Parents approve
 * (optionally modified), or reject with a comment. Rows live forever
 * (INV-001); the original suggestion amount is never overwritten.
 */
final class SuggestionService
{
    public function __construct(
        private readonly Db $db,
        private readonly ?LedgerService $ledger = null,
    ) {
    }

    public function submit(int $childId, string $title, int $suggestedCoins, ?string $comment): int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Please describe what you did (max 190 characters).');
        }
        if ($suggestedCoins < 0 || $suggestedCoins > 100_000) {
            throw new \InvalidArgumentException('Suggested coins out of range.');
        }

        return WriteGate::transaction($this->db, function (Db $db) use ($childId, $title, $suggestedCoins, $comment): int {
            $child = $db->fetchOne('SELECT id FROM children WHERE id = ? AND archived_at IS NULL', [$childId]);
            if ($child === null) {
                throw new \InvalidArgumentException('Child not found or archived.');
            }
            $db->execute(
                'INSERT INTO suggestions (child_id, title, suggested_coins, comment, status, created_at)
                 VALUES (?, ?, ?, ?, \'pending\', UTC_TIMESTAMP())',
                [$childId, $title, $suggestedCoins, trim((string) $comment) ?: null]
            );

            return $db->lastInsertId();
        });
    }

    /** @return bool whether this call actually decided the suggestion */
    public function approve(int $id, int $parentId, int $coins, int $xp, ?string $comment = null): bool
    {
        if ($coins < 0 || $xp < 0) {
            throw new \InvalidArgumentException('Approved amounts cannot be negative.');
        }

        return WriteGate::transaction($this->db, function (Db $db) use ($id, $parentId, $coins, $xp, $comment): bool {
            $suggestion = $db->fetchOne('SELECT * FROM suggestions WHERE id = ? FOR UPDATE', [$id]);
            if ($suggestion === null) {
                throw new \InvalidArgumentException('Suggestion not found.');
            }

            $updated = $db->execute(
                "UPDATE suggestions
                 SET status = 'approved', approved_coins = ?, approved_xp = ?, parent_comment = ?,
                     decided_by = ?, decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'pending'",
                [$coins, $xp, $comment, $parentId, $id]
            );
            if ($updated === 0) {
                return false; // already decided — idempotent
            }

            $ledger = $this->ledger ?? new LedgerService($db);
            $txId = $ledger->post(
                childId: (int) $suggestion['child_id'],
                coinsDelta: $coins,
                xpDelta: $xp,
                type: 'suggestion',
                title: (string) $suggestion['title'],
                actorUserId: $parentId,
                comment: $comment,
                sourceType: 'suggestion',
                sourceId: $id,
                idempotencyKey: 'suggestion:' . $id,
            );
            $db->execute('UPDATE suggestions SET transaction_id = ? WHERE id = ?', [$txId, $id]);

            return true;
        });
    }

    /** @return bool whether this call actually decided the suggestion */
    public function reject(int $id, int $parentId, ?string $comment = null): bool
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($id, $parentId, $comment): bool {
            return $db->execute(
                "UPDATE suggestions
                 SET status = 'rejected', parent_comment = ?, decided_by = ?, decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'pending'",
                [$comment, $parentId, $id]
            ) > 0;
        });
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        return $this->db->fetchAll(
            "SELECT s.*, c.name AS child_name FROM suggestions s
             JOIN children c ON c.id = s.child_id
             WHERE s.status = 'pending' ORDER BY s.created_at"
        );
    }

    /** @return list<array<string, mixed>> the child's own suggestions, newest first */
    public function forChild(int $childId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM suggestions WHERE child_id = ? ORDER BY id DESC LIMIT ?',
            [$childId, $limit]
        );
    }
}
