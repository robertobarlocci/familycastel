<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Reusable point templates ("Geschirr abgeräumt +5"). Applying one posts
 * through the LedgerService with a snapshot of the CURRENT template title —
 * later edits/archival never change history (INV-001).
 */
final class TemplateService
{
    public function __construct(
        private readonly Db $db,
        private readonly ?LedgerService $ledger = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        [$title, $coins, $xp, $scope, $childIds, $favorite, $confirm] = $this->validated($data);

        return WriteGate::transaction($this->db, function (Db $db) use ($title, $coins, $xp, $scope, $childIds, $favorite, $confirm): int {
            $db->execute(
                'INSERT INTO point_templates
                    (title, coins_delta, xp_delta, scope, child_ids, is_favorite, requires_confirm, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$title, $coins, $xp, $scope, $childIds, $favorite, $confirm]
            );

            return $db->lastInsertId();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->assertExists($id);
        [$title, $coins, $xp, $scope, $childIds, $favorite, $confirm] = $this->validated($data);

        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE point_templates
             SET title = ?, coins_delta = ?, xp_delta = ?, scope = ?, child_ids = ?,
                 is_favorite = ?, requires_confirm = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?',
            [$title, $coins, $xp, $scope, $childIds, $favorite, $confirm, $id]
        ));
    }

    public function archive(int $id): void
    {
        $this->assertExists($id);
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE point_templates SET archived_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$id]
        ));
    }

    public function toggleFavorite(int $id): void
    {
        $this->assertExists($id);
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE point_templates SET is_favorite = 1 - is_favorite, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$id]
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM point_templates WHERE id = ?', [$id]);
    }

    /**
     * Active templates applicable to one child: global ones plus those whose
     * child_ids JSON list contains the child. Favorites first.
     *
     * @return list<array<string, mixed>>
     */
    public function forChild(int $childId): array
    {
        // MariaDB: the candidate is passed as a JSON literal string ("5" is a
        // valid JSON document) — CAST(? AS JSON) is MySQL-only syntax.
        return $this->db->fetchAll(
            "SELECT * FROM point_templates
             WHERE archived_at IS NULL
               AND (scope = 'all' OR JSON_CONTAINS(child_ids, ?))
             ORDER BY is_favorite DESC, sort, title",
            [(string) $childId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM point_templates WHERE archived_at IS NULL ORDER BY is_favorite DESC, sort, title'
        );
    }

    /**
     * One-tap award: post the template through the ledger. Returns tx id.
     * Template validation happens INSIDE the same transaction under a row
     * lock — a concurrent archive/edit cannot slip between check and post
     * (global lock order: gate → point_templates → children → transactions).
     */
    public function apply(int $templateId, int $childId, ?int $actorUserId, ?string $idempotencyKey = null): int
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($templateId, $childId, $actorUserId, $idempotencyKey): int {
            $template = $db->fetchOne(
                'SELECT * FROM point_templates WHERE id = ? FOR UPDATE',
                [$templateId]
            );
            if ($template === null || $template['archived_at'] !== null) {
                throw new \InvalidArgumentException('Template not available.');
            }
            if (!$this->appliesTo($template, $childId)) {
                throw new \InvalidArgumentException('Template is not available for this child.');
            }

            $ledger = $this->ledger ?? new LedgerService($db);
            $coins = (int) $template['coins_delta'];

            return $ledger->post(
                childId: $childId,
                coinsDelta: $coins,
                xpDelta: (int) $template['xp_delta'],
                type: $coins >= 0 ? 'award' : 'deduction',
                title: (string) $template['title'],
                actorUserId: $actorUserId,
                sourceType: 'point_template',
                sourceId: $templateId,
                idempotencyKey: $idempotencyKey,
            );
        });
    }

    /** @param array<string, mixed> $template */
    private function appliesTo(array $template, int $childId): bool
    {
        if ($template['scope'] === 'all') {
            return true;
        }
        $ids = json_decode((string) $template['child_ids'], true);

        return is_array($ids) && in_array($childId, array_map(intval(...), $ids), true);
    }

    /** @return array{0:string,1:int,2:int,3:string,4:?string,5:int,6:int} */
    private function validated(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Template title is required (max 190 characters).');
        }

        $coins = (int) ($data['coins_delta'] ?? 0);
        $xp = (int) ($data['xp_delta'] ?? 0);
        if ($xp < 0) {
            throw new \InvalidArgumentException('XP can never be negative.');
        }
        if ($coins === 0 && $xp === 0) {
            throw new \InvalidArgumentException('A template must change Coins or XP.');
        }
        // Match the ledger's sanity cap — a template that can never be applied
        // must not be saveable in the first place.
        if (abs($coins) > 100_000 || $xp > 100_000) {
            throw new \InvalidArgumentException('Amount exceeds the sanity limit.');
        }

        $scope = (string) ($data['scope'] ?? 'all');
        if (!in_array($scope, ['all', 'selected'], true)) {
            throw new \InvalidArgumentException('Unknown scope.');
        }

        $childIds = null;
        if ($scope === 'selected') {
            $ids = array_values(array_unique(array_map(intval(...), (array) ($data['child_ids'] ?? []))));
            $ids = array_values(array_filter($ids, static fn (int $id) => $id > 0));
            if ($ids === []) {
                throw new \InvalidArgumentException('Select at least one child for a scoped template.');
            }
            $childIds = json_encode($ids, JSON_THROW_ON_ERROR);
        }

        return [
            $title,
            $coins,
            $xp,
            $scope,
            $childIds,
            !empty($data['is_favorite']) ? 1 : 0,
            !empty($data['requires_confirm']) ? 1 : 0,
        ];
    }

    private function assertExists(int $id): void
    {
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException('Template not found.');
        }
    }
}
