<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

use PDO;
use Throwable;

/**
 * Post-update file-mode self-heal (plan §5 of the 2026-08-10 updater-modes spec).
 *
 * WHY THIS EXISTS. `update.php` is deliberately never part of the swapped set —
 * the running executor must not be replaced mid-run — so the release's copy is
 * installed LAST, after the commit point. That means the update which carries a
 * fix to the updater is itself driven by the OLD executor. When the fix is
 * "stop shipping 0750 directories", the very next update still ships them and
 * the site loses every stylesheet again. Verified against the real e2e updater,
 * not assumed. The product's users have no shell (INV-003), so the new code has
 * to repair the tree the old code just laid down.
 *
 * DESIGN — no "already healed" state, ever. Four successive marker designs were
 * refuted in review (stale after rollback → TOCTOU on the VERSION inode → ABA
 * across the walk → a path-based final chmod that can land on a swapped-in
 * tree). Every one of those failures was the same thing: a stored claim about
 * the past going stale. So nothing is stored. Instead:
 *
 *   - the fast path PROBES the real property — is `public-assets/` world
 *     traversable — which is exactly what broke in production;
 *   - the walk sets that bit LAST, so it is a COMPLETION WITNESS, not a sample:
 *     an interrupted walk leaves it unset and the next request redoes the work;
 *   - concurrency with the swap is EXCLUDED rather than out-raced, by joining
 *     the updater's own writer drain (`ops_state.write_locked` … FOR UPDATE).
 *     A walking healer holds that row, so `fc_gate_drain()` waits for it; a
 *     healer arriving after `fc_gate(true)` sees the lock and stands down.
 *
 * It never touches `config/` or `storage/`, so `config.php` (0640) and the 0600
 * secrets keep their modes.
 */
final class FileModeHeal
{
    public const OK = 'ok';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** Seconds a failed attempt suppresses the next walk (bounds CPU + log). */
    private const COOLDOWN = 60;

    /** Directories healed in full. `public-assets` is handled separately, last. */
    private const DIRS = ['app', 'views', 'lang'];

    /** Root files that Apache must be able to read. */
    private const FILES = ['index.php', '.htaccess', 'sw.js', 'offline.html', 'VERSION'];

    /** The witness: healed last, probed first. */
    private const WITNESS = 'public-assets';

    /**
     * @param callable():?PDO $pdoFactory Lazy — only called when a walk is actually needed,
     *                                    so the steady state never touches the database.
     */
    public static function ensure(string $root, string $stateDir, ?callable $pdoFactory): string
    {
        // Fast path: one stat(). Sound because the witness is set last.
        if (self::isServable($root . '/' . self::WITNESS)) {
            return self::OK;
        }

        // An ops operation (update swap, restore) owns the tree. Cheap check that
        // keeps the common case off the database; the real guarantee is the gate
        // below. Observation only — never a mutation, so INV-005 is untouched.
        if (is_dir($root . '/storage/ops.lock')) {
            return self::SKIPPED;
        }

        $retry = $stateDir . '/file-modes.retry';
        if (is_file($retry) && (time() - (int) @filemtime($retry)) < self::COOLDOWN) {
            return self::FAILED;
        }

        if (!is_dir($stateDir) && !@mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
            return self::SKIPPED;
        }

        $lock = @fopen($stateDir . '/file-modes.lock', 'c');
        if ($lock === false) {
            return self::SKIPPED;
        }
        // NON-blocking on purpose: a blocking lock would queue every concurrent
        // request behind one healer. Losing the race means someone else is on it.
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return self::SKIPPED;
        }

