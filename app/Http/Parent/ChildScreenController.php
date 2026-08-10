<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Parent;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\ChildService;
use FamilyCastel\Domain\InsufficientCoinsException;
use FamilyCastel\Domain\LedgerService;
use FamilyCastel\Domain\SettingsService;
use FamilyCastel\Domain\TemplateService;
use FamilyCastel\Domain\WriteLockedException;

/**
 * THE parent speed screen: select child → one-tap template award, or a quick
 * custom award. Target: under 5 seconds from opening the app (RULE: parent
 * speed is priority #2).
 */
final class ChildScreenController
{
    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    public function show(int $childId): string
    {
        $child = (new ChildService($this->db))->find($childId);
        if ($child === null || $child['archived_at'] !== null) {
            return $this->redirect('/parent');
        }

        $ledger = new LedgerService($this->db);
        $templates = (new TemplateService($this->db))->forChild($childId);

        return $this->view->render('parent/child', [
            'child' => $child,
            'available' => $ledger->availableBalance($childId),
            'templates' => $templates,
            'history' => $ledger->history($childId, 10),
        ], 'layouts/parent');
    }

    /** One-tap template award. */
    public function applyTemplate(int $childId, array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/child/' . $childId);
        }

        try {
            $op = op_from_post($post);
            $templates = new TemplateService($this->db);
            $templates->apply(
                (int) ($post['template_id'] ?? 0),
                $childId,
                Auth::parentId(),
                idempotencyKey: $op !== null ? 'award:' . Auth::parentId() . ':' . $op : null,
            );
            (new \FamilyCastel\Domain\AchievementService($this->db, new \FamilyCastel\Domain\NotificationService($this->db)))->sync($childId);
            Session::flash('success', t('award.template_done'));
        } catch (\FamilyCastel\Domain\DuplicatePostException) {
            // Same form nonce again (double-click/replay): the award already
            // happened exactly once — that IS the success the parent wanted.
            Session::flash('success', t('award.template_done'));
        } catch (WriteLockedException) {
            Session::flash('error', t('common.maintenance'));
        } catch (InsufficientCoinsException) {
            Session::flash('error', t('award.error_insufficient'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->redirect('/parent/child/' . $childId);
    }

    /** Quick custom award/deduction, optionally saved as a template. */
    public function custom(int $childId, array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/child/' . $childId);
        }

        $title = trim((string) ($post['title'] ?? ''));
        $coins = (int) ($post['coins'] ?? 0);
        $xp = max(0, (int) ($post['xp'] ?? 0));
        $comment = trim((string) ($post['comment'] ?? '')) ?: null;
        $allowNegative = (bool) (new SettingsService($this->db))->get('economy.allow_negative_balance', false);

        try {
            if ($title === '' || mb_strlen($title) > 190 || ($coins === 0 && $xp === 0)) {
                throw new \InvalidArgumentException(t('award.error_fields'));
            }

            // Award + optional template creation are ONE transaction — a
            // template validation failure must never leave a committed award
            // behind (retry would double-post).
            $op = op_from_post($post);
            $this->db->transaction(function () use ($childId, $coins, $xp, $title, $comment, $allowNegative, $post, $op): void {
                (new LedgerService($this->db))->post(
                    childId: $childId,
                    coinsDelta: $coins,
                    xpDelta: $xp,
                    type: $coins >= 0 ? 'award' : 'deduction',
                    title: $title,
                    actorUserId: Auth::parentId(),
                    comment: $comment,
                    idempotencyKey: $op !== null ? 'custom:' . Auth::parentId() . ':' . $op : null,
                    allowNegative: $allowNegative,
                );

                if (!empty($post['save_as_template'])) {
                    (new TemplateService($this->db))->create([
                        'title' => $title,
                        'coins_delta' => $coins,
                        'xp_delta' => $xp,
                        'scope' => 'all',
                        'is_favorite' => true,
                    ]);
                }
            });
            (new \FamilyCastel\Domain\AchievementService($this->db, new \FamilyCastel\Domain\NotificationService($this->db)))->sync($childId);
            Session::flash('success', $coins >= 0 ? t('award.custom_done') : t('award.deduct_done'));
        } catch (\FamilyCastel\Domain\DuplicatePostException) {
            Session::flash('success', $coins >= 0 ? t('award.custom_done') : t('award.deduct_done'));
        } catch (WriteLockedException) {
            Session::flash('error', t('common.maintenance'));
        } catch (InsufficientCoinsException) {
            Session::flash('error', t('award.error_insufficient'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->redirect('/parent/child/' . $childId);
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
