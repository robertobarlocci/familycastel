<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Kid;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Request;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\AchievementService;
use FamilyCastel\Domain\CelebrationService;
use FamilyCastel\Domain\InsufficientCoinsException;
use FamilyCastel\Domain\JournalService;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\LevelService;
use FamilyCastel\Domain\MilestoneService;
use FamilyCastel\Domain\PenaltyPhotoStore;
use FamilyCastel\Domain\QuestUnavailableException;
use FamilyCastel\Domain\RewardService;
use FamilyCastel\Domain\SettingsService;
use FamilyCastel\Domain\SidequestService;
use FamilyCastel\Domain\SuggestionService;
use FamilyCastel\Domain\WriteLockedException;
use FamilyCastel\Http\PenaltyPhotoResponse;

/** The child's game area. Every action is scoped to the logged-in child. */
final class KidController
{
    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    private function childId(): int
    {
        return (int) Auth::childId();
    }

    private function child(): array
    {
        return $this->db->fetchOne('SELECT * FROM children WHERE id = ?', [$this->childId()]) ?? [];
    }

    private const CHARACTER_EMOJI = [
        'knight' => '🤺', 'archer' => '🏹', 'mage' => '🧙', 'paladin' => '🛡️',
        'striker' => '👟', 'goalkeeper' => '🧤', 'defender' => '💪', 'midfielder' => '🎯',
    ];

    /**
     * How many Journal entries this child has not looked at yet, for the dock
     * badge. Decoration: it must never break a page, so a closed write gate or
     * any other failure reports "nothing new" rather than a 500 — the same
     * stance the parent header takes around its own badge.
     */
    private function journalUnseen(): int
    {
        try {
            return (new JournalService($this->db))->unseenCount($this->childId());
        } catch (\Throwable $e) {
            $this->logDecorationFailure('journal badge unavailable', $e);

            return 0;
        }
    }

