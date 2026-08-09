<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * The hard write gate (plan §9 4b). EVERY domain mutation must run inside
 * Db::transaction with WriteGate::assertOpen() as its first gate touch —
 * the shared lock is held to COMMIT, so update/restore quiescence is provable.
 *
 * Deliberately exempt (operational, not domain data): login_attempts,
 * audit_log, update_history/backup_history (written BY the maintenance flows).
 */
final class WriteGate
{
    /**
     * Acquire the shared gate lock and fail if maintenance holds it.
     * Idempotent within a transaction — safe to call from nested services.
     *
     * @throws WriteLockedException while update/restore holds the gate
     */
    public static function assertOpen(Db $db): void
    {
        $gate = $db->fetchOne('SELECT write_locked FROM ops_state WHERE id = 1 LOCK IN SHARE MODE');
        if ($gate === null) {
            // Fail CLOSED — a missing gate row means an inconsistent install.
            throw new \RuntimeException('ops_state gate row missing — refusing writes.');
        }
        if ((int) $gate['write_locked'] === 1) {
            throw new WriteLockedException('Maintenance in progress — mutations are paused.');
        }
    }

    /** Convenience: gated transaction for domain mutations. */
    public static function transaction(Db $db, callable $fn): mixed
    {
        return $db->transaction(function (Db $db) use ($fn): mixed {
            self::assertOpen($db);

            return $fn($db);
        });
    }
}
