<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Records a "Minuspunkt" — a coin deduction with a reason and an optional photo
 * the child can look at.
 *
 * This is NOT a second economy. Coins move through LedgerService::post() with
 * type 'deduction', exactly as the child screen's custom award already does
 * (INV-002), and XP is a literal 0 because XP never decreases.
 *
 * The hard part is that a filesystem move cannot join a database transaction.
 * Three rules keep that sound:
 *
 * 1. The photo is stored BEFORE the transaction opens. Doing it inside would
 *    mean a rolled-back transaction leaves a file nothing references and that
 *    nothing could ever find again.
 * 2. The compensation RE-DERIVES rather than interpreting the exception. An
 *    exception escaping transaction() does not prove a rollback — a COMMIT that
 *    fails at the network layer can leave a committed row. Deleting on "an
 *    exception happened" would destroy the photo of a penalty the child can
 *    already see.
 * 3. Writers and the orphan sweep serialize on a MariaDB advisory lock, not on
 *    flock: flock is not coherent on NFS-backed or multi-node shared hosting,
 *    and a lock FILE could not exist before the first upload created its
 *    directory. Advisory locks are connection-scoped, so a crash self-heals.
 */
final class PenaltyService
{
    public const MIN_COINS = 1;
    public const MAX_COINS = 999;
    public const REASON_MAX = 190;
    public const COMMENT_MAX = 500;

    /** Seconds a writer waits for the advisory lock before failing closed. */
    private const LOCK_TIMEOUT = 10;

    /** Roughly 1 % of successful recordings also sweep — shared hosting has no cron. */
    private const SWEEP_IN = 100;

    private ?string $lockName = null;
    private bool $holdsLock = false;

    public function __construct(
        private readonly Db $db,
        private readonly PenaltyPhotoStore $photos,
    ) {
    }

    /**
     * @param int         $coins  POSITIVE magnitude; this service owns the sign
     * @param array|null  $upload one entry of $_FILES, or null
     *
     * @return int the new transaction's id
     */
    public function record(
        int $childId,
        int $coins,
        string $reason,
        ?string $comment,
        ?array $upload,
        ?int $actorUserId,
        ?string $idempotencyKey,
        bool $allowNegative,
    ): int {
        // The no-deadlock argument depends on this service owning its
        // transaction boundary: Db::transaction() JOINS an existing
        // transaction, so a caller that wrapped us would make the advisory lock
        // be taken AFTER that transaction's row locks, reversing the ordering.
        // Checked, not trusted — and checked before any lock or any file.
        if ($this->db->pdo()->inTransaction()) {
            throw new \LogicException('PenaltyService::record() must not run inside a transaction.');
        }

        $reason = trim($reason);
        $comment = $comment !== null ? trim($comment) : null;
        $comment = ($comment === null || $comment === '') ? null : mb_substr($comment, 0, self::COMMENT_MAX);

        // Both bounds live here, not only in the form: max="999" is a hint to a
        // browser, not a constraint on an HTTP request. Without the upper bound
        // a forged POST would be limited only by LedgerService's 100 000
        // ceiling — from a page whose whole purpose is small consequences.
        if ($coins < self::MIN_COINS || $coins > self::MAX_COINS) {
            throw new \InvalidArgumentException(t('penalties.error_coins'));
        }
        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new \InvalidArgumentException(t('penalties.error_reason'));
        }

        $this->acquireLock();

