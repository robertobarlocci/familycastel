<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Kid;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\AchievementService;
use FamilyCastel\Domain\InsufficientCoinsException;
use FamilyCastel\Domain\JournalService;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\LevelService;
use FamilyCastel\Domain\MilestoneService;
use FamilyCastel\Domain\QuestUnavailableException;
use FamilyCastel\Domain\RewardService;
use FamilyCastel\Domain\SettingsService;
use FamilyCastel\Domain\SidequestService;
use FamilyCastel\Domain\SuggestionService;
use FamilyCastel\Domain\WriteLockedException;

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
        $celebrations = $achievements->takeUnseen($this->childId());

        $level = (int) $child['level'];

        return $this->view->render('kid/home', [
            'child' => $child,
            'progress' => $levels->progress((int) $child['xp_total']),
            'available' => (new LedgerService($this->db))->availableBalance($this->childId()),
            'milestones' => (new MilestoneService($this->db))->activeFor($this->childId()),
            'openQuests' => count((new SidequestService($this->db))->availableFor($this->childId())),
            'celebrations' => $celebrations,
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
        ], 'layouts/kid');
    }

    public function redeemReward(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                (new RewardService($this->db))->request((int) ($post['reward_id'] ?? 0), $this->childId());
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
                (new RewardService($this->db))->requestCustom(
                    $this->childId(),
                    (string) ($post['title'] ?? ''),
                    ($post['duration_minutes'] ?? '') !== '' ? (int) $post['duration_minutes'] : null,
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
        ], 'layouts/kid');
    }

    public function journal(): string
    {
        $filter = (string) ($_GET['filter'] ?? 'all');
        if (!in_array($filter, JournalService::FILTERS, true)) {
            $filter = 'all';
        }

        return $this->view->render('kid/journal', [
            'child' => $this->child(),
            'filter' => $filter,
            'filters' => JournalService::FILTERS,
            'entries' => (new JournalService($this->db))->entries($this->childId(), $filter),
        ], 'layouts/kid');
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
