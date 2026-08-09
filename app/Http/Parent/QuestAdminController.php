<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Parent;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\ChildService;
use FamilyCastel\Domain\MilestoneService;
use FamilyCastel\Domain\RewardService;
use FamilyCastel\Domain\SidequestService;

/** Parent management of Sidequests, rewards and milestones (create/archive/claim). */
final class QuestAdminController
{
    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    // ------------------------------------------------------------ sidequests

    public function sidequests(): string
    {
        return $this->view->render('parent/sidequests', [
            'quests' => (new SidequestService($this->db))->listActiveQuests(),
            'children' => (new ChildService($this->db))->listActive(),
        ], 'layouts/parent');
    }

    public function createSidequest(array $post): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/sidequests');
        }

        try {
            (new SidequestService($this->db))->create([
                'title' => (string) ($post['title'] ?? ''),
                'description' => (string) ($post['description'] ?? ''),
                'coins_reward' => (int) ($post['coins_reward'] ?? 0),
                'xp_reward' => (int) ($post['xp_reward'] ?? 0),
                'type' => (string) ($post['type'] ?? 'once'),
                'ownership' => (string) ($post['ownership'] ?? 'first_come'),
                'assigned_child_ids' => (array) ($post['assigned_child_ids'] ?? []),
                'expires_at' => (string) ($post['expires_at'] ?? ''),
            ], (int) Auth::parentId());
            Session::flash('success', t('quests.created'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->redirect('/parent/sidequests');
    }

    public function archiveSidequest(int $id, array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            (new SidequestService($this->db))->archiveQuest($id);
            Session::flash('success', t('quests.archived'));
        }

        return $this->redirect('/parent/sidequests');
    }

    // ------------------------------------------------------------ rewards

    public function rewards(): string
    {
        return $this->view->render('parent/rewards', [
            'rewards' => (new RewardService($this->db))->activeRewards(),
        ], 'layouts/parent');
    }

    public function createReward(array $post): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/rewards');
        }

        try {
            (new RewardService($this->db))->createReward([
                'title' => (string) ($post['title'] ?? ''),
                'description' => (string) ($post['description'] ?? ''),
                'cost_coins' => (int) ($post['cost_coins'] ?? 0),
                'duration_minutes' => (string) ($post['duration_minutes'] ?? ''),
                'icon' => (string) ($post['icon'] ?? ''),
            ]);
            Session::flash('success', t('rewards.created'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->redirect('/parent/rewards');
    }

    public function archiveReward(int $id, array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            (new RewardService($this->db))->archiveReward($id);
            Session::flash('success', t('rewards.archived'));
        }

        return $this->redirect('/parent/rewards');
    }

    // ------------------------------------------------------------ milestones

    public function milestones(): string
    {
        $children = (new ChildService($this->db))->listActive();
        $milestones = new MilestoneService($this->db);
        $byChild = [];
        foreach ($children as $child) {
            $byChild[(int) $child['id']] = $milestones->activeFor((int) $child['id']);
        }

        return $this->view->render('parent/milestones', [
            'children' => $children,
            'milestonesByChild' => $byChild,
        ], 'layouts/parent');
    }

    public function createMilestone(array $post): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/milestones');
        }

        try {
            (new MilestoneService($this->db))->create(
                (int) ($post['child_id'] ?? 0),
                (string) ($post['title'] ?? ''),
                (int) ($post['target_coins'] ?? 0),
                in_array($post['spend_mode'] ?? '', ['spend', 'progress_only'], true) ? $post['spend_mode'] : null,
            );
            Session::flash('success', t('milestones.created'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->redirect('/parent/milestones');
    }

    public function claimMilestone(int $id, array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            try {
                (new MilestoneService($this->db))->claim($id, (int) Auth::parentId());
                Session::flash('success', t('milestones.claimed'));
            } catch (\FamilyCastel\Domain\InsufficientCoinsException) {
                Session::flash('error', t('milestones.error_insufficient'));
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/parent/milestones');
    }

    public function archiveMilestone(int $id, array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            (new MilestoneService($this->db))->archive($id);
            Session::flash('success', t('milestones.archived'));
        }

        return $this->redirect('/parent/milestones');
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
