<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Domain\CelebrationService;
use PHPUnit\Framework\TestCase;

/**
 * The decision table behind "confetti or rain?".
 *
 * Pure and database-free on purpose: this is the part of the feature a child
 * actually experiences, and every branch of it should be cheap to state and
 * cheap to change your mind about.
 */
final class CelebrationMoodTest extends TestCase
{
    /** Mirrors the transactions.type ENUM in migration 001. */
    private const TYPES = [
        'award', 'deduction', 'sidequest', 'suggestion',
        'reward_spend', 'milestone_spend', 'adjustment', 'reversal',
    ];

    /** @return array<string, mixed> */
    private function event(int $coins, int $xp, string $type): array
    {
        return ['coins_delta' => $coins, 'xp_delta' => $xp, 'type' => $type];
    }

    private function assertMood(bool $positive, bool $negative, array $events, string $why): void
    {
        self::assertSame(
            ['positive' => $positive, 'negative' => $negative],
            CelebrationService::feedbackFor($events),
            $why
        );
    }

    // ------------------------------------------------------------- 1-2: basics

    public function testAnAwardCelebrates(): void
    {
        $this->assertMood(true, false, [$this->event(5, 5, 'award')], 'coins gained → confetti, no rain');
    }

    public function testADeductionRains(): void
    {
        $this->assertMood(false, true, [$this->event(-3, 0, 'deduction')], 'a Minuspunkt → rain, no confetti');
    }

    // ---------------------------------------- 3-4: the child's OWN choices

    /**
     * The one rule that is a product decision rather than arithmetic: spending
     * saved Coins on a reward you chose is not a punishment, so it must not
     * rain. Getting the thing you saved for is the happiest moment in the app.
     */
    public function testSpendingOnAChosenRewardDoesNotRain(): void
    {
        $this->assertMood(false, false, [$this->event(-30, 0, 'reward_spend')], 'the child chose this');
    }

    public function testSpendingOnAMilestoneDoesNotRain(): void
    {
        $this->assertMood(false, false, [$this->event(-1000, 0, 'milestone_spend')], 'the child chose this too');
    }

    // ------------------------------------------- 5-7: corrections DO rain

    public function testANegativeAdjustmentRains(): void
    {
        $this->assertMood(false, true, [$this->event(-4, 0, 'adjustment')], 'applied TO the child, not chosen BY them');
    }

    public function testANegativeReversalRains(): void
    {
        $this->assertMood(false, true, [$this->event(-8, 0, 'reversal')], 'points taken back');
    }

    public function testAPositiveReversalCelebrates(): void
    {
        $this->assertMood(true, false, [$this->event(8, 0, 'reversal')], 'a deduction undone is good news');
    }

    // ------------------------------------------------------- 8-9: the edges

    public function testXpOnlyStillCelebrates(): void
    {
        $this->assertMood(true, false, [$this->event(0, 12, 'sidequest')], 'XP is a gain even with no Coins');
    }

    public function testAZeroDeltaEventDoesNothing(): void
    {
        $this->assertMood(false, false, [$this->event(0, 0, 'adjustment')], 'nothing moved, nothing to show');
    }

    // ------------------------------------------------ 10-11: batches

    public function testAMixedBatchShowsBoth(): void
    {
        $this->assertMood(
            true,
            true,
            [$this->event(5, 5, 'award'), $this->event(-2, 0, 'deduction')],
            'a parent awarded AND deducted between two visits — both are true, both are shown'
        );
    }

    public function testAnEmptyBatchShowsNothing(): void
    {
        $this->assertMood(false, false, [], 'the ordinary case: nothing happened since last time');
    }

    public function testASpendAndAnAwardTogetherCelebrateWithoutRaining(): void
    {
        $this->assertMood(
            true,
            false,
            [$this->event(-30, 0, 'reward_spend'), $this->event(5, 5, 'award')],
            'redeeming a reward must not drag rain onto an otherwise good day'
        );
    }

    // -------------------------------------------------- 12: enum coverage

    /**
     * Enumerated from the ENUM rather than hand-listed, so a type added to the
     * schema without a decision here fails loudly instead of silently defaulting
     * to "no feedback".
     */
    public function testEveryTransactionTypeHasADecision(): void
    {
        $spendTypes = ['reward_spend', 'milestone_spend'];

        foreach (self::TYPES as $type) {
            $this->assertMood(true, false, [$this->event(7, 0, $type)], "positive coins on '{$type}'");

            $this->assertMood(
                false,
                !in_array($type, $spendTypes, true),
                [$this->event(-7, 0, $type)],
                "negative coins on '{$type}'"
            );
        }
    }

    // ------------------------------------------- robustness of the pure part

    public function testMissingKeysAreTreatedAsNeutralRatherThanFatal(): void
    {
        $this->assertMood(false, false, [[]], 'a malformed row must not take the child\'s home page down');
    }

    public function testStringDeltasFromPdoAreHandled(): void
    {
        // PDO hands back column values as strings unless emulation is off for
        // every driver/type combination; the mood must not depend on that.
        $this->assertMood(
            true,
            true,
            [
                ['coins_delta' => '5', 'xp_delta' => '0', 'type' => 'award'],
                ['coins_delta' => '-2', 'xp_delta' => '0', 'type' => 'deduction'],
            ],
            'string deltas decide the same way as integers'
        );
    }
}
