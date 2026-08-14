<?php

declare(strict_types=1);

namespace FamilyCastel\Http;

use FamilyCastel\Domain\PenaltyPhotoStore;

/**
 * Streams a penalty photo. One implementation, two callers (the parent view and
 * the child view), because they differ only in which guard let them in.
 *
 * Modelled on the only other streaming site in the codebase,
 * OpsController::downloadBackup() — including the exit() after readfile(), which
 * the router contract needs (index.php echoes the handler's return value).
 */
final class PenaltyPhotoResponse
{
    /**
     * @param string $absolutePath from PenaltyPhotoStore::resolve() — already
     *                             shape-checked and containment-checked
     * @param string $extension    from the VALIDATED path, never from the
     *                             stored mime column
     */
    public static function send(string $absolutePath, string $extension, int $transactionId): string
    {
        // The Content-Type comes from the validated path extension. The
        // transaction_photos.mime column is diagnostic only: a restored backup
        // or a hand-edited row could otherwise pair image bytes with
        // text/html, which is the one way an uploaded file could still become
        // executable content despite never being served by Apache.
        header('Content-Type: ' . PenaltyPhotoStore::mimeForExtension($extension));
        header('Content-Length: ' . (string) filesize($absolutePath));

        // inline, not attachment: the point is that the child SEES it.
        header('Content-Disposition: inline; filename="minuspunkt-' . $transactionId . '.' . $extension . '"');

        // private: a shared cache must never hold one child's photo where
        // another request could be served it.
        header('Cache-Control: private, no-store');

        // Also set globally at index.php:126 — repeated here because this
        // response is the one place where sniffing would actually matter.
        header('X-Content-Type-Options: nosniff');

        readfile($absolutePath);
        exit;
    }
}