    /**
     * Record a failure in the feedback layer without failing the page.
     *
     * The whole celebration/badge feature is decoration on top of the Journal,
     * which keeps every entry forever (INV-001), so nothing is lost when it
     * degrades. But it must not degrade SILENTLY: a badge stuck at 0 because a
     * query is broken looks exactly like a badge that is correctly 0.
     */
    private function logDecorationFailure(string $what, \Throwable $e): void
    {
        @file_put_contents(
            FC_ROOT . '/storage/logs/app.log',
            gmdate('c') . ' ' . $what . ': ' . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }

    public function home(): string
    {
        $child = $this->child();
        $achievements = new AchievementService($this->db);
        $levels = LevelService::fromSettings(new SettingsService($this->db));

        // Self-healing: re-evaluate achievements on every home visit — cheap,
        // and catches any unlock a failed post-approval sync missed.
        $achievements->sync($this->childId());

        // Celebration queue: unseen achievement unlocks celebrate ONCE —
        // read-and-consume is atomic (concurrent loads can't double-fire).
        //
        // Deliberately left exactly as it has always been, and NOT given the
        // navigation guard or the decoration-failure catch that the transaction
        // feedback below has. Both gaps are real — a prefetch can eat an unlock,
        // and a transient write failure here still 500s the page — but they are
        // pre-existing and live in the achievements path, so fixing them means
        // touching that path and its tests. Tracked as issue #22 together with
        // the unbounded-UPDATE bug in takeUnseen() itself. The asymmetry below
        // is on purpose; it is not an oversight.
        $celebrations = $achievements->takeUnseen($this->childId());

        // The SECOND celebration source, next to achievements: the Coin/XP
        // events themselves. Every award, deduction, approval and correction
        // goes through the ledger (INV-002), so consuming transactions is what
        // makes ALL of them produce feedback — an "Eigene Aktion" award as much
        // as a Sidequest approval — instead of only the paths somebody
        // remembered to wire up.
        $feedback = ['positive' => false, 'negative' => false];
        if (Request::isUserNavigation()) {
            try {
                $feedback = CelebrationService::feedbackFor(
                    (new CelebrationService($this->db))->takeUncelebrated($this->childId())
                );
            } catch (\Throwable $e) {
                // A backup or restore is running (WriteLockedException), or the
                // seen-state columns are missing because migration 008 has not
                // run yet on a hand-updated install, or the write simply fails.
                // In every case the events keep their NULL marks and celebrate
                // on the next visit. This is DECORATION: it must never be able
                // to turn a child's castle into a 500, so the catch is broad on
                // purpose — and logged, so a permanently silent celebration is
                // distinguishable from a correctly quiet one.
                $this->logDecorationFailure('celebration unavailable', $e);
            }
        }

        $level = (int) $child['level'];

        return $this->view->render('kid/home', [
            'child' => $child,
            'progress' => $levels->progress((int) $child['xp_total']),
            'available' => (new LedgerService($this->db))->availableBalance($this->childId()),
            'milestones' => (new MilestoneService($this->db))->activeFor($this->childId()),
            'openQuests' => count((new SidequestService($this->db))->availableFor($this->childId())),
            'celebrations' => $celebrations,
            'feedback' => $feedback,
            'journalUnseen' => $this->journalUnseen(),
            'worldTier' => \FamilyCastel\Domain\ThemeService::worldTier($level),
            'nextTierLevel' => \FamilyCastel\Domain\ThemeService::nextWorldTierLevel($level),
            'titleKey' => \FamilyCastel\Domain\ThemeService::titleKey((string) $child['theme'], $level),
            'characterEmoji' => self::CHARACTER_EMOJI[$child['character_key']] ?? '🤺',
        ], 'layouts/kid');
    }

    // ------------------------------------------------------------ settings

    public function settings(): string
    {
        $child = $this->child();

        return $this->view->render('kid/settings', [
            'child' => $child,
            'allowedThemes' => \FamilyCastel\Domain\ThemeService::allowedThemes($child['allowed_themes']),
            'journalUnseen' => $this->journalUnseen(),
        ], 'layouts/kid');
    }

    public function saveSettings(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            $child = $this->child();
            $theme = (string) ($post['theme'] ?? $child['theme']);
            $allowed = \FamilyCastel\Domain\ThemeService::allowedThemes($child['allowed_themes']);
            if (!in_array($theme, $allowed, true)) {
                $theme = (string) $child['theme'];
            }
            $sound = !empty($post['sound_enabled']) ? 1 : 0;

            try {
                \FamilyCastel\Domain\WriteGate::transaction($this->db, fn ($db) => $db->execute(
                    'UPDATE children SET theme = ?, sound_enabled = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                    [$theme, $sound, $this->childId()]
                ));
                Session::flash('success', t('kidsettings.saved'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            }
        }

        return $this->redirect('/kid/settings');
    }

    // ------------------------------------------------------------ sidequests

    public function sidequests(): string
    {
        $quests = new SidequestService($this->db);

        return $this->view->render('kid/sidequests', [
            'child' => $this->child(),
            'available' => $quests->availableFor($this->childId()),
            'claims' => $quests->claimsFor($this->childId(), 30),
            'journalUnseen' => $this->journalUnseen(),
        ], 'layouts/kid');
    }

    public function acceptQuest(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                (new SidequestService($this->db))->accept((int) ($post['quest_id'] ?? 0), $this->childId());
                Session::flash('success', t('kidquests.accepted'));
            } catch (QuestUnavailableException) {
                Session::flash('error', t('kidquests.too_late'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/kid/sidequests');
    }

    public function completeQuest(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                (new SidequestService($this->db))->markCompleted((int) ($post['claim_id'] ?? 0), $this->childId());
                Session::flash('success', t('kidquests.completed'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/kid/sidequests');
    }

    public function cancelQuest(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                (new SidequestService($this->db))->cancel((int) ($post['claim_id'] ?? 0), $this->childId());
                Session::flash('success', t('kidquests.cancelled'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/kid/sidequests');
    }

    // ------------------------------------------------------------ suggestions

    public function submitSuggestion(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                (new SuggestionService($this->db))->submit(
                    $this->childId(),
                    (string) ($post['title'] ?? ''),
                    max(0, (int) ($post['coins'] ?? 0)),
                    (string) ($post['comment'] ?? '') ?: null,
                );
                Session::flash('success', t('kidquests.suggested'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/kid/sidequests');
    }

    // ------------------------------------------------------------ rewards

    public function rewards(): string
    {
        $rewards = new RewardService($this->db);

        return $this->view->render('kid/rewards', [
            'child' => $this->child(),
            'available' => (new LedgerService($this->db))->availableBalance($this->childId()),
            'catalog' => $rewards->activeRewards(),
            'requests' => $rewards->forChild($this->childId(), 20),
            'journalUnseen' => $this->journalUnseen(),
        ], 'layouts/kid');
    }

    public function redeemReward(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                $op = op_from_post($post);
                (new RewardService($this->db))->request(
                    (int) ($post['reward_id'] ?? 0),
                    $this->childId(),
                    requestKey: $op !== null ? 'req:' . $this->childId() . ':' . $op : null,
                );
                Session::flash('success', t('kidrewards.requested'));
            } catch (InsufficientCoinsException) {
                Session::flash('error', t('kidrewards.error_insufficient'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/kid/rewards');
    }

    public function wishReward(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                $op = op_from_post($post);
                (new RewardService($this->db))->requestCustomKeyed(
                    $this->childId(),
                    (string) ($post['title'] ?? ''),
                    ($post['duration_minutes'] ?? '') !== '' ? (int) $post['duration_minutes'] : null,
                    $op !== null ? 'wish:' . $this->childId() . ':' . $op : null,
                );
                Session::flash('success', t('kidrewards.wished'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/kid/rewards');
    }

    public function cancelReward(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            (new RewardService($this->db))->cancel((int) ($post['request_id'] ?? 0), $this->childId());
            Session::flash('success', t('kidrewards.cancelled'));
        }

        return $this->redirect('/kid/rewards');
    }

    // ------------------------------------------------------------ milestones

    public function milestones(): string
    {
        return $this->view->render('kid/milestones', [
            'child' => $this->child(),
            'milestones' => (new MilestoneService($this->db))->activeFor($this->childId()),
            'wishes' => $this->db->fetchAll(
                'SELECT * FROM milestone_requests WHERE child_id = ? ORDER BY id DESC LIMIT 10',
                [$this->childId()]
            ),
            'journalUnseen' => $this->journalUnseen(),
        ], 'layouts/kid');
    }

    public function wishMilestone(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                (new MilestoneService($this->db))->submitWish(
                    $this->childId(),
                    (string) ($post['title'] ?? ''),
                    max(1, (int) ($post['coins'] ?? 0)),
                );
                Session::flash('success', t('kidmilestones.wished'));
            } catch (WriteLockedException) {
                Session::flash('error', t('common.maintenance'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/kid/milestones');
    }

    // ------------------------------------------------------------ achievements & journal

    public function achievements(): string
    {
        return $this->view->render('kid/achievements', [
            'child' => $this->child(),
            'achievements' => (new AchievementService($this->db))->allWithState($this->childId()),
            'journalUnseen' => $this->journalUnseen(),
        ], 'layouts/kid');
    }

    public function journal(): string
    {
        $filter = (string) ($_GET['filter'] ?? 'all');
        if (!in_array($filter, JournalService::FILTERS, true)) {
            $filter = 'all';
        }

        $journal = new JournalService($this->db);

        // Clear the "something new" mark only when the child has actually been
        // shown everything: the unfiltered view, opened deliberately. A filtered
        // view would hide the very entries the badge was pointing at, and a
        // prefetch is not a visit. The dock link carries no filter, so tapping
        // the badge always clears it.
        if ($filter === 'all' && Request::isUserNavigation()) {
            try {
                $journal->markSeen($this->childId());
            } catch (\Throwable $e) {
                // Maintenance, a missing column on a hand-updated install, or a
                // transient write failure. Reading the Journal is the point of
                // this page — it must still render; the badge simply stays.
                $this->logDecorationFailure('journal mark-seen failed', $e);
            }
        }

        return $this->view->render('kid/journal', [
            'child' => $this->child(),
            'filter' => $filter,
            'filters' => JournalService::FILTERS,
            'entries' => $journal->entries($this->childId(), $filter),
            'journalUnseen' => $this->journalUnseen(),
        ], 'layouts/kid');
    }

    /**
     * The photo attached to a penalty in this child's journal.
     *
     * The transaction id in the URL is guessable by counting, so ownership is
     * proven from the SESSION principal and never from the URL — this is the
     * IDOR boundary between siblings. A miss redirects rather than disclosing
     * whether that id exists.
     */
    public function penaltyPhoto(int $transactionId): string
    {
        $row = $this->db->fetchOne(
            'SELECT p.path
             FROM transaction_photos p
             JOIN transactions t ON t.id = p.transaction_id
             WHERE p.transaction_id = ? AND t.child_id = ?',
            [$transactionId, $this->childId()]
        );
        if ($row === null) {
            return $this->redirect('/kid/journal');
        }

        try {
            $absolute = (new PenaltyPhotoStore(FC_ROOT . '/storage/uploads'))->resolve((string) $row['path']);
        } catch (\InvalidArgumentException) {
            return $this->redirect('/kid/journal');
        }

        return PenaltyPhotoResponse::send(
            $absolute,
            PenaltyPhotoStore::extensionOf((string) $row['path']),
            $transactionId
        );
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