        try {
            // Another healer may have finished while we were taking the lock.
            if (self::isServable($root . '/' . self::WITNESS)) {
                return self::OK;
            }

            return self::healUnderWriteGate($root, $retry, $pdoFactory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Join the updater's writer drain for the whole walk. Holding the ops_state
     * row means fc_gate_drain() blocks on us instead of swapping the tree out
     * from under the walk; seeing write_locked = 1 means we stand down.
     *
     * No database (or not installed) is SKIPPED, never FAILED: a file-mode
     * problem must not escalate into an outage.
     */
    private static function healUnderWriteGate(string $root, string $retry, ?callable $pdoFactory): string
    {
        $pdo = null;
        if ($pdoFactory !== null) {
            try {
                $pdo = $pdoFactory();
            } catch (Throwable) {
                return self::SKIPPED;
            }
        }

        if (!$pdo instanceof PDO) {
            // Unit tests and pre-install requests: no gate to join, no swap possible.
            return self::finish(self::walk($root), $retry);
        }

        try {
            $pdo->beginTransaction();
            $row = $pdo->query('SELECT write_locked FROM ops_state WHERE id = 1 FOR UPDATE')->fetch();
            if ((int) ($row['write_locked'] ?? 0) === 1) {
                $pdo->rollBack();

                return self::SKIPPED;
            }
            $healed = self::walk($root);
            $pdo->commit();
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::SKIPPED;
        }

        return self::finish($healed, $retry);
    }

    private static function finish(bool $healed, string $retry): string
    {
        if ($healed) {
            @unlink($retry);

            return self::OK;
        }
        @touch($retry);

        return self::FAILED;
    }

    /**
     * Everything except the witness first; the witness only if all of it worked.
     *
     * @return bool true only when every chmod succeeded
     */
    private static function walk(string $root): bool
    {
        // A symlinked witness is never a state we create, and following it would
        // let `children()` (which resolves through is_dir/scandir) recurse into
        // whatever it points at — including config/ and storage/, whose 0640/0600
        // secrets would be chmodded to 0644. Refuse and report failure rather
        // than heal something outside the installation.
        if (is_link($root . '/' . self::WITNESS)) {
            return false;
        }

        $ok = true;
        foreach (self::DIRS as $dir) {
            $ok = self::chmodTree($root . '/' . $dir) && $ok;
        }
        // The witness directory's CONTENTS, but not the directory itself.
        foreach (self::children($root . '/' . self::WITNESS) as $child) {
            $ok = self::chmodTree($child) && $ok;
        }
        foreach (self::FILES as $file) {
            $ok = self::chmodPath($root . '/' . $file, 0644) && $ok;
        }
        if (!$ok) {
            return false; // leave the witness unset — an interrupted walk must be redone
        }

        return self::chmodPath($root . '/' . self::WITNESS, 0755);
    }

    /** @return list<string> */
    private static function children(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $out[] = $dir . '/' . $entry;
            }
        }

        return $out;
    }

    /** Directories 0755, files 0644. Missing entries are fine (a release may retire one). */
    private static function chmodTree(string $path): bool
    {
        if (is_link($path) || !file_exists($path)) {
            return true;
        }
        if (!is_dir($path)) {
            return self::chmodPath($path, 0644);
        }
        $ok = true;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue; // chmod() follows symlinks — never chase one out of the tree
            }
            $ok = self::chmodPath($item->getPathname(), $item->isDir() ? 0755 : 0644) && $ok;
        }

        return self::chmodPath($path, 0755) && $ok;
    }

    private static function chmodPath(string $path, int $mode): bool
    {
        if (is_link($path) || !file_exists($path)) {
            return true;
        }
        if ((fileperms($path) & 0777) === $mode) {
            return true; // already correct — chmod() would be a wasted syscall
        }

        return (bool) @chmod($path, $mode);
    }

    /**
     * World needs r-x on the directory for a static file server to serve through it.
     *
     * A symlink is never servable for our purposes: the fast path must not accept
     * one (it would mask the symlinked-witness case that walk() refuses), so both
     * ends agree that a symlinked public-assets is a fault, not a healthy state.
     */
    private static function isServable(string $dir): bool
    {
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            return false;
        }
        $perms = @fileperms($dir);

        return $perms !== false && ($perms & 0005) === 0005;
    }
}
