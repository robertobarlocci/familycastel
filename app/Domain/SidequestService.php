<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Sidequests (never "Tasks"/"Chores" — INV-004). Claiming is race-free via
 * sidequest_claim_slots UNIQUE(sidequest_id, recurrence_bucket, scope_key)
 * under FOR UPDATE on the quest row (plan §4.9b/§6). Approval is idempotent:
 * a status-guard UPDATE wins exactly once, and the ledger post carries an
 * idempotency key as the second net. Claims are history — never deleted
 * (INV-001); slots are operational and are freed on release.
 */
final class SidequestService
{
    public function __construct(
        private readonly Db $db,
        private readonly ?LedgerService $ledger = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $createdBy): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Sidequest title is required (max 190 characters).');
        }
        $coins = (int) ($data['coins_reward'] ?? 0);
        $xp = (int) ($data['xp_reward'] ?? 0);
        if ($coins < 0 || $xp < 0 || ($coins === 0 && $xp === 0) || $coins > 100_000 || $xp > 100_000) {
            throw new \InvalidArgumentException('Sidequest rewards must be positive and within limits.');
        }
        $type = (string) ($data['type'] ?? 'once');
        if (!in_array($type, ['once', 'daily', 'weekly', 'repeating'], true)) {
            throw new \InvalidArgumentException('Unknown Sidequest type.');
        }
        $ownership = (string) ($data['ownership'] ?? 'first_come');
        if (!in_array($ownership, ['first_come', 'assigned', 'per_child'], true)) {
            throw new \InvalidArgumentException('Unknown ownership mode.');
        }

        $assigned = null;
        if ($ownership === 'assigned') {
            $ids = array_values(array_filter(array_map(intval(...), (array) ($data['assigned_child_ids'] ?? [])), fn ($i) => $i > 0));
            if ($ids === []) {
                throw new \InvalidArgumentException('Assign at least one child.');
            }
            $assigned = json_encode($ids, JSON_THROW_ON_ERROR);
        }

        $expiresAt = $this->parseDate($data['expires_at'] ?? null);
        $availableFrom = $this->parseDate($data['available_from'] ?? null);

        return WriteGate::transaction($this->db, function (Db $db) use (
            $title, $data, $coins, $xp, $type, $ownership, $assigned, $expiresAt, $availableFrom, $createdBy
        ): int {
            $db->execute(
                'INSERT INTO sidequests
                    (title, description, coins_reward, xp_reward, type, ownership, assigned_child_ids,
                     available_from, expires_at, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [
                    $title, trim((string) ($data['description'] ?? '')) ?: null, $coins, $xp,
                    $type, $ownership, $assigned, $availableFrom, $expiresAt, $createdBy,
                ]
            );

            return $db->lastInsertId();
        });
    }

    public function archiveQuest(int $questId): void
    {
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            "UPDATE sidequests SET status = 'archived', archived_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$questId]
        ));
    }

    /**
     * Quests this child can accept right now: active, in their time window,
     * matching ownership, and with a free slot for the current bucket.
     *
     * @return list<array<string, mixed>>
     */
    public function availableFor(int $childId): array
    {
        $quests = $this->db->fetchAll(
            "SELECT q.* FROM sidequests q
             WHERE q.status = 'active'
               AND (q.available_from IS NULL OR q.available_from <= UTC_TIMESTAMP())
               AND (q.expires_at IS NULL OR q.expires_at > UTC_TIMESTAMP())
               AND (q.ownership <> 'assigned' OR JSON_CONTAINS(q.assigned_child_ids, ?))
             ORDER BY q.expires_at IS NULL, q.expires_at, q.id DESC",
            [(string) $childId]
        );

        return array_values(array_filter(
            $quests,
            fn (array $quest) => $this->slotFree($quest, $childId)
        ));
    }

    /** @return list<array<string, mixed>> the child's own claims, newest first */
    public function claimsFor(int $childId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, q.type AS quest_type FROM sidequest_claims c
             JOIN sidequests q ON q.id = c.sidequest_id
             WHERE c.child_id = ? ORDER BY c.id DESC LIMIT ?',
            [$childId, $limit]
        );
    }

    /** @return list<array<string, mixed>> pending approvals for parents */
    public function pendingApprovals(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, ch.name AS child_name FROM sidequest_claims c
             JOIN children ch ON ch.id = c.child_id
             WHERE c.status = 'completed_pending' ORDER BY c.completed_at"
        );
    }

    /**
     * Child accepts a quest. Returns the claim id.
     * Race-free: quest row FOR UPDATE + unique slot insert.
     */
    public function accept(int $questId, int $childId): int
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($questId, $childId): int {
            $quest = $db->fetchOne('SELECT * FROM sidequests WHERE id = ? FOR UPDATE', [$questId]);
            if ($quest === null || $quest['status'] !== 'active') {
                throw new QuestUnavailableException('Quest not available.');
            }
            if ($quest['expires_at'] !== null && $quest['expires_at'] <= gmdate('Y-m-d H:i:s')) {
                throw new QuestUnavailableException('Quest expired.');
            }
            if ($quest['available_from'] !== null && $quest['available_from'] > gmdate('Y-m-d H:i:s')) {
                throw new QuestUnavailableException('Quest not yet available.');
            }
            if (!$this->ownershipAllows($quest, $childId)) {
                throw new QuestUnavailableException('Quest is not for this child.');
            }

            $child = $db->fetchOne(
                'SELECT id FROM children WHERE id = ? AND archived_at IS NULL', [$childId]
            );
            if ($child === null) {
                throw new \InvalidArgumentException('Child not found or archived.');
            }

            $bucket = $this->bucketFor((string) $quest['type']);
            $scopeKey = $quest['ownership'] === 'first_come' ? 'g' : (string) $childId;

            $db->execute(
                'INSERT INTO sidequest_claims
                    (sidequest_id, child_id, recurrence_bucket, status, title, coins_reward, xp_reward, accepted_at, created_at)
                 VALUES (?, ?, ?, \'accepted\', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$questId, $childId, $bucket, $quest['title'], $quest['coins_reward'], $quest['xp_reward']]
            );
            $claimId = $db->lastInsertId();

            try {
                $db->execute(
                    'INSERT INTO sidequest_claim_slots (sidequest_id, recurrence_bucket, scope_key, claim_id)
                     VALUES (?, ?, ?, ?)',
                    [$questId, $bucket, $scopeKey, $claimId]
                );
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                    // Slot taken — someone was faster. The whole transaction
                    // rolls back, so the claim row above vanishes with it.
                    throw new QuestUnavailableException('Someone was faster!');
                }
                throw $e;
            }

            return $claimId;
        });
    }

    /** Child marks their accepted claim as done → parent approval queue. */
    public function markCompleted(int $claimId, int $childId): void
    {
        WriteGate::transaction($this->db, function (Db $db) use ($claimId, $childId): void {
            $updated = $db->execute(
                "UPDATE sidequest_claims SET status = 'completed_pending', completed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND child_id = ? AND status = 'accepted'",
                [$claimId, $childId]
            );
            if ($updated === 0) {
                throw new \InvalidArgumentException('Claim not found, not yours, or not in progress.');
            }
        });
    }

    /** Child gives an accepted quest back — frees the slot, keeps the claim row. */
    public function cancel(int $claimId, int $childId): void
    {
        WriteGate::transaction($this->db, function (Db $db) use ($claimId, $childId): void {
            $updated = $db->execute(
                "UPDATE sidequest_claims SET status = 'cancelled', decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND child_id = ? AND status IN ('accepted', 'completed_pending')",
                [$claimId, $childId]
            );
            if ($updated === 0) {
                throw new \InvalidArgumentException('Claim not found, not yours, or already decided.');
            }
            $db->execute('DELETE FROM sidequest_claim_slots WHERE claim_id = ?', [$claimId]);
        });
    }

    /**
     * Parent approves a completed claim — grants Coins/XP exactly once.
     * Optional overrides let the parent modify the reward; snapshots of the
     * original stay on the claim (INV-001).
     */
    /** @return bool whether this call actually decided the claim */
    public function approve(
        int $claimId,
        int $parentId,
        ?int $coinsOverride = null,
        ?int $xpOverride = null,
        ?string $comment = null,
    ): bool {
        return WriteGate::transaction($this->db, function (Db $db) use ($claimId, $parentId, $coinsOverride, $xpOverride, $comment): bool {
            $claim = $db->fetchOne('SELECT * FROM sidequest_claims WHERE id = ? FOR UPDATE', [$claimId]);
            if ($claim === null) {
                throw new \InvalidArgumentException('Claim not found.');
            }

            $coins = $coinsOverride ?? (int) $claim['coins_reward'];
            $xp = $xpOverride ?? (int) $claim['xp_reward'];
            if ($coins < 0 || $xp < 0) {
                throw new \InvalidArgumentException('Approved rewards cannot be negative.');
            }

            // Idempotency net #1: the status guard — 0 affected rows means
            // someone already decided; NO ledger post happens.
            $updated = $db->execute(
                "UPDATE sidequest_claims
                 SET status = 'approved', approved_coins = ?, approved_xp = ?, parent_comment = ?,
                     decided_at = UTC_TIMESTAMP(), decided_by = ?
                 WHERE id = ? AND status = 'completed_pending'",
                [$coins, $xp, $comment, $parentId, $claimId]
            );
            if ($updated === 0) {
                return false;
            }

            // Idempotency net #2: unique ledger key.
            $ledger = $this->ledger ?? new LedgerService($db);
            $txId = $ledger->post(
                childId: (int) $claim['child_id'],
                coinsDelta: $coins,
                xpDelta: $xp,
                type: 'sidequest',
                title: (string) $claim['title'],
                actorUserId: $parentId,
                comment: $comment,
                sourceType: 'sidequest_claim',
                sourceId: $claimId,
                idempotencyKey: 'sidequest_claim:' . $claimId,
            );
            $db->execute('UPDATE sidequest_claims SET transaction_id = ? WHERE id = ?', [$txId, $claimId]);

            // Slot semantics per type (Codex T8-T12 review): only 'repeating'
            // quests reopen after approval. 'once' stays done forever and
            // daily/weekly stay consumed for their bucket — freeing those
            // would let the same quest be claimed again immediately.
            $quest = $db->fetchOne('SELECT type FROM sidequests WHERE id = ?', [$claim['sidequest_id']]);
            if ($quest !== null && $quest['type'] === 'repeating') {
                $db->execute('DELETE FROM sidequest_claim_slots WHERE claim_id = ?', [$claimId]);
            }

            return true;
        });
    }

    /**
     * Parent rejects a claim — frees the slot in every mode (the child did
     * not do it, so the quest becomes available again), no coins.
     *
     * @return bool whether this call actually decided the claim
     */
    public function reject(int $claimId, int $parentId, ?string $comment = null): bool
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($claimId, $parentId, $comment): bool {
            $updated = $db->execute(
                "UPDATE sidequest_claims
                 SET status = 'rejected', parent_comment = ?, decided_at = UTC_TIMESTAMP(), decided_by = ?
                 WHERE id = ? AND status IN ('completed_pending', 'accepted')",
                [$comment, $parentId, $claimId]
            );
            if ($updated === 0) {
                return false; // already decided — idempotent no-op
            }
            $db->execute('DELETE FROM sidequest_claim_slots WHERE claim_id = ?', [$claimId]);

            return true;
        });
    }

    /** @return list<array<string, mixed>> */
    public function listActiveQuests(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM sidequests WHERE status = 'active' ORDER BY id DESC"
        );
    }

    /** @param array<string, mixed> $quest */
    private function ownershipAllows(array $quest, int $childId): bool
    {
        if ($quest['ownership'] !== 'assigned') {
            return true;
        }
        $ids = json_decode((string) $quest['assigned_child_ids'], true);

        return is_array($ids) && in_array($childId, array_map(intval(...), $ids), true);
    }

    /** @param array<string, mixed> $quest */
    private function slotFree(array $quest, int $childId): bool
    {
        $bucket = $this->bucketFor((string) $quest['type']);
        $scopeKey = $quest['ownership'] === 'first_come' ? 'g' : (string) $childId;

        $slot = $this->db->fetchOne(
            'SELECT id FROM sidequest_claim_slots WHERE sidequest_id = ? AND recurrence_bucket = ? AND scope_key = ?',
            [$quest['id'], $bucket, $scopeKey]
        );

        return $slot === null;
    }

    /** ''=one-time · date=daily · ISO week=weekly · claim-serial=repeating */
    private function bucketFor(string $type): string
    {
        return match ($type) {
            'daily' => gmdate('Y-m-d'),
            'weekly' => gmdate('o-\WW'),
            'repeating' => '', // freed slot = immediately claimable again
            default => '',
        };
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException('Invalid date value.');
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }
}