        $stored = null;
        try {
            if ($upload !== null && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $stored = $this->photos->store($upload);
            }

            try {
                $transactionId = $this->db->transaction(
                    function (Db $db) use ($childId, $coins, $reason, $comment, $actorUserId, $idempotencyKey, $allowNegative, $stored): int {
                        $id = (new LedgerService($db))->post(
                            childId: $childId,
                            coinsDelta: -$coins,
                            xpDelta: 0,                 // INV-002: XP never decreases
                            type: 'deduction',
                            title: $reason,
                            actorUserId: $actorUserId,
                            comment: $comment,
                            idempotencyKey: $idempotencyKey,
                            allowNegative: $allowNegative,
                        );

                        if ($stored !== null) {
                            $db->execute(
                                'INSERT INTO transaction_photos (transaction_id, path, mime, byte_size, created_at)
                                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                                [$id, $stored['path'], $stored['mime'], $stored['bytes']]
                            );
                        }

                        return $id;
                    }
                );
            } catch (\Throwable $e) {
                if ($stored !== null) {
                    $this->discardIfUnreferenced($stored['path']);
                }
                throw $e;
            }

            (new AchievementService($this->db, new NotificationService($this->db)))->sync($childId);

            if (random_int(1, self::SWEEP_IN) === 1) {
                $this->sweepOrphans();
            }

            return $transactionId;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Delete a stored file ONLY when the database proves nothing references it.
     *
     * The direction of failure is the whole design: when the re-derivation
     * cannot run (the database is the thing that just broke), the file is
     * KEPT. An unreferenced file is a few bytes in a directory nothing serves;
     * a deleted referenced file is lost evidence.
     */
    private function discardIfUnreferenced(string $path): void
    {
        try {
            // A locking read is a CURRENT read, never a REPEATABLE READ
            // snapshot — so the answer cannot be stale.
            $row = $this->db->fetchOne(
                'SELECT id FROM transaction_photos WHERE path = ? LOCK IN SHARE MODE',
                [$path]
            );
        } catch (\Throwable) {
            return;
        }

        if ($row === null) {
            $this->photos->delete($path);
        }
    }

    /**
     * Collect files whose transaction never committed (the process was killed
     * between the move and the commit, so the compensation never ran).
     *
     * Correctness comes from the advisory lock, not from an age threshold: any
     * grace period is a guess about how long a request may take, and a request
     * that stalls past it would have its file deleted and then commit a row
     * pointing at nothing. While this holds the lock, no writer is between its
     * move and its commit, so "no row" means "never will have one".
     *
     * Best effort throughout: this is maintenance and must never fail a
     * parent's penalty.
     */
    public function sweepOrphans(): void
    {
        // A stale snapshot would make "no row" a lie. In autocommit every
        // statement gets a fresh read view; inside a transaction it would not.
        if ($this->db->pdo()->inTransaction()) {
            return;
        }
        // record() already holds the lock when it calls us; a standalone call
        // has to take it — and must then RELEASE it, or a lock acquired here
        // outlives the sweep and blocks every later writer on this connection.
        $acquiredHere = false;
        if (!$this->holdsLock) {
            if (!$this->tryLock()) {
                return;
            }
            $acquiredHere = true;
        }

        try {
            $root = $this->photos->penaltiesDir();
            $months = $this->monthDirectories($root);
            if ($months === []) {
                return;
            }

            $month = $this->nextMonth($root, $months);

            // The cursor is written BEFORE the sweep on purpose, so the
            // fallback is driven by what actually happened rather than by a
            // prediction: is_writable() would only have covered permissions,
            // while a quota, a full disk or a failed rename fail just as hard.
            // If the choice cannot be remembered, remembering it is pointless —
            // pick randomly instead, which is stateless and therefore cannot
            // starve the other months no matter how often the write fails.
            // Writing early costs nothing: the cursor is a hint, so the worst
            // case is one month skipped until the next lap.
            if (!$this->writeCursor($root, $month)) {
                $month = $months[random_int(0, count($months) - 1)];
            }

            $this->sweepMonth($root, $month);
        } catch (\Throwable $e) {
            $this->logSweepFailure($e);
        } finally {
            if ($acquiredHere) {
                $this->releaseLock();
            }
        }
    }

    // ------------------------------------------------------------------ sweeping

    /** @return list<string> sorted 'YYYY/MM' directories that really exist */
    private function monthDirectories(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $months = [];
        foreach ((array) scandir($root) as $year) {
            if (!is_string($year) || preg_match('/^\d{4}$/', $year) !== 1) {
                continue;
            }
            $yearDir = $root . '/' . $year;
            if (!is_dir($yearDir) || is_link($yearDir)) {
                continue;
            }
            foreach ((array) scandir($yearDir) as $mm) {
                if (!is_string($mm) || preg_match('/^\d{2}$/', $mm) !== 1) {
                    continue;
                }
                $monthDir = $yearDir . '/' . $mm;
                if (is_dir($monthDir) && !is_link($monthDir)) {
                    $months[] = $year . '/' . $mm;
                }
            }
        }
        sort($months, SORT_STRING);

        return $months;
    }

    /**
     * The cursor is a HINT, never state: anything invalid, missing or naming a
     * directory that no longer exists restarts deterministically from the
     * oldest month. Corrupt state can therefore never wedge the sweep.
     *
     * @param list<string> $months
     */
    private function nextMonth(string $root, array $months): string
    {
        $cursor = @file_get_contents($root . '/.sweep-cursor');
        $cursor = is_string($cursor) ? trim($cursor) : '';

        if (preg_match('#^\d{4}/\d{2}$#', $cursor) !== 1) {
            return $months[0];
        }

        $index = array_search($cursor, $months, true);
        if ($index === false) {
            return $months[0];
        }

        return $months[($index + 1) % count($months)];
    }

    /** @return bool whether the choice was durably recorded */
    private function writeCursor(string $root, string $month): bool
    {
        // tmp + rename in the same directory: a kill during the write leaves
        // either the old value or the new one, never a half-written one.
        // Requires the DIRECTORY to be writable, not the cursor file.
        $target = $root . '/.sweep-cursor';
        $tmp = $root . '/.sweep-cursor.' . bin2hex(random_bytes(6));

        // A SHORT write is a failure too: under a quota or a full disk
        // file_put_contents() returns a byte count rather than false, and
        // renaming a truncated cursor into place would publish a half value.
        if (@file_put_contents($tmp, $month) !== strlen($month)) {
            @unlink($tmp);
            $this->logSweepFailure(new \RuntimeException('sweep cursor not fully written: ' . $target));

            return false;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            $this->logSweepFailure(new \RuntimeException('sweep cursor could not be replaced: ' . $target));

            return false;
        }

        return true;
    }

    private function sweepMonth(string $root, string $month): void
    {
        $dir = $root . '/' . $month;
        $base = realpath($root);
        $real = realpath($dir);

        // The sweep DELETES files, so it gets the read boundary's treatment
        // rather than being trusted because it is internal.
        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return;
        }

        foreach ((array) scandir($real) as $entry) {
            if (!is_string($entry) || preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $entry) !== 1) {
                continue;
            }
            $file = $real . '/' . $entry;
            if (!is_file($file) || is_link($file)) {
                continue;
            }

            $relative = $month . '/' . $entry;
            $row = $this->db->fetchOne(
                'SELECT id FROM transaction_photos WHERE path = ? LOCK IN SHARE MODE',
                [$relative]
            );
            if ($row === null) {
                @unlink($file);
            }
        }
    }

    private function logSweepFailure(\Throwable $e): void
    {
        if (!defined('FC_ROOT')) {
            return;
        }
        $line = gmdate('c') . ' penalty-sweep: ' . $e->getMessage() . PHP_EOL;
        @file_put_contents(FC_ROOT . '/storage/logs/app.log', $line, FILE_APPEND);
    }

    // --------------------------------------------------------------------- lock

    /**
     * Advisory lock names are a flat namespace on the SERVER, and on shared
     * hosting one MariaDB server carries many customers' databases. A fixed
     * name would let one family's slow upload time out another family's
     * penalty, across account boundaries. Discriminated by our own schema.
     */
    public function lockName(): string
    {
        if ($this->lockName === null) {
            $row = $this->db->fetchOne('SELECT DATABASE() AS d');
            $schema = (string) ($row['d'] ?? 'unknown');
            $this->lockName = 'fc_penalty_photo_' . substr(sha1($schema), 0, 16);
        }

        return $this->lockName;
    }

    private function acquireLock(): void
    {
        // GET_LOCK is COUNTING: acquiring twice needs two RELEASE_LOCK calls,
        // so a nested acquire plus one release leaves a hidden hold that wedges
        // every later writer on this connection.
        if ($this->holdsLock) {
            throw new \LogicException('The penalty photo lock is already held by this service.');
        }

        $row = $this->db->fetchOne('SELECT GET_LOCK(?, ?) AS ok', [$this->lockName(), self::LOCK_TIMEOUT]);
        $ok = $row['ok'] ?? null;

        // Fail CLOSED: a mutual-exclusion primitive that continues when
        // acquisition fails is decorative (LESSONS 2026-08-10 round 5).
        if ($ok === null || (int) $ok !== 1) {
            throw new \RuntimeException('Could not acquire the penalty photo lock.');
        }

        $this->holdsLock = true;
    }

    private function tryLock(): bool
    {
        if ($this->holdsLock) {
            return true;
        }

        $row = $this->db->fetchOne('SELECT GET_LOCK(?, 0) AS ok', [$this->lockName()]);
        if (($row['ok'] ?? null) === null || (int) $row['ok'] !== 1) {
            return false;
        }

        $this->holdsLock = true;

        return true;
    }

    private function releaseLock(): void
    {
        if (!$this->holdsLock) {
            return;
        }

        // Never depend on connection teardown: release explicitly, and let
        // connection death be the backstop only.
        try {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS r', [$this->lockName()]);
        } catch (\Throwable) {
            // The lock dies with the connection; nothing else to do.
        }

        $this->holdsLock = false;
    }
}
