<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Parent;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\ChildService;
use FamilyCastel\Domain\DuplicatePostException;
use FamilyCastel\Domain\InsufficientCoinsException;
use FamilyCastel\Domain\PenaltyPhotoStore;
use FamilyCastel\Domain\PenaltyService;
use FamilyCastel\Domain\SettingsService;
use FamilyCastel\Domain\WriteLockedException;
use FamilyCastel\Http\PenaltyPhotoResponse;

/**
 * "Minuspunkte" — record a coin deduction with a reason and, optionally, a
 * photo the child can look at, so the entry teaches instead of just subtracting.
 *
 * Deductions themselves are not new (the child screen has always accepted a
 * negative amount); what this page adds is a direct entry point and photo
 * evidence.
 */
final class PenaltiesController
{
    private const RECENT_LIMIT = 20;

    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    public function index(): string
    {
        return $this->view->render('parent/penalties', [
            'children' => (new ChildService($this->db))->listActive(),
            'recent' => $this->recent(),
            'maxUploadBytes' => $this->effectiveUploadLimit(),
        ], 'layouts/parent');
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function create(array $post, array $files): string
    {
        // BEFORE the CSRF check, deliberately. When a body exceeds
        // post_max_size PHP discards $_POST *and* $_FILES entirely, so _csrf is
        // gone and Csrf::validate(null) is false — the parent would be told
        // their SECURITY CHECK failed when what actually happened is that their
        // photo was too big, which sends the next person debugging it in
        // exactly the wrong direction.
        //
        // The test is narrow on purpose: an ordinary malformed POST must fall
        // through to the normal CSRF rejection, not be reported as "too large".
        // Safe without CSRF because this branch mutates nothing, and it is
        // unreachable without a parent session ($requireParent runs first).
        if ($this->looksLikePostMaxSizeOverflow($post, $files)) {
            Session::flash('error', t('penalties.error_too_large'));

            return $this->redirect('/parent/penalties');
        }

        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/penalties');
        }

        $childId = (int) ($post['child_id'] ?? 0);
        $coins = (int) ($post['coins'] ?? 0);
        $reason = (string) ($post['reason'] ?? '');
        $comment = trim((string) ($post['comment'] ?? '')) ?: null;
        $op = op_from_post($post);

        $upload = is_array($files['photo'] ?? null) ? $files['photo'] : null;

        $allowNegative = (bool) (new SettingsService($this->db))->get('economy.allow_negative_balance', false);

        try {
            $this->penalties()->record(
                childId: $childId,
                coins: $coins,
                reason: $reason,
                comment: $comment,
                upload: $upload,
                actorUserId: Auth::parentId(),
                idempotencyKey: $op !== null ? 'penalty:' . Auth::parentId() . ':' . $op : null,
                allowNegative: $allowNegative,
            );

            Session::flash('success', t('penalties.created'));
        } catch (DuplicatePostException) {
            // A replay's desired outcome is the outcome that already happened.
            Session::flash('success', t('penalties.created'));
        } catch (WriteLockedException) {
            Session::flash('error', t('common.maintenance'));
        } catch (InsufficientCoinsException) {
            Session::flash('error', t('award.error_insufficient'));
        } catch (\InvalidArgumentException $e) {
            Session::flash(
                'error',
                PenaltyPhotoStore::isTooLarge($e) ? t('penalties.error_too_large') : $e->getMessage()
            );
        } catch (\RuntimeException $e) {
            // Storage problems name a cause to the log, never to the browser.
            $this->log('penalty storage failure: ' . $e->getMessage());
            Session::flash('error', t('penalties.error_storage'));
        }

        return $this->redirect('/parent/penalties');
    }

    public function photo(int $transactionId): string
    {
        $row = $this->db->fetchOne(
            'SELECT p.path FROM transaction_photos p WHERE p.transaction_id = ?',
            [$transactionId]
        );
        if ($row === null) {
            return $this->redirect('/parent/penalties');
        }

        try {
            $absolute = $this->photos()->resolve((string) $row['path']);
        } catch (\InvalidArgumentException) {
            return $this->redirect('/parent/penalties');
        }

        return PenaltyPhotoResponse::send(
            $absolute,
            PenaltyPhotoStore::extensionOf((string) $row['path']),
            $transactionId
        );
    }

    // ------------------------------------------------------------------ helpers

    private function penalties(): PenaltyService
    {
        return new PenaltyService($this->db, $this->photos());
    }

    private function photos(): PenaltyPhotoStore
    {
        return new PenaltyPhotoStore(FC_ROOT . '/storage/uploads');
    }

    /** @return list<array<string, mixed>> */
    private function recent(): array
    {
        return $this->db->fetchAll(
            "SELECT t.id, t.title, t.coins_delta, t.comment, t.created_at,
                    c.name AS child_name, p.id AS photo_id
             FROM transactions t
             JOIN children c ON c.id = t.child_id
             LEFT JOIN transaction_photos p ON p.transaction_id = t.id
             WHERE t.type = 'deduction'
             ORDER BY t.id DESC
             LIMIT ?",
            [self::RECENT_LIMIT]
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    private function looksLikePostMaxSizeOverflow(array $post, array $files): bool
    {
        if ($post !== [] || $files !== []) {
            return false;
        }

        $limit = self::shorthandToBytes((string) ini_get('post_max_size'));
        if ($limit <= 0) {
            return false;   // 0 / unset means unlimited: this cannot be the cause
        }

        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
    }

    /** The smallest of the two ini limits is what a parent can actually send. */
    private function effectiveUploadLimit(): int
    {
        $candidates = array_filter([
            PenaltyPhotoStore::MAX_BYTES,
            self::shorthandToBytes((string) ini_get('upload_max_filesize')),
            self::shorthandToBytes((string) ini_get('post_max_size')),
        ], static fn (int $v): bool => $v > 0);

        return $candidates === [] ? PenaltyPhotoStore::MAX_BYTES : (int) min($candidates);
    }

    /** PHP ini shorthand ("32M", "2G") to bytes. */
    private static function shorthandToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function log(string $message): void
    {
        @file_put_contents(
            FC_ROOT . '/storage/logs/app.log',
            gmdate('c') . ' ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
