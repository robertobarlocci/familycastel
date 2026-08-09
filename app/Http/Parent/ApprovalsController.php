<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Parent;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\AchievementService;
use FamilyCastel\Domain\InsufficientCoinsException;
use FamilyCastel\Domain\MilestoneService;
use FamilyCastel\Domain\NotificationService;
use FamilyCastel\Domain\RewardService;
use FamilyCastel\Domain\SidequestService;
use FamilyCastel\Domain\SuggestionService;
use FamilyCastel\Domain\WriteLockedException;

/** The central approval hub — must be FAST (approve in one tap). */
final class ApprovalsController
{
    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    public function index(): string
    {
        return $this->view->render('parent/approvals', [
            'claims' => (new SidequestService($this->db))->pendingApprovals(),
            'suggestions' => (new SuggestionService($this->db))->pending(),
            'rewardRequests' => (new RewardService($this->db))->pending(),
            'wishes' => (new MilestoneService($this->db))->pendingWishes(),
        ], 'layouts/parent');
    }

    public function decide(array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect();
        }

        $kind = (string) ($post['kind'] ?? '');
        $action = (string) ($post['action'] ?? '');
        $id = (int) ($post['id'] ?? 0);
        $comment = trim((string) ($post['comment'] ?? '')) ?: null;
        $coins = ($post['coins'] ?? '') !== '' ? (int) $post['coins'] : null;
        $xp = ($post['xp'] ?? '') !== '' ? (int) $post['xp'] : null;
        $parentId = (int) Auth::parentId();

        // Explicit allowlist — anything else must never fall through to a
        // destructive default.
        if (!in_array($action, ['approve', 'reject'], true)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect();
        }

        try {
            // Decision + achievement sync + outcome notification commit as ONE
            // transaction — a failure in any part retries as a whole instead
            // of leaving achievements/notifications permanently missing.
            [$childId, $decided] = $this->db->transaction(function () use ($kind, $action, $id, $parentId, $coins, $xp, $comment): array {
                [$childId, $decided] = $this->applyDecision($kind, $action, $id, $parentId, $coins, $xp, $comment);
                if ($decided && $childId !== null) {
                    if ($action === 'approve') {
                        (new AchievementService($this->db, new NotificationService($this->db)))->sync($childId);
                    }
                    (new NotificationService($this->db))->notify('child', $childId, $kind . '_' . $action, ['id' => $id]);
                }

                return [$childId, $decided];
            });
            Session::flash(
                $decided ? 'success' : 'error',
                $decided
                    ? ($action === 'approve' ? t('approvals.approved') : t('approvals.rejected'))
                    : t('approvals.already_decided')
            );
        } catch (WriteLockedException) {
            Session::flash('error', t('common.maintenance'));
        } catch (InsufficientCoinsException) {
            Session::flash('error', t('approvals.error_insufficient'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->redirect();
    }

    /** @return array{0: int|null, 1: bool} [child id, whether THIS call decided it] */
    private function applyDecision(
        string $kind,
        string $action,
        int $id,
        int $parentId,
        ?int $coins,
        ?int $xp,
        ?string $comment,
    ): array {
        switch ($kind) {
            case 'sidequest':
                $claim = $this->db->fetchOne('SELECT child_id FROM sidequest_claims WHERE id = ?', [$id]);
                $service = new SidequestService($this->db);
                $decided = $action === 'approve'
                    ? $service->approve($id, $parentId, $coins, $xp, $comment)
                    : $service->reject($id, $parentId, $comment);

                return [$claim !== null ? (int) $claim['child_id'] : null, $decided];

            case 'suggestion':
                $row = $this->db->fetchOne('SELECT child_id, suggested_coins FROM suggestions WHERE id = ?', [$id]);
                if ($row === null) {
                    throw new \InvalidArgumentException('Suggestion not found.');
                }
                $service = new SuggestionService($this->db);
                $decided = $action === 'approve'
                    ? $service->approve($id, $parentId, $coins ?? (int) $row['suggested_coins'], $xp ?? ($coins ?? (int) $row['suggested_coins']), $comment)
                    : $service->reject($id, $parentId, $comment);

                return [(int) $row['child_id'], $decided];

            case 'reward':
                $row = $this->db->fetchOne('SELECT child_id FROM reward_requests WHERE id = ?', [$id]);
                $service = new RewardService($this->db);
                $decided = $action === 'approve'
                    ? $service->approve($id, $parentId, $coins, $comment)
                    : $service->reject($id, $parentId, $comment);

                return [$row !== null ? (int) $row['child_id'] : null, $decided];

            case 'wish':
                $row = $this->db->fetchOne('SELECT child_id FROM milestone_requests WHERE id = ?', [$id]);
                $service = new MilestoneService($this->db);
                $decided = $action === 'approve'
                    ? $service->approveWish($id, $parentId, $coins, $comment)
                    : $service->rejectWish($id, $parentId, $comment);

                return [$row !== null ? (int) $row['child_id'] : null, $decided];

            default:
                throw new \InvalidArgumentException('Unknown approval kind.');
        }
    }

    private function redirect(): string
    {
        header('Location: ' . url('/parent/approvals'), true, 302);

        return '';
    }
}
