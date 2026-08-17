<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * What the child should SEE when they next open their castle.
 *
 * The unit is the ledger transaction, not the entry point that created it.
 * Every way a child's Coins or XP can move — a Sidequest approval, a quick
 * action, "Eigene Aktion", a Minuspunkt, an adjustment, a reversal — funnels
 * through LedgerService::post() (INV-002), so hanging the feedback off the
 * transaction is what makes ALL of them produce feedback, including the ones
 * nobody has thought of yet. Doing it per controller instead is how you ship a
 * feature that celebrates two entry points and silently ignores the third.
 *
 * This sits NEXT TO the achievement celebration (AchievementService::takeUnseen
 * -> [data-celebrate]), it does not replace it: achievements are milestones,
 * transactions are events, and both deserve a reaction.
 */
final class CelebrationService
{
    /**
     * Rows read (and marked) per statement. Bounds the `IN (...)` placeholder
     * list: without it, a child returning to an install whose backfill was
     * skipped would build one placeholder per historical row and 500 the page —
     * a failure that only shows up on the installs with the most history.
     */
    public const CHUNK = 200;

    /** Rows one page load may consume. A larger backlog drains on the next visit. */
    public const MAX_CHUNKS = 25;

    /**
     * Types where a negative coins_delta is the child's OWN choice — spending
     * saved Coins on a reward they picked. Raining on that would tell a child
     * that finally getting what they saved for is a punishment.
     */
    private const VOLUNTARY_SPENDS = ['reward_spend', 'milestone_spend'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Atomically read-and-consume the child's uncelebrated ledger events.
     *
     * Same idea as AchievementService::takeUnseen() — read and mark inside ONE
     * gated transaction, so two concurrent page loads can never both celebrate
     * the same event — but with one deliberate difference, which is a real bug
     * in that older implementation (issue #22):
     *
     * The UPDATE is bounded by the ids actually READ. `FOR UPDATE` locks the
     * rows the SELECT found; it does not stop a row being INSERTED between the
     * two statements, and a broad `WHERE celebrated_at IS NULL` would then mark
     * that new row seen without ever returning it. The child loses that
     * celebration silently and undetectably. The window is ordinary behaviour
     * here: a parent tapping "Eintragen" while the child's home page loads.
     *
     * @param  null|callable(list<array<string, mixed>>): void $afterRead test seam:
     *         invoked with each chunk right after it is read and before it is marked
     * @return list<array<string, mixed>> the consumed events, oldest first
     */
    public function takeUncelebrated(int $childId, ?callable $afterRead = null): array
    {
        return WriteGate::transaction($this->db, function (Db $db) use ($childId, $afterRead): array {
            $events = [];

            for ($pass = 0; $pass < self::MAX_CHUNKS; $pass++) {
                $rows = $db->fetchAll(
                    'SELECT id, coins_delta, xp_delta, type FROM transactions
                      WHERE child_id = ? AND celebrated_at IS NULL
                      ORDER BY id LIMIT ' . self::CHUNK . ' FOR UPDATE',
                    [$childId]
                );
                if ($rows === []) {
                    break;
                }

                if ($afterRead !== null) {
                    $afterRead($rows);
                }

                self::markRows($db, 'celebrated_at', $rows);

                foreach ($rows as $row) {
                    $events[] = $row;
                }

                if (count($rows) < self::CHUNK) {
                    break;
                }
            }

            return $events;
        });
    }

    /**
     * What feedback does this batch call for? Pure, so the whole decision table
     * is testable without a database.
     *
     * Both flags can be true at once — a parent awarded AND deducted between
     * two visits — and that is rendered as both, sequenced (rain first, then
     * confetti), because both facts are true.
     *
     * "Negative" is decided on Coins alone, with no xp_delta < 0 branch, because
     * a negative-XP event CANNOT EXIST: transactions.xp_delta is INT UNSIGNED
     * and LedgerService::postInternal() throws on a negative xpDelta, both
     * enforcing INV-002 "XP never decreases". A branch for it would be
     * unreachable code asserting the opposite of a HARD invariant. Pinned by
     * TransactionFeedbackTest::testXpCanNeverBeNegative(), so relaxing INV-002
     * later breaks a test instead of silently mis-deciding the mood.
     *
     * @param  list<array<string, mixed>> $events
     * @return array{positive: bool, negative: bool}
     */
    public static function feedbackFor(array $events): array
    {
        $positive = false;
        $negative = false;

        foreach ($events as $event) {
            $coins = (int) ($event['coins_delta'] ?? 0);
            $xp = (int) ($event['xp_delta'] ?? 0);
            $type = (string) ($event['type'] ?? '');

            if ($coins > 0 || $xp > 0) {
                $positive = true;
            }

            if ($coins < 0 && !in_array($type, self::VOLUNTARY_SPENDS, true)) {
                $negative = true;
            }
        }

        return ['positive' => $positive, 'negative' => $negative];
    }

    /**
     * Mark exactly the rows that were read. Shared with JournalService so both
     * seen-state columns get the same bounded treatment.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function markRows(Db $db, string $column, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Column name is a compile-time constant from our own callers, never
        // request input — but keep the allowlist so it stays that way.
        if (!in_array($column, ['celebrated_at', 'journal_seen_at'], true)) {
            throw new \InvalidArgumentException('Unknown seen-state column: ' . $column);
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        $db->execute(
            "UPDATE transactions SET {$column} = UTC_TIMESTAMP()
              WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                AND {$column} IS NULL",
            $ids
        );
    }
}
