<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * The permanent, game-style Journal (INV-001). Entries are derived from the
 * ledger plus the request/claim tables (pending & rejected items have no
 * ledger row yet — they still belong in the story).
 */
final class JournalService
{
    public const FILTERS = ['all', 'earned', 'spent', 'deducted', 'pending', 'rejected', 'sidequests', 'rewards'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every entry carries the same keys, whatever it was derived from —
     * `transaction_id` and `has_photo` are null/false on the non-ledger kinds
     * rather than absent, so the view never needs an isset() guard.
     *
     * @return list<array{kind: string, title: string, coins: ?int, xp: ?int,
     *               status: string, comment: ?string, at: string,
     *               transaction_id: ?int, has_photo: bool}>
     */
    public function entries(int $childId, string $filter = 'all', int $limit = 100): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';

        $entries = [];

        // Ledger rows (posted history).
        if (!in_array($filter, ['pending', 'rejected'], true)) {
            $where = match ($filter) {
                'earned' => 'AND t.coins_delta > 0',
                'spent' => "AND t.type IN ('reward_spend', 'milestone_spend')",
                'deducted' => "AND t.type IN ('deduction', 'reversal') AND t.coins_delta < 0",
                'sidequests' => "AND t.type = 'sidequest'",
                'rewards' => "AND t.type = 'reward_spend'",
                default => '',
            };
            // The LEFT JOIN is what lets a penalty carry its photo evidence into
            // the child's journal — one extra column, no extra query per row.
            foreach ($this->db->fetchAll(
                "SELECT t.*, p.id AS photo_id
                 FROM transactions t
                 LEFT JOIN transaction_photos p ON p.transaction_id = t.id
                 WHERE t.child_id = ? {$where} ORDER BY t.id DESC LIMIT ?",
                [$childId, $limit]
            ) as $tx) {
                $entries[] = [
                    'kind' => (string) $tx['type'],
                    'title' => (string) $tx['title'],
                    'coins' => (int) $tx['coins_delta'],
                    'xp' => (int) $tx['xp_delta'],
                    'status' => 'posted',
                    'comment' => $tx['comment'],
                    'at' => (string) $tx['created_at'],
                    'transaction_id' => (int) $tx['id'],
                    'has_photo' => $tx['photo_id'] !== null,
                ];
            }
        }

        // Pending/rejected items (no ledger row).
        if (in_array($filter, ['all', 'pending', 'rejected', 'sidequests', 'rewards'], true)) {
            $states = match ($filter) {
                'pending' => ['completed_pending', 'pending'],
                'rejected' => ['rejected'],
                default => ['completed_pending', 'pending', 'rejected'],
            };
            $in = static fn (array $s) => implode(',', array_fill(0, count($s), '?'));

            if ($filter !== 'rewards') {
                $claimStates = array_values(array_intersect($states, ['completed_pending', 'rejected']));
                if ($claimStates !== []) {
                    foreach ($this->db->fetchAll(
                        'SELECT * FROM sidequest_claims WHERE child_id = ? AND status IN (' . $in($claimStates) . ') ORDER BY id DESC LIMIT ?',
                        [$childId, ...$claimStates, $limit]
                    ) as $claim) {
                        $entries[] = [
                            'kind' => 'sidequest',
                            'title' => (string) $claim['title'],
                            'coins' => (int) $claim['coins_reward'],
                            'xp' => (int) $claim['xp_reward'],
                            'status' => $claim['status'] === 'rejected' ? 'rejected' : 'pending',
                            'comment' => $claim['parent_comment'],
                            'at' => (string) ($claim['completed_at'] ?? $claim['created_at']),
                            // Uniform shape: only ledger rows can carry photo evidence, but every
                            // entry declares both keys so the view needs no isset() guard.
                            'transaction_id' => null,
                            'has_photo' => false,
                        ];
                    }
                }
            }

            if ($filter !== 'sidequests') {
                $reqStates = array_values(array_intersect($states, ['pending', 'rejected']));
                if ($reqStates !== []) {
                    foreach ($this->db->fetchAll(
                        'SELECT * FROM reward_requests WHERE child_id = ? AND status IN (' . $in($reqStates) . ') ORDER BY id DESC LIMIT ?',
                        [$childId, ...$reqStates, $limit]
                    ) as $request) {
                        $entries[] = [
                            'kind' => 'reward_request',
                            'title' => (string) $request['title'],
                            'coins' => -(int) $request['cost_coins'],
                            'xp' => null,
                            'status' => (string) $request['status'],
                            'comment' => $request['parent_comment'],
                            'at' => (string) $request['created_at'],
                            // Uniform shape: only ledger rows can carry photo evidence, but every
                            // entry declares both keys so the view needs no isset() guard.
                            'transaction_id' => null,
                            'has_photo' => false,
                        ];
                    }
                }

                // Suggestions and milestone wishes belong to the general
                // story — but not to the 'rewards' filter.
                if ($reqStates !== [] && $filter !== 'rewards') {
                    foreach ($this->db->fetchAll(
                        'SELECT * FROM suggestions WHERE child_id = ? AND status IN (' . $in($reqStates) . ') ORDER BY id DESC LIMIT ?',
                        [$childId, ...$reqStates, $limit]
                    ) as $suggestion) {
                        $entries[] = [
                            'kind' => 'suggestion',
                            'title' => (string) $suggestion['title'],
                            'coins' => (int) $suggestion['suggested_coins'],
                            'xp' => null,
                            'status' => (string) $suggestion['status'],
                            'comment' => $suggestion['parent_comment'],
                            'at' => (string) $suggestion['created_at'],
                            // Uniform shape: only ledger rows can carry photo evidence, but every
                            // entry declares both keys so the view needs no isset() guard.
                            'transaction_id' => null,
                            'has_photo' => false,
                        ];
                    }

                    foreach ($this->db->fetchAll(
                        'SELECT * FROM milestone_requests WHERE child_id = ? AND status IN (' . $in($reqStates) . ') ORDER BY id DESC LIMIT ?',
                        [$childId, ...$reqStates, $limit]
                    ) as $wish) {
                        $entries[] = [
                            'kind' => 'milestone_wish',
                            'title' => (string) $wish['title'],
                            'coins' => (int) $wish['suggested_coins'],
                            'xp' => null,
                            'status' => (string) $wish['status'],
                            'comment' => $wish['parent_comment'],
                            'at' => (string) $wish['created_at'],
                            // Uniform shape: only ledger rows can carry photo evidence, but every
                            // entry declares both keys so the view needs no isset() guard.
                            'transaction_id' => null,
                            'has_photo' => false,
                        ];
                    }
                }
            }
        }

        usort($entries, static fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return array_slice($entries, 0, $limit);
    }
}
