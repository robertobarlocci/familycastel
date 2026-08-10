<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Parent;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\AuditService;
use FamilyCastel\Domain\BackupService;
use FamilyCastel\Domain\SettingsService;
use FamilyCastel\Domain\UpdateChecker;
use FamilyCastel\Install\SystemCheck;

/** Parent ops area: updates, backups, restore, system status, diagnostics. */
final class OpsController
{
    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    private function backups(): BackupService
    {
        return new BackupService(
            $this->db,
            backupsDir: FC_ROOT . '/storage/backups',
            configFile: FC_ROOT . '/config/config.php',
            uploadsDir: FC_ROOT . '/storage/uploads',
        );
    }

    // ------------------------------------------------------------ updates

    public function updates(): string
    {
        $checker = new UpdateChecker(FC_ROOT . '/storage/cache');
        $latest = $checker->latest();
        $journal = $this->journal();
        $recordedRow = $this->db->fetchOne('SELECT `value` FROM settings WHERE `key` = ?', ['app.version']);
        $recorded = $recordedRow !== null ? (string) json_decode((string) $recordedRow['value'], true) : FC_VERSION;

        return $this->view->render('parent/ops/updates', [
            'manualPending' => $recorded !== '' && $recorded !== FC_VERSION,
            'recordedVersion' => $recorded,
            'current' => FC_VERSION,
            'latest' => $latest,
            'updateAvailable' => $latest !== null && version_compare($latest['version'], FC_VERSION, '>'),
            'journal' => $journal,
            'history' => $this->db->fetchAll('SELECT * FROM update_history ORDER BY id DESC LIMIT 10'),
        ], 'layouts/parent');
    }

    public function checkNow(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            (new UpdateChecker(FC_ROOT . '/storage/cache'))->latest(force: true);
            Session::flash('success', t('ops.checked'));
        }

        return $this->redirect('/parent/settings/updates');
    }

    /** Mint the updater token, write the target journal, hand over to update.php. */
    public function startUpdate(array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/settings/updates');
        }

        $latest = (new UpdateChecker(FC_ROOT . '/storage/cache'))->latest();
        if ($latest === null || !version_compare($latest['version'], FC_VERSION, '>')) {
            Session::flash('error', t('ops.no_update'));

            return $this->redirect('/parent/settings/updates');
        }

        $dir = FC_ROOT . '/storage/updates';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            Session::flash('error', t('ops.storage_error'));

            return $this->redirect('/parent/settings/updates');
        }

        // Serialize BEFORE touching token/journal: a second start while a run
        // is active must never overwrite the active run's state. The lock is
        // handed over to update.php (owner names this run_id) and released by
        // its finish/abort/rollback paths.
        $runId = bin2hex(random_bytes(8));
        if (!$this->acquireUpdaterLock($runId)) {
            Session::flash('error', t('ops.locked'));

            return $this->redirect('/parent/settings/updates');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $journal = [
            'run_id' => $runId,
            'step' => 'preflight',
            'status' => 'prepared',
            'started_at' => time(),
            'heartbeat' => time(),
            'from_version' => FC_VERSION,
            'target' => $latest,
            'log' => ['Prepared by parent #' . Auth::parentId()],
        ];
        if (!$this->writeUpdaterState($dir, $token, $journal)) {
            $this->releaseOpsLock();
            Session::flash('error', t('ops.storage_error'));

            return $this->redirect('/parent/settings/updates');
        }

        (new AuditService($this->db))->log('user', Auth::parentId(), 'update.started', details: ['to' => $latest['version']], ip: $ip);

        setcookie('fc_update_token', $token, [
            'expires' => time() + 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => Session::isHttps(),
        ]);
        header('Location: ' . url('/update.php'), true, 302);

        return '';
    }

    /** Manual-FTP path: files already replaced — verify + backup + migrate. */
    public function startManualUpdate(array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/settings/updates');
        }

        $dir = FC_ROOT . '/storage/updates';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            Session::flash('error', t('ops.storage_error'));

            return $this->redirect('/parent/settings/updates');
        }

        $runId = bin2hex(random_bytes(8));
        if (!$this->acquireUpdaterLock($runId)) {
            Session::flash('error', t('ops.locked'));

            return $this->redirect('/parent/settings/updates');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $journal = [
            'run_id' => $runId,
            'chain' => 'manual',
            'step' => 'manual_verify',
            'status' => 'prepared',
            'started_at' => time(),
            'heartbeat' => time(),
            'from_version' => 'manual',
            'target' => ['version' => FC_VERSION],
            'log' => ['Manual update prepared by parent #' . Auth::parentId()],
        ];
        if (!$this->writeUpdaterState($dir, $token, $journal)) {
            $this->releaseOpsLock();
            Session::flash('error', t('ops.storage_error'));

            return $this->redirect('/parent/settings/updates');
        }

        (new AuditService($this->db))->log('user', Auth::parentId(), 'update.manual_started', ip: $ip);

        setcookie('fc_update_token', $token, [
            'expires' => time() + 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => Session::isHttps(),
        ]);
        header('Location: ' . url('/update.php'), true, 302);

        return '';
    }

    // ------------------------------------------------------------ backups

    public function backupsPage(): string
    {
        return $this->view->render('parent/ops/backups', [
            'backups' => $this->backups()->listFromFilesystem(),
        ], 'layouts/parent');
    }

    public function createBackup(array $post, string $ip): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            if (!$this->acquireOpsLock('backup')) {
                Session::flash('error', t('ops.locked'));

                return $this->redirect('/parent/settings/backups');
            }
            try {
                $info = $this->backups()->create('manual', Auth::parentId());
                (new AuditService($this->db))->log('user', Auth::parentId(), 'backup.created', details: ['id' => $info['id']], ip: $ip);
                Session::flash('success', t('ops.backup_created'));
            } catch (\Throwable $e) {
                \FamilyCastel\Core\ErrorHandler::log(FC_ROOT . '/storage/logs', $e);
                Session::flash('error', t('ops.backup_failed'));
            } finally {
                $this->releaseOpsLock();
            }
        }

        return $this->redirect('/parent/settings/backups');
    }

    public function downloadBackup(string $id): string
    {
        $backup = $this->findBackup($id);
        if ($backup === null) {
            return $this->redirect('/parent/settings/backups');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="family-castel-backup-' . $backup['id'] . '.zip"');
        header('Content-Length: ' . (string) filesize($backup['zip']));
        readfile($backup['zip']);
        exit;
    }

    public function deleteBackup(string $id, array $post, string $ip): string
    {
        $backup = $this->findBackup($id);
        if ($backup !== null && Csrf::validate($post['_csrf'] ?? null)
            && hash_equals('DELETE', strtoupper(trim((string) ($post['confirm'] ?? ''))))) {
            if (!$this->acquireOpsLock('backup-delete')) {
                Session::flash('error', t('ops.locked'));

                return $this->redirect('/parent/settings/backups');
            }
            $dir = dirname($backup['zip']);
            @unlink($backup['zip']);
            @unlink($dir . '/meta.json');
            @rmdir($dir);
            $this->backups()->syncHistory();
            $this->releaseOpsLock();
            (new AuditService($this->db))->log('user', Auth::parentId(), 'backup.deleted', details: ['id' => $id], ip: $ip);
            Session::flash('success', t('ops.backup_deleted'));
        } else {
            Session::flash('error', t('ops.confirm_word_wrong', ['word' => 'DELETE']));
        }

        return $this->redirect('/parent/settings/backups');
    }

    /**
     * Restore = disaster recovery (plan §10): typed confirmation, maintenance
     * + hard gate FIRST, retained emergency backup, exact DB replacement,
     * uploads restore, migrations to current code, re-sync.
     */
    public function restore(string $id, array $post, string $ip): string
    {
        $backup = $this->findBackup($id);
        if ($backup === null || !Csrf::validate($post['_csrf'] ?? null)) {
            return $this->redirect('/parent/settings/backups');
        }
        if (!hash_equals('RESTORE', strtoupper(trim((string) ($post['confirm'] ?? ''))))) {
            Session::flash('error', t('ops.confirm_word_wrong', ['word' => 'RESTORE']));

            return $this->redirect('/parent/settings/backups');
        }

        if (!$this->acquireOpsLock('restore')) {
            Session::flash('error', t('ops.locked'));

            return $this->redirect('/parent/settings/backups');
        }

        $maintenanceFlag = FC_ROOT . '/storage/maintenance.flag';
        $emergency = null;
        $destructiveStarted = false;
        $uploadsLive = FC_ROOT . '/storage/uploads';
        $uploadsTmp = FC_ROOT . '/storage/uploads.restore-' . bin2hex(random_bytes(4));
        $uploadsOld = FC_ROOT . '/storage/uploads.old-' . bin2hex(random_bytes(4));
        $uploadsState = 'untouched'; // 'untouched' | 'aside' | 'swapped'
        try {
            // 1. Quiesce: maintenance + hard gate (drain via exclusive acquisition).
            file_put_contents($maintenanceFlag, json_encode(['since' => gmdate('c'), 'reason' => 'restore']));
            $this->db->execute('UPDATE ops_state SET write_locked = 1, updated_at = UTC_TIMESTAMP() WHERE id = 1');
            $this->db->transaction(function (Db $db): void {
                $db->fetchOne('SELECT write_locked FROM ops_state WHERE id = 1 FOR UPDATE');
            });

            // 2. Retained emergency backup of NOW (INV-001 — nothing is ever lost).
            $backups = $this->backups();
            $emergency = $backups->create('emergency', Auth::parentId());

            // 3. Uploads are prepared OFF-LINE first (write-checked) but
            // activated only AFTER db + migrations + health all succeeded —
            // otherwise a late failure would revert the database while the
            // target backup's uploads stayed live (mismatched state).
            $backups->extractUploads($backup['zip'], $uploadsTmp);

            // 4. Exact DB replacement (the destructive point).
            $destructiveStarted = true;
            $backups->restoreDatabase($backup['zip']);

            // 5. Migrations up to the CURRENT code + health probe.
            (new \FamilyCastel\Database\Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
            $this->db->fetchOne('SELECT 1');

            // 5b. The snapshot brought its own remember_tokens rows with it. If
            // it predates a revocation — including one triggered by theft
            // detection — those credentials are now live again. Revoke every
            // one: each device signs in once more, which is a small price next
            // to silently reinstating a stolen cookie.
            (new \FamilyCastel\Domain\RememberService($this->db))->revokeAll();

            // 6. Uploads swap — the old set is KEPT until success for revert.
            // NO inline un-checked revert here: any failure leaves a tracked
            // state ('aside'/'swapped') that the catch path reverts CHECKED.
            if (is_dir($uploadsLive)) {
                if (!rename($uploadsLive, $uploadsOld)) {
                    throw new \RuntimeException('Cannot set aside current uploads.');
                }
                $uploadsState = 'aside';
            }
            if (!rename($uploadsTmp, $uploadsLive)) {
                throw new \RuntimeException('Cannot activate restored uploads.');
            }
            $uploadsState = 'swapped';

            // 7. Reopen.
            $this->db->execute('UPDATE ops_state SET write_locked = 0, updated_at = UTC_TIMESTAMP() WHERE id = 1');
            @unlink($maintenanceFlag);

            // 8. Post-commit cleanup + bookkeeping: strictly non-fatal.
            try {
                $this->removeDirRecursive($uploadsOld);
                (new AuditService($this->db))->log('user', Auth::parentId(), 'backup.restored', details: ['id' => $id], ip: $ip);
            } catch (\Throwable $post) {
                \FamilyCastel\Core\ErrorHandler::log(FC_ROOT . '/storage/logs', $post);
            }
            Session::flash('success', t('ops.restored'));
        } catch (\Throwable $e) {
            \FamilyCastel\Core\ErrorHandler::log(FC_ROOT . '/storage/logs', $e);

            if ($destructiveStarted && $emergency !== null) {
                // A partial restore must NEVER go live: put the emergency
                // snapshot back — uploads first (if they were swapped), then
                // the database. Only if ALL of that succeeds may writes reopen.
                try {
                    if ($uploadsState === 'swapped') {
                        $junk = FC_ROOT . '/storage/uploads.junk-' . bin2hex(random_bytes(4));
                        if (is_dir($uploadsLive) && !@rename($uploadsLive, $junk)) {
                            throw new \RuntimeException('Cannot revert restored uploads.');
                        }
                        if (is_dir($uploadsOld) && !@rename($uploadsOld, $uploadsLive)) {
                            throw new \RuntimeException('Cannot put previous uploads back.');
                        }
                        $this->removeDirRecursive($junk);
                    } elseif ($uploadsState === 'aside') {
                        // Live was moved aside but nothing replaced it yet.
                        if (is_dir($uploadsOld) && !@rename($uploadsOld, $uploadsLive)) {
                            throw new \RuntimeException('Cannot put previous uploads back.');
                        }
                    }
                    $this->backups()->restoreDatabase($emergency['zip']);
                    $this->db->execute('UPDATE ops_state SET write_locked = 0 WHERE id = 1');
                    @unlink($maintenanceFlag);
                    Session::flash('error', t('ops.restore_failed', ['message' => $e->getMessage()]) . ' ' . t('ops.restore_reverted'));
                } catch (\Throwable $second) {
                    \FamilyCastel\Core\ErrorHandler::log(FC_ROOT . '/storage/logs', $second);
                    // Fail CLOSED: maintenance + gate stay on until a human intervenes.
                    Session::flash('error', t('ops.restore_failed_closed', ['backup' => $emergency['id']]));
                }
            } else {
                // Nothing destructive happened yet — safe to reopen.
                try {
                    $this->db->execute('UPDATE ops_state SET write_locked = 0 WHERE id = 1');
                } catch (\Throwable) {
                }
                @unlink($maintenanceFlag);
                Session::flash('error', t('ops.restore_failed', ['message' => $e->getMessage()]));
            }
        } finally {
            if (is_dir($uploadsTmp)) {
                try {
                    $this->removeDirRecursive($uploadsTmp);
                } catch (\Throwable) {
                }
            }
            $this->releaseOpsLock();
        }

        return $this->redirect('/parent/settings/backups');
    }

    private function acquireOpsLock(string $owner): bool
    {
        return $this->opsLockAcquire($owner . ':' . gmdate('c'));
    }

    /** Handover variant: the owner token names the updater RUN — update.php
     *  recognizes it as its own lock and releases it when the run ends. */
    private function acquireUpdaterLock(string $runId): bool
    {
        return $this->opsLockAcquire('updater:' . $runId);
    }

    /** Owner token this request wrote — release only removes OUR lock. */
    private ?string $opsLockToken = null;

    /**
     * EVERY mutation of ops.lock — fast acquire, stale reclaim and release —
     * runs inside ONE flock mutex on storage/ops.lock.mutex (the SAME file
     * update.php uses). That closes the delayed-actor/ABA class: no actor
     * can create, rename or delete the lock based on an observation made
     * outside its own critical section. flock dies with the process, so a
     * crash cannot wedge the mutex; the ops.lock DIRECTORY still carries the
     * cross-request ownership that flock cannot.
     */
    private function lockMutex(\Closure $fn): bool
    {
        $fh = @fopen(FC_ROOT . '/storage/ops.lock.mutex', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if ($fh !== false) {
                fclose($fh);
            }

            return false; // FAIL CLOSED: no mutex → no lock mutation
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function opsLockAcquire(string $ownerToken): bool
    {
        $dir = FC_ROOT . '/storage/ops.lock';

        return $this->lockMutex(function () use ($dir, $ownerToken): bool {
            if (@mkdir($dir)) {
                @file_put_contents($dir . '/owner', $ownerToken);
                $this->opsLockToken = $ownerToken;
                $this->pruneLockGraveyards();

                return true;
            }

            // Stale reclaim — observation and action inside the SAME mutex hold.
            $owner = (string) @file_get_contents($dir . '/owner');
            $identity = $owner !== '' ? (int) @filemtime($dir . '/owner') : (int) @filemtime($dir);
            if (!$this->lockIsStale($owner, $identity)) {
                return false;
            }
            $graveyard = $dir . '.stale-' . substr(sha1($owner . '|' . $identity), 0, 12)
                . '-' . bin2hex(random_bytes(3));
            if (!@rename($dir, $graveyard)) {
                return false;
            }
            @file_put_contents($graveyard . '/reclaimed', gmdate('c'));
            @touch($graveyard); // age from RECLAIM time for the pruner
            if (@mkdir($dir)) {
                @file_put_contents($dir . '/owner', $ownerToken);
                $this->opsLockToken = $ownerToken;

                return true;
            }

            return false;
        });
    }

    /**
     * Staleness from ONE observation:
     *  - ownerless (crash between mkdir and owner write) → dir mtime > 15min;
     *  - updater-owned + journal DESCRIBES this run → heartbeat > 30min;
     *  - updater-owned, journal names another run (journal write pending)
     *    → owner mtime > 30min;
     *  - backup/restore-owned → owner mtime > 2h (requests die with PHP).
     */
    private function lockIsStale(string $owner, int $identity): bool
    {
        if ($owner === '') {
            return $identity > 0 && $identity < time() - 900;
        }
        if (str_starts_with($owner, 'updater:')) {
            $journal = json_decode((string) @file_get_contents(FC_ROOT . '/storage/updates/state.json'), true);
            $journalRun = is_array($journal) ? (string) ($journal['run_id'] ?? '') : '';
            if ('updater:' . $journalRun === $owner) {
                return (int) ($journal['heartbeat'] ?? 0) < time() - 1800;
            }

            return $identity > 0 && $identity < time() - 1800;
        }

        return $identity > 0 && $identity < time() - 7200;
    }

    /** Graveyards are kept at reclaim time (immediate deletion would re-open
     *  the race) and pruned only once unambiguously historical. */
    private function pruneLockGraveyards(): void
    {
        foreach (glob(FC_ROOT . '/storage/ops.lock.stale-*') ?: [] as $old) {
            if ((int) @filemtime($old) < time() - 3600) {
                $this->removeDirRecursive($old);
            }
        }
    }

    /**
     * Token + journal written via tmp+rename — never a torn state file. The
     * journal replacement happens under the SAME flock fence update.php uses
     * for its check+write critical section, so installing a new run's journal
     * and a superseded handler's write can never interleave.
     */
    private function writeUpdaterState(string $dir, string $token, array $journal): bool
    {
        $tokenTmp = $dir . '/auth-token.tmp';
        if (@file_put_contents($tokenTmp, $token, LOCK_EX) === false || !@rename($tokenTmp, $dir . '/auth-token')) {
            return false;
        }
        @chmod($dir . '/auth-token', 0600);

        // FAIL CLOSED: no successfully acquired fence → no journal install.
        $fence = @fopen($dir . '/state.lock', 'c');
        if ($fence === false) {
            return false;
        }
        if (!flock($fence, LOCK_EX)) {
            fclose($fence);

            return false;
        }
        try {
            $stateTmp = $dir . '/state.json.tmp';
            $json = json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

            return @file_put_contents($stateTmp, $json, LOCK_EX) !== false && @rename($stateTmp, $dir . '/state.json');
        } finally {
            flock($fence, LOCK_UN);
            fclose($fence);
        }
    }

    private function releaseOpsLock(): void
    {
        if ($this->opsLockToken === null) {
            return;
        }
        $dir = FC_ROOT . '/storage/ops.lock';
        $token = $this->opsLockToken;
        $released = $this->lockMutex(static function () use ($dir, $token): bool {
            $owner = (string) @file_get_contents($dir . '/owner');
            if ($owner !== $token) {
                // Not provably ours — reclaimed by a successor, or ownerless
                // (someone else's crash window; stale reclaim handles it).
                return false;
            }
            @unlink($dir . '/owner');
            @rmdir($dir);

            return true;
        });
        if ($released) {
            $this->opsLockToken = null;
        }
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------ status & diagnostics

    public function status(): string
    {
        $gate = $this->db->fetchOne('SELECT write_locked FROM ops_state WHERE id = 1');
        $dbVersion = $this->db->fetchOne('SELECT VERSION() AS v');
        $migrator = new \FamilyCastel\Database\Migrator($this->db, FC_ROOT . '/app/Database/Migrations');
        $backups = $this->backups()->listFromFilesystem();

        return $this->view->render('parent/ops/status', [
            'appVersion' => FC_VERSION,
            'phpVersion' => PHP_VERSION,
            'dbVersion' => (string) ($dbVersion['v'] ?? '?'),
            'schemaVersion' => $migrator->currentVersion() ?? '—',
            'pendingMigrations' => count($migrator->pending()),
            'checks' => (new SystemCheck(FC_ROOT))->run(),
            'https' => Session::isHttps(),
            'gateLocked' => (int) ($gate['write_locked'] ?? 0) === 1,
            'maintenance' => is_file(FC_ROOT . '/storage/maintenance.flag'),
            'lastBackup' => $backups[0] ?? null,
            'settings' => [
                'locale' => (new SettingsService($this->db))->get('app.locale', 'de'),
                'timezone' => (new SettingsService($this->db))->get('app.timezone', 'UTC'),
            ],
        ], 'layouts/parent');
    }

    public function diagnostics(): string
    {
        $lines = [
            'Family Castel diagnostic report',
            'Generated: ' . gmdate('c'),
            '',
            'App version:    ' . FC_VERSION,
            'PHP version:    ' . PHP_VERSION,
            'SAPI:           ' . PHP_SAPI,
            'OS:             ' . php_uname('s') . ' ' . php_uname('r'),
        ];
        try {
            $lines[] = 'DB version:     ' . ($this->db->fetchOne('SELECT VERSION() AS v')['v'] ?? '?');
            $migrator = new \FamilyCastel\Database\Migrator($this->db, FC_ROOT . '/app/Database/Migrations');
            $lines[] = 'Schema version: ' . ($migrator->currentVersion() ?? '—');
        } catch (\Throwable $e) {
            $lines[] = 'DB:             ERROR ' . $e->getMessage();
        }
        $lines[] = '';
        $lines[] = 'Extensions: ' . implode(', ', array_filter(
            ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'openssl', 'curl', 'zip', 'sodium', 'gd'],
            extension_loaded(...)
        ));
        $lines[] = '';
        foreach ((new SystemCheck(FC_ROOT))->run() as $check) {
            $lines[] = sprintf('[%s] %s — %s', strtoupper($check['level']), $check['label'], $check['detail']);
        }
        $lines[] = '';
        $lines[] = '--- last app log lines (sanitized) ---';
        $logFile = FC_ROOT . '/storage/logs/app.log';
        if (is_file($logFile)) {
            $tail = array_slice(file($logFile) ?: [], -40);
            foreach ($tail as $line) {
                // Defense-in-depth: strip anything that looks like a secret.
                $lines[] = preg_replace('/(password|secret|token)\s*[=:]?\s*\S*/i', '$1=[redacted]', rtrim($line));
            }
        } else {
            $lines[] = '(no log entries)';
        }

        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="family-castel-diagnostics.txt"');

        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------ internals

    /** @return array{id: string, zip: string, kind: string}|null */
    private function findBackup(string $id): ?array
    {
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[a-z_]+-[0-9a-f]{6}$/', $id) !== 1) {
            return null;
        }
        foreach ($this->backups()->listFromFilesystem() as $backup) {
            if ($backup['id'] === $id) {
                return $backup;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function journal(): ?array
    {
        $file = FC_ROOT . '/storage/updates/state.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
