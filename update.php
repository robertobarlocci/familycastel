<?php

/**
 * Family Castel — standalone update executor (plan §9).
 *
 * This file is NEVER part of the swapped set: together with config/ and
 * storage/ it survives every update, so there is always executable code able
 * to resume or roll back — no matter where an interruption happens.
 *
 * Design rules:
 *  - Steps before the swap may use the (old) app classes — they only READ
 *    app files. The SWAP itself and everything the rollback path needs is
 *    implemented right here with plain filesystem/PDO operations.
 *  - Every step runs in its own POST within a time budget; the journal
 *    (storage/updates/state.json) makes each step idempotent/resumable.
 *  - Auth: parent session flag + one-time updater token minted by the app.
 *  - The DB write gate (ops_state.write_locked) provides provable quiescence.
 *
 * The browser drives the steps sequentially via fetch() from this page.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('FC_UPDATE_ROOT', __DIR__);

// ---------------------------------------------------------------- helpers

/** @return array<string, mixed> */
function fc_config(): array
{
    $file = FC_UPDATE_ROOT . '/config/config.php';
    if (!is_file($file)) {
        fc_fail('Not installed.');
    }
    $config = require $file;

    return is_array($config) ? $config : [];
}

function fc_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db = fc_config()['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int) ($db['port'] ?? 3306),
            $db['name'] ?? ''
        );
        $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

/** @return array<string, mixed> */
function fc_journal_read(): array
{
    $file = FC_UPDATE_ROOT . '/storage/updates/state.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($data) ? $data : [];
}

/** @param array<string, mixed> $journal */
function fc_journal_write(array $journal): void
{
    $journal['heartbeat'] = time();
    $dir = FC_UPDATE_ROOT . '/storage/updates';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    // Fencing under flock: check + replace are ONE critical section, so a
    // successor run cannot slip its journal in between a stale check and this
    // write. OpsController takes the same flock when installing a new run's
    // journal, making the fence mutually exclusive. FAIL CLOSED: without a
    // successfully acquired fence we must not write at all.
    $fence = fopen($dir . '/state.lock', 'c');
    if ($fence === false || !flock($fence, LOCK_EX)) {
        if ($fence !== false) {
            fclose($fence);
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Cannot acquire the journal fence — nothing was changed.']);
        exit;
    }
    try {
        if (defined('FC_REQ_RUN')) {
            $onDisk = fc_journal_read();
            if ((string) ($onDisk['run_id'] ?? '') !== FC_REQ_RUN) {
                http_response_code(409);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Superseded by a newer update run.']);
                exit;
            }
        }
        $tmp = $dir . '/state.json.tmp';
        file_put_contents($tmp, json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, $dir . '/state.json');
    } finally {
        flock($fence, LOCK_UN);
        fclose($fence);
    }
}

function fc_log(string $message): void
{
    $journal = fc_journal_read();
    $journal['log'][] = '[' . gmdate('H:i:s') . '] ' . $message;
    fc_journal_write($journal);
}

/** This run's identity — minted by OpsController at start (empty for the
 *  bare test driver, which never has a competing run). */
function fc_run_id(): string
{
    return (string) (fc_journal_read()['run_id'] ?? '');
}

/**
 * EVERY mutation of ops.lock — fast acquire, re-entry check, stale reclaim
 * and release — runs inside ONE flock mutex (fc_lock_mutex). That closes the
 * whole delayed-actor/ABA class: no actor can create, rename or delete the
 * lock based on an observation made outside its own critical section, so a
 * freshly (re)created successor lock can never be renamed away by a
 * reclaimer that decided earlier. The flock dies with the process (a crash
 * cannot wedge it); the ops.lock DIRECTORY still carries the cross-request
 * ownership that flock cannot.
 */
function fc_lock_mutex(Closure $fn): bool
{
    $fh = @fopen(FC_UPDATE_ROOT . '/storage/ops.lock.mutex', 'c');
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

/** Global recovery lock (shared semantics with backup/restore flows). */
function fc_ops_lock_acquire(): bool
{
    $dir = FC_UPDATE_ROOT . '/storage/ops.lock';
    $mine = 'updater:' . fc_run_id();

    return fc_lock_mutex(static function () use ($dir, $mine): bool {
        if (@mkdir($dir)) {
            file_put_contents($dir . '/owner', $mine);
            fc_lock_graveyard_prune();

            return true;
        }

        // Re-entry (each step is its own request): the lock is ours iff the
        // owner token names THIS run — a fresh concurrent run has a different
        // run_id and is refused instead of silently sharing the lock.
        $owner = (string) @file_get_contents($dir . '/owner');
        if ($owner === $mine) {
            return true;
        }

        // Stale reclaim — observation and action inside the SAME mutex hold.
        $identity = $owner !== '' ? (int) @filemtime($dir . '/owner') : (int) @filemtime($dir);
        if (!fc_lock_is_stale($owner, $identity)) {
            return false;
        }
        $graveyard = $dir . '.stale-' . substr(sha1($owner . '|' . $identity), 0, 12)
            . '-' . bin2hex(random_bytes(3));
        if (!@rename($dir, $graveyard)) {
            return false;
        }
        @file_put_contents($graveyard . '/reclaimed', gmdate('c')); // debugging aid
        @touch($graveyard); // age from RECLAIM time for the pruner
        if (@mkdir($dir)) {
            file_put_contents($dir . '/owner', $mine);

            return true;
        }

        return false;
    });
}

/**
 * A lock is stale only when the evidence is about THIS observation:
 *  - updater-owned + the journal DESCRIBES this run (run_id matches the
 *    owner token) → judge by journal heartbeat (long steps are normal);
 *  - updater-owned but the journal names a DIFFERENT run (e.g. the journal
 *    write after lock creation has not happened yet) → judge by the observed
 *    owner mtime;
 *  - ownerless (crash between mkdir and owner write) → judge by the observed
 *    dir mtime.
 * $identity is the SAME mtime later hashed into the graveyard name — one
 * observation feeds both decisions (no re-read window).
 */
function fc_lock_is_stale(string $owner, int $identity): bool
{
    if ($owner === '') {
        return $identity > 0 && $identity < time() - 900;
    }
    if (!str_starts_with($owner, 'updater:')) {
        return false; // backup/restore locks are never reclaimed by the updater
    }
    $journal = fc_journal_read();
    if ('updater:' . (string) ($journal['run_id'] ?? '') === $owner) {
        return (int) ($journal['heartbeat'] ?? 0) < time() - 1800;
    }

    return $identity > 0 && $identity < time() - 1800;
}

/**
 * Graveyarded stale locks are KEPT at reclaim time (deleting them immediately
 * would re-open the race for a delayed contender) and pruned only once they
 * are unambiguously historical.
 */
function fc_lock_graveyard_prune(): void
{
    foreach (glob(FC_UPDATE_ROOT . '/storage/ops.lock.stale-*') ?: [] as $old) {
        if ((int) @filemtime($old) < time() - 3600) {
            fc_rrmdir($old);
        }
    }
}

function fc_ops_lock_release(): void
{
    $dir = FC_UPDATE_ROOT . '/storage/ops.lock';
    $mine = 'updater:' . (defined('FC_REQ_RUN') ? FC_REQ_RUN : fc_run_id());
    fc_lock_mutex(static function () use ($dir, $mine): bool {
        $owner = (string) @file_get_contents($dir . '/owner');
        if ($owner !== $mine) {
            // Not provably ours (reclaimed by a successor, or ownerless — an
            // ownerless lock is someone ELSE's crash window, never ours to
            // free; the stale reclaim handles it). Never delete it.
            return false;
        }
        @unlink($dir . '/owner');
        @rmdir($dir);

        return true;
    });
}

function fc_maintenance(bool $on): void
{
    $flag = FC_UPDATE_ROOT . '/storage/maintenance.flag';
    if ($on) {
        file_put_contents($flag, json_encode(['since' => gmdate('c'), 'reason' => 'update']));
    } else {
        @unlink($flag);
    }
}

function fc_gate(bool $locked): void
{
    fc_pdo()->prepare('UPDATE ops_state SET write_locked = ?, updated_at = UTC_TIMESTAMP() WHERE id = 1')
        ->execute([$locked ? 1 : 0]);
}

/** Acquire the exclusive gate lock once — this IS the writer drain (plan §9 4b). */
function fc_gate_drain(): void
{
    $pdo = fc_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT write_locked FROM ops_state WHERE id = 1 FOR UPDATE')->fetch();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function fc_fail(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** @param array<string, mixed> $data */
function fc_ok(array $data = []): never
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + $data);
    exit;
}

function fc_auth(): void
{
    $tokenFile = FC_UPDATE_ROOT . '/storage/updates/auth-token';
    // Cookie-first (HttpOnly, set by the app — never in a URL); POST fallback
    // kept for the test driver.
    $provided = (string) ($_COOKIE['fc_update_token'] ?? $_POST['token'] ?? '');
    $expected = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        fc_fail('Updater token missing or invalid. Start the update from the Family Castel settings.', 403);
    }
}

/** The code entries an update swaps (everything else survives untouched). */
function fc_swap_entries(): array
{
    return ['index.php', '.htaccess', 'app', 'views', 'public-assets', 'lang', 'sw.js', 'offline.html', 'VERSION'];
}

/**
 * removed[] whitelist: only paths inside the swapped code entries may ever be
 * retired — a manifest can NEVER remove update.php, config/ or storage/.
 */
function fc_removed_allowed(string $path): bool
{
    if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
        return false;
    }
    $first = explode('/', $path, 2)[0];

    return in_array($first, fc_swap_entries(), true);
}

function fc_app_autoload(): void
{
    require_once FC_UPDATE_ROOT . '/app/Core/Autoloader.php';
    \FamilyCastel\Core\Autoloader::register(FC_UPDATE_ROOT . '/app');
    require_once FC_UPDATE_ROOT . '/app/Core/helpers.php';
    if (!defined('FC_ROOT')) {
        define('FC_ROOT', FC_UPDATE_ROOT);
    }
    if (!defined('FC_VERSION')) {
        define('FC_VERSION', trim((string) @file_get_contents(FC_UPDATE_ROOT . '/VERSION')) ?: '0.0.0');
    }
}

// ---------------------------------------------------------------- steps

/**
 * Manual FTP chain (plan §9): files were already replaced by the user.
 * Verify the uploaded code against its own release.json manifest — a partial
 * upload blocks everything — then continue with backup → migrate → health.
 */
function fc_step_manual_verify(): void
{
    if (!fc_ops_lock_acquire()) {
        fc_fail('Another recovery operation is running.', 409);
    }
    $manifestFile = FC_UPDATE_ROOT . '/release.json';
    $manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
    if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'Family Castel') {
        fc_abort('release.json missing — upload the complete release package first.');
    }
    foreach (($manifest['files'] ?? []) as $file => $sha) {
        $path = FC_UPDATE_ROOT . '/' . $file;
        if (!is_file($path) || !hash_equals((string) $sha, (string) hash_file('sha256', $path))) {
            fc_abort('Uploaded file failed verification (incomplete upload?): ' . $file);
        }
    }
    $removedValid = [];
    foreach (($manifest['removed'] ?? []) as $removed) {
        $removed = trim((string) $removed, '/');
        if (fc_removed_allowed($removed)) {
            $removedValid[] = $removed;
        }
    }
    $journal = fc_journal_read();
    // Removals are only APPLIED later — after maintenance + backup (migrate step).
    $journal['manifest'] = ['version' => $manifest['version'] ?? '', 'removed' => $removedValid];
    fc_journal_write($journal);
    fc_log('Manual upload verified (' . count($manifest['files'] ?? []) . ' files).');
    fc_advance('maintenance_on');
}

function fc_step_preflight(): void
{
    $journal = fc_journal_read();
    $target = (string) ($journal['target']['version'] ?? '');
    if ($target === '') {
        fc_fail('No update prepared. Start from the settings page.');
    }
    if (!fc_ops_lock_acquire()) {
        fc_fail('Another recovery operation is running.', 409);
    }

    $free = (float) @disk_free_space(FC_UPDATE_ROOT);
    $needed = 3 * (float) ($journal['target']['size'] ?? 50 * 1024 * 1024);
    if ($free > 0 && $free < $needed) {
        fc_fail('Not enough disk space for a safe update.');
    }
    foreach (array_merge(fc_swap_entries(), ['storage', 'config']) as $entry) {
        $path = FC_UPDATE_ROOT . '/' . $entry;
        if (file_exists($path) && !is_writable($path)) {
            fc_fail("Not writable: {$entry}");
        }
    }
    $probe = FC_UPDATE_ROOT . '/storage/updates/.write-probe';
    if (@file_put_contents($probe, 'x') === false) {
        fc_fail('storage/updates is not writable.');
    }
    @unlink($probe);

    fc_log('Preflight OK for v' . $target);
    fc_advance('download');
}

function fc_step_download(): void
{
    $journal = fc_journal_read();
    $zipUrl = (string) ($journal['target']['zip_url'] ?? '');
    $shaUrl = (string) ($journal['target']['sha256_url'] ?? '');
    if ($zipUrl === '' || $shaUrl === '') {
        fc_fail('Release URLs missing.');
    }

    $zipFile = FC_UPDATE_ROOT . '/storage/updates/release.zip';
    fc_download($zipUrl, $zipFile);
    $shaFile = FC_UPDATE_ROOT . '/storage/updates/release.zip.sha256';
    fc_download($shaUrl, $shaFile);

    fc_log('Downloaded ' . basename($zipUrl) . ' (' . filesize($zipFile) . ' bytes)');
    fc_advance('verify');
}

function fc_download(string $url, string $dest): void
{
    // Production: https only. file:// and plain http exist ONLY for the test
    // driver (FC_UPDATE_TEST env set by tests/e2e-updater.sh).
    $testMode = getenv('FC_UPDATE_TEST') === '1';
    $allowed = $testMode ? '#^(https?|file)://#' : '#^https://#';
    if (!preg_match($allowed, $url)) {
        fc_fail('Unsupported release URL (https required).');
    }
    $fh = fopen($dest, 'w');
    if ($fh === false) {
        fc_fail('Cannot write download target.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'FamilyCastel-Updater',
        CURLOPT_MAXFILESIZE => 512 * 1024 * 1024,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow): int {
            return $dlNow > 512 * 1024 * 1024 ? 1 : 0; // hard byte ceiling
        },
    ]);
    curl_setopt(
        $ch,
        CURLOPT_PROTOCOLS,
        $testMode ? (CURLPROTO_HTTPS | CURLPROTO_HTTP | CURLPROTO_FILE) : CURLPROTO_HTTPS
    );
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    $success = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($success !== true) {
        @unlink($dest);
        fc_fail('Download failed: ' . $error);
    }
}

function fc_step_verify(): void
{
    $zipFile = FC_UPDATE_ROOT . '/storage/updates/release.zip';
    $shaFile = FC_UPDATE_ROOT . '/storage/updates/release.zip.sha256';
    $expected = strtolower(strtok(trim((string) file_get_contents($shaFile)), " \t"));
    $actual = hash_file('sha256', $zipFile);
    if (!is_string($actual) || !preg_match('/^[0-9a-f]{64}$/', $expected) || !hash_equals($expected, $actual)) {
        fc_abort('Checksum mismatch — the downloaded package is not the released package.');
    }
    fc_log('SHA256 verified: ' . $actual);
    fc_advance('maintenance_on');
}

function fc_step_maintenance_on(): void
{
    fc_maintenance(true);
    fc_gate(true);
    fc_gate_drain(); // blocks until every in-flight writer finished
    fc_log('Maintenance on, write gate locked and drained.');
    fc_advance('backup');
}

function fc_after_backup(): string
{
    $journal = fc_journal_read();

    return ($journal['chain'] ?? 'github') === 'manual' ? 'migrate' : 'extract';
}

function fc_step_backup(): void
{
    fc_app_autoload();
    $config = fc_config();
    $db = \FamilyCastel\Core\Db::fromParams(
        host: (string) $config['db']['host'],
        port: (int) $config['db']['port'],
        name: (string) $config['db']['name'],
        user: (string) $config['db']['user'],
        password: (string) $config['db']['password'],
    );
    $backups = new \FamilyCastel\Domain\BackupService(
        $db,
        backupsDir: FC_UPDATE_ROOT . '/storage/backups',
        configFile: FC_UPDATE_ROOT . '/config/config.php',
        uploadsDir: FC_UPDATE_ROOT . '/storage/uploads',
    );
    $info = $backups->create('pre_update', createdBy: null);

    $journal = fc_journal_read();
    $journal['backup_zip'] = $info['zip'];
    fc_journal_write($journal);
    fc_log('Pre-update backup: ' . basename(dirname($info['zip'])));
    fc_advance(fc_after_backup());
}

function fc_step_extract(): void
{
    $zipFile = FC_UPDATE_ROOT . '/storage/updates/release.zip';
    $staging = FC_UPDATE_ROOT . '/storage/updates/staging';
    fc_rrmdir($staging);
    mkdir($staging, 0770, true);

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        fc_abort('Cannot open release package.');
    }

    // ZIP-bomb + zip-slip limits (plan §9 step 6).
    if ($zip->numFiles > 10000) {
        fc_abort('Release package has too many entries.');
    }
    $totalUncompressed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = (string) $stat['name'];
        if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
            fc_abort('Release package contains an unsafe path.');
        }
        if ($stat['size'] > 50 * 1024 * 1024) {
            fc_abort('Release package entry too large.');
        }
        if ($stat['comp_size'] > 0 && $stat['size'] / max(1, $stat['comp_size']) > 100) {
            fc_abort('Release package compression ratio suspicious.');
        }
        $totalUncompressed += (int) $stat['size'];
        if ($totalUncompressed > 500 * 1024 * 1024) {
            fc_abort('Release package too large.');
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_ends_with($name, '/')) {
            continue;
        }
        $dest = $staging . '/' . $name;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0770, true);
        }
        if (file_put_contents($dest, $zip->getFromIndex($i)) === false) {
            fc_abort('Extraction failed at ' . $name);
        }
    }
    $zip->close();

    fc_log('Extracted ' . $zip->numFiles . ' entries to staging.');
    fc_advance('stage_verify');
}

function fc_step_stage_verify(): void
{
    $staging = FC_UPDATE_ROOT . '/storage/updates/staging';
    $manifestFile = $staging . '/release.json';
    $manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
    if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'Family Castel') {
        fc_abort('Release manifest missing or foreign.');
    }

    $journal = fc_journal_read();
    if (($manifest['version'] ?? '') !== ($journal['target']['version'] ?? '')) {
        fc_abort('Release manifest version mismatch.');
    }
    $minPhp = (string) ($manifest['min_php'] ?? '8.2.0');
    if (version_compare(PHP_VERSION, $minPhp, '<')) {
        fc_abort("This release needs PHP {$minPhp}+ (server has " . PHP_VERSION . ').');
    }
    $current = trim((string) @file_get_contents(FC_UPDATE_ROOT . '/VERSION'));
    $floor = (string) ($manifest['update_from'] ?? '0.0.0');
    if ($current !== '' && version_compare($current, $floor, '<')) {
        fc_abort("This release requires at least version {$floor} (installed: {$current}).");
    }

    foreach (($manifest['files'] ?? []) as $file => $sha) {
        $path = $staging . '/' . $file;
        if (!is_file($path) || !hash_equals((string) $sha, (string) hash_file('sha256', $path))) {
            fc_abort('Staged file failed verification: ' . $file);
        }
    }

    // The staged VERSION file must state the target — otherwise the swap
    // would install code that reports (and boot-gates on) the wrong version.
    $stagedVersion = trim((string) @file_get_contents($staging . '/VERSION'));
    if ($stagedVersion !== (string) ($journal['target']['version'] ?? '')) {
        fc_abort('Staged VERSION file does not match the target release.');
    }

    $journal['manifest'] = ['version' => $manifest['version'], 'removed' => $manifest['removed'] ?? []];
    fc_journal_write($journal);
    fc_log('Staged package verified (' . count($manifest['files'] ?? []) . ' files).');
    fc_advance('swap');
}

function fc_step_swap(): void
{
    // Pure filesystem work — NO app classes (they are being replaced).
    $staging = FC_UPDATE_ROOT . '/storage/updates/staging';
    $backupDir = FC_UPDATE_ROOT . '/storage/updates/previous';
    // NEVER clear 'previous' here: on a resumed run it holds the only
    // rollback source. It is cleared exactly once, before the FIRST swap
    // (journal has no swap bookkeeping yet).
    $journal = fc_journal_read();
    if (!isset($journal['swapped']) && !isset($journal['swapping'])) {
        fc_rrmdir($backupDir);
    }
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0770, true);
    }

    $swapped = $journal['swapped'] ?? [];

    // Resume an interrupted per-entry swap: reconstruct where it stopped.
    $pending = $journal['swapping'] ?? null;
    if (is_string($pending) && !in_array($pending, $swapped, true)) {
        $live = FC_UPDATE_ROOT . '/' . $pending;
        $old = $backupDir . '/' . $pending;
        $new = $staging . '/' . $pending;
        if (file_exists($old) && file_exists($new)) {
            // first rename done, second not — finish it
            if (!rename($new, $live)) {
                fc_rollback_files('Swap resume failed placing new ' . $pending . '.');
            }
        }
        // (old && !new) → both renames done; (!old && new) → nothing done yet:
        // falls through to the normal loop. An ADDED entry (no old ever) whose
        // promotion completed leaves neither old nor new — mark it done too.
        if (file_exists($old) && !file_exists($new)) {
            $swapped[] = $pending;
        } elseif (!file_exists($old) && !file_exists($new) && file_exists($live)
            && in_array($pending, $journal['added'] ?? [], true)) {
            $swapped[] = $pending;
        }
        unset($journal['swapping']);
        $journal['swapped'] = $swapped;
        fc_journal_write($journal);
    }

    foreach (fc_swap_entries() as $entry) {
        if (in_array($entry, $swapped, true)) {
            continue; // resumed run — already swapped
        }
        $live = FC_UPDATE_ROOT . '/' . $entry;
        $new = $staging . '/' . $entry;
        if (!file_exists($new)) {
            continue; // release does not ship this entry — keep the live one
        }
        // Journal INTENT before touching anything (resume state machine above).
        // 'added' marks entries with NO live predecessor — rollback must know
        // whether "previous/ copy missing" means added-by-release (remove the
        // new copy) or already-consumed-by-a-rerun (leave the restored copy).
        if (!file_exists($live) && !in_array($entry, $journal['added'] ?? [], true)) {
            $journal['added'][] = $entry;
        }
        $journal['swapping'] = $entry;
        fc_journal_write($journal);

        if (file_exists($live) && !rename($live, $backupDir . '/' . $entry)) {
            fc_rollback_files('Swap failed moving ' . $entry . ' aside.');
        }
        if (!rename($new, $live)) {
            fc_rollback_files('Swap failed placing new ' . $entry . '.');
        }
        $swapped[] = $entry;
        $journal['swapped'] = $swapped;
        unset($journal['swapping']);
        fc_journal_write($journal);
    }

    // Retired files: move into the backup dir (rollback restores them).
    foreach (($journal['manifest']['removed'] ?? []) as $removed) {
        $removed = trim((string) $removed, '/');
        if (!fc_removed_allowed($removed)) {
            continue;
        }
        $live = FC_UPDATE_ROOT . '/' . $removed;
        if (file_exists($live)) {
            $dest = $backupDir . '/__removed__/' . $removed;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0770, true);
            }
            rename($live, $dest);
        }
    }

    fc_log('Swap complete.');
    fc_advance('migrate');
}

function fc_step_migrate(): void
{
    // Manual chain: retired files are removed HERE — after maintenance and
    // the pre-update backup, never before (deep-review finding).
    $journal = fc_journal_read();
    if (($journal['chain'] ?? 'github') === 'manual') {
        foreach (($journal['manifest']['removed'] ?? []) as $removed) {
            if (fc_removed_allowed((string) $removed) && file_exists(FC_UPDATE_ROOT . '/' . $removed)) {
                fc_rrmdir(FC_UPDATE_ROOT . '/' . $removed);
            }
        }
    }

    // Fresh request post-swap: the autoloader below loads the NEW classes.
    fc_app_autoload();
    $config = fc_config();
    $db = \FamilyCastel\Core\Db::fromParams(
        host: (string) $config['db']['host'],
        port: (int) $config['db']['port'],
        name: (string) $config['db']['name'],
        user: (string) $config['db']['user'],
        password: (string) $config['db']['password'],
    );
    try {
        $applied = (new \FamilyCastel\Database\Migrator($db, FC_UPDATE_ROOT . '/app/Database/Migrations'))->migrate();
    } catch (Throwable $e) {
        fc_rollback_full('Migration failed: ' . $e->getMessage());
    }

    fc_log('Migrations applied: ' . (implode(', ', $applied) ?: 'none'));
    fc_advance('health');
}

function fc_step_health(): void
{
    try {
        $pdo = fc_pdo();
        $pdo->query('SELECT 1')->fetch();
        $count = $pdo->query('SELECT COUNT(*) AS c FROM schema_migrations')->fetch();
        if ((int) ($count['c'] ?? 0) < 1) {
            throw new RuntimeException('schema_migrations empty');
        }
        // The new code must at least parse.
        foreach (['index.php', 'app/routes.php', 'app/Core/Router.php', 'app/Core/Db.php'] as $file) {
            if (!php_check_syntax_shim(FC_UPDATE_ROOT . '/' . $file)) {
                throw new RuntimeException('new ' . $file . ' does not parse');
            }
        }
    } catch (Throwable $e) {
        fc_rollback_full('Health check failed: ' . $e->getMessage());
    }

    fc_log('Health check OK.');
    fc_advance('finish');
}

/** Real parse check without shell (INV-003): token_get_all(TOKEN_PARSE). */
function php_check_syntax_shim(string $file): bool
{
    $source = @file_get_contents($file);
    if (!is_string($source)) {
        return false;
    }
    try {
        token_get_all($source, TOKEN_PARSE);

        return true;
    } catch (ParseError) {
        return false;
    } catch (Throwable) {
        return false;
    }
}

function fc_step_finish(): void
{
    $journal = fc_journal_read();
    $target = (string) ($journal['target']['version'] ?? '');

    // 1. Record the running version — MANDATORY: without it the boot gate
    // would 503 the site forever. Failure here → full rollback while every
    // safety net is still armed.
    try {
        fc_pdo()->prepare(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP()'
        )->execute(['app.version', json_encode(trim((string) @file_get_contents(FC_UPDATE_ROOT . '/VERSION')))]);
    } catch (Throwable $e) {
        fc_rollback_full('Could not record the new version: ' . $e->getMessage());
    }

    // 2. History (informational). Idempotency is enforced by the DATABASE:
    // UNIQUE (to_version, started_at, status) — migration 004 — makes a
    // re-run's INSERT IGNORE a provable no-op (no SELECT/INSERT race).
    try {
        fc_history_record(
            (string) ($journal['from_version'] ?? '?'),
            $target,
            'success',
            gmdate('Y-m-d H:i:s', (int) ($journal['started_at'] ?? time())),
            implode("\n", $journal['log'] ?? []),
            basename(dirname((string) ($journal['backup_zip'] ?? '')))
        );
    } catch (Throwable) {
        // history is informational — never fail the finish over it
    }

    // Non-fatal cleanup (plan §9.10): pruning errors are logged only.
    try {
        fc_app_autoload();
        $config = fc_config();
        $db = \FamilyCastel\Core\Db::fromParams(
            host: (string) $config['db']['host'],
            port: (int) $config['db']['port'],
            name: (string) $config['db']['name'],
            user: (string) $config['db']['user'],
            password: (string) $config['db']['password'],
        );
        $backups = new \FamilyCastel\Domain\BackupService(
            $db,
            backupsDir: FC_UPDATE_ROOT . '/storage/backups',
            configFile: FC_UPDATE_ROOT . '/config/config.php',
            uploadsDir: FC_UPDATE_ROOT . '/storage/uploads',
        );
        $settings = new \FamilyCastel\Domain\SettingsService($db);
        $backups->pruneRetention((int) $settings->get('backups.retention_pre_update', 5));
    } catch (Throwable $e) {
        fc_log('Pruning skipped: ' . $e->getMessage());
    }

    // COMMIT POINT: gate + maintenance off. If this throws, the journal is
    // still on 'finish' — re-running the step is safe and idempotent.
    fc_gate(false);
    fc_maintenance(false);

    $journal = fc_journal_read();
    $journal['step'] = 'done';
    $journal['status'] = 'success';
    fc_journal_write($journal);

    // Post-commit SELF-UPDATE of the executor (quality check Q3): update.php
    // is deliberately excluded from the swap (never replace the running
    // step machine mid-run), so the new release's copy is installed HERE —
    // after the commit point, when this request no longer reads the file
    // (PHP has it fully loaded). Verified by stage_verify against the
    // manifest like every other file. Non-fatal: a failed copy leaves the
    // current (working) executor in place, which is the status quo.
    $stagedSelf = FC_UPDATE_ROOT . '/storage/updates/staging/update.php';
    $liveSelf = FC_UPDATE_ROOT . '/update.php';
    if (is_file($stagedSelf) && hash_file('sha256', $stagedSelf) !== hash_file('sha256', $liveSelf)) {
        $tmpSelf = $liveSelf . '.new';
        if (@copy($stagedSelf, $tmpSelf) && @rename($tmpSelf, $liveSelf)) {
            fc_log('Updater executor replaced with the release version.');
        } else {
            @unlink($tmpSelf);
            fc_log('WARNING: could not replace update.php — the previous executor stays active.');
        }
    }

    // Post-commit cleanup — strictly non-fatal.
    fc_rrmdir(FC_UPDATE_ROOT . '/storage/updates/staging');
    @unlink(FC_UPDATE_ROOT . '/storage/updates/release.zip');
    @unlink(FC_UPDATE_ROOT . '/storage/updates/release.zip.sha256');
    fc_token_cleanup();
    fc_ops_lock_release();

    fc_ok(['step' => 'done', 'message' => 'Update complete — v' . $target]);
}

// ---------------------------------------------------------------- rollback

/**
 * Restore the entry an interrupted swap was working on (journal 'swapping').
 * Three distinguishable states:
 *   previous/<e> exists            → live was moved aside: put it back.
 *   staging/<e> still exists       → nothing was moved yet: leave live alone.
 *   neither exists but live does   → the entry was ADDED by the release and
 *                                    already promoted: remove the new copy.
 */
function fc_restore_pending_swap(array $journal, string $backupDir): bool
{
    $pending = $journal['swapping'] ?? null;
    if (!is_string($pending) || $pending === '' || in_array($pending, $journal['swapped'] ?? [], true)) {
        return true;
    }
    $live = FC_UPDATE_ROOT . '/' . $pending;
    $old = $backupDir . '/' . $pending;
    $new = FC_UPDATE_ROOT . '/storage/updates/staging/' . $pending;
    if (file_exists($old)) {
        fc_rrmdir($live);
        if (!@rename($old, $live)) {
            fc_log('Rollback could not restore ' . $pending);

            return false;
        }
    } elseif (!file_exists($new) && file_exists($live)
        && in_array($pending, $journal['added'] ?? [], true)) {
        fc_rrmdir($live);
        if (file_exists($live)) {
            return false;
        }
    }

    return true;
}

/** @return bool true only when EVERY entry provably went back. */
function fc_restore_swapped_entries(array $journal, string $backupDir): bool
{
    $ok = fc_restore_pending_swap($journal, $backupDir);
    foreach (array_reverse($journal['swapped'] ?? []) as $entry) {
        $live = FC_UPDATE_ROOT . '/' . $entry;
        $old = $backupDir . '/' . $entry;
        if (file_exists($old)) {
            fc_rrmdir($live);
            if (!@rename($old, $live)) {
                fc_log('Rollback could not restore ' . $entry);
                $ok = false;
            }
        } elseif (in_array($entry, $journal['added'] ?? [], true)) {
            fc_rrmdir($live); // release-added entry: pre-update state had none
            if (file_exists($live)) {
                $ok = false;
            }
        }
    }

    return fc_restore_removed($backupDir) && $ok;
}

function fc_rollback_files(string $reason): never
{
    fc_log('ROLLBACK (files): ' . $reason);
    $backupDir = FC_UPDATE_ROOT . '/storage/updates/previous';
    $journal = fc_journal_read();
    if (!fc_restore_swapped_entries($journal, $backupDir)) {
        // The rollback itself is incomplete — reopening would serve a mixed
        // tree. Fail CLOSED; previous/ still holds whatever could not move.
        fc_terminal_failure($reason . ' — AND the file rollback was incomplete; the site stays in '
            . 'maintenance. Restore the code files manually from storage/updates/previous.', reopen: false);
    }
    fc_terminal_failure($reason);
}

function fc_rollback_full(string $reason): never
{
    fc_log('ROLLBACK (files + database): ' . $reason);
    $journal = fc_journal_read();

    // 1. Files back — the in-flight entry first (it was touched last).
    $backupDir = FC_UPDATE_ROOT . '/storage/updates/previous';
    $filesOk = fc_restore_swapped_entries($journal, $backupDir);

    // 2. Exact DB snapshot restore (never down-migrations — plan §9.9).
    $backupZip = (string) ($journal['backup_zip'] ?? '');
    if ($backupZip !== '' && is_file($backupZip)) {
        try {
            fc_app_autoload(); // OLD code again after the file rollback
            $config = fc_config();
            $db = \FamilyCastel\Core\Db::fromParams(
                host: (string) $config['db']['host'],
                port: (int) $config['db']['port'],
                name: (string) $config['db']['name'],
                user: (string) $config['db']['user'],
                password: (string) $config['db']['password'],
            );
            (new \FamilyCastel\Domain\BackupService(
                $db,
                backupsDir: FC_UPDATE_ROOT . '/storage/backups',
                configFile: FC_UPDATE_ROOT . '/config/config.php',
                uploadsDir: FC_UPDATE_ROOT . '/storage/uploads',
            ))->restoreDatabase($backupZip);
            fc_log('Database restored from pre-update backup.');
        } catch (Throwable $e) {
            fc_log('DATABASE RESTORE FAILED: ' . $e->getMessage() . ' — restore manually from ' . $backupZip);
            // Old files + possibly-migrated database: NEVER reopen. Keep the
            // site in maintenance until a human restores the snapshot.
            fc_terminal_failure($reason . ' — AND the automatic DB restore failed; '
                . 'the site stays in maintenance. Restore manually from backup '
                . basename(dirname($backupZip)) . '.', reopen: false);
        }
    }

    if (!$filesOk) {
        fc_terminal_failure($reason . ' — AND the file rollback was incomplete; the site stays in '
            . 'maintenance. Restore the code files manually from storage/updates/previous.', reopen: false);
    }

    fc_terminal_failure($reason);
}

/** @return bool true when every retired file went back (or none were retired). */
function fc_restore_removed(string $backupDir): bool
{
    $removedRoot = $backupDir . '/__removed__';
    if (!is_dir($removedRoot)) {
        return true;
    }
    $ok = true;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($removedRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($removedRoot) + 1);
        $dest = FC_UPDATE_ROOT . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest) && !@mkdir($dest, 0770, true)) {
                $ok = false;
            }
        } elseif (!file_exists($dest) && !@rename($item->getPathname(), $dest)) {
            $ok = false;
        }
    }

    return $ok;
}

/**
 * Idempotent history insert that works on BOTH schema generations: the
 * INSERT..SELECT..WHERE NOT EXISTS dedups even when a rollback restored a
 * pre-004 snapshot WITHOUT the unique key (sequential re-runs — the only
 * writers there), and INSERT IGNORE + uq_update_history_run (004+) makes the
 * concurrent case a provable no-op as well.
 */
function fc_history_record(string $from, string $to, string $status, string $startedAt, string $log, string $backupFile): void
{
    fc_pdo()->prepare(
        "INSERT IGNORE INTO update_history (from_version, to_version, status, started_at, finished_at, log, backup_file)
         SELECT ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?
         FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM update_history
             WHERE to_version = ? AND started_at = ? AND status = ?
         )"
    )->execute([$from, $to, $status, $startedAt, $log, $backupFile, $to, $startedAt, $status]);
}

function fc_terminal_failure(string $reason, bool $reopen = true): never
{
    try {
        $journal = fc_journal_read();
        fc_history_record(
            (string) ($journal['from_version'] ?? '?'),
            (string) ($journal['target']['version'] ?? '?'),
            'rolled_back',
            gmdate('Y-m-d H:i:s', (int) ($journal['started_at'] ?? time())),
            implode("\n", $journal['log'] ?? []),
            basename(dirname((string) ($journal['backup_zip'] ?? '')))
        );
    } catch (Throwable) {
    }

    $journal = fc_journal_read();
    $journal['step'] = 'failed';
    $journal['status'] = $reopen ? 'rolled_back' : 'restore_failed_manual_needed';
    $journal['error'] = $reason;
    fc_journal_write($journal);

    fc_token_cleanup();
    if ($reopen) {
        fc_gate(false);
        fc_maintenance(false);
    }
    fc_ops_lock_release();
    fc_fail($reason, 500);
}

function fc_token_cleanup(): void
{
    @unlink(FC_UPDATE_ROOT . '/storage/updates/auth-token');
    if (!headers_sent()) {
        setcookie('fc_update_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    }
}

/** Abort BEFORE the swap: clean staging, release everything, no rollback needed. */
function fc_abort(string $reason): never
{
    fc_log('ABORT: ' . $reason);
    fc_rrmdir(FC_UPDATE_ROOT . '/storage/updates/staging');
    $journal = fc_journal_read();
    $journal['step'] = 'failed';
    $journal['status'] = 'aborted';
    $journal['error'] = $reason;
    fc_journal_write($journal);
    fc_token_cleanup();
    fc_gate(false);
    fc_maintenance(false);
    fc_ops_lock_release();
    fc_fail($reason, 500);
}

function fc_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);
        }

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function fc_advance(string $next): never
{
    $journal = fc_journal_read();
    $journal['step'] = $next;
    $journal['status'] = 'running';
    fc_journal_write($journal);
    fc_ok(['step' => $next]);
}

// ---------------------------------------------------------------- dispatch

if (defined('FC_UPDATE_LIB')) {
    return; // included by tests — expose functions only
}

$journal = fc_journal_read();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    fc_auth();
    // Pin THIS request to the run that authorized it: journal writes and the
    // lock release compare against this constant, so a handler that loses the
    // run to a newer start is fenced instead of clobbering its state.
    define('FC_REQ_RUN', (string) ($journal['run_id'] ?? ''));
    $step = (string) ($_POST['step'] ?? '');
    $allowed = ['manual_verify', 'preflight', 'download', 'verify', 'maintenance_on', 'backup', 'extract', 'stage_verify', 'swap', 'migrate', 'health', 'finish'];
    if (!in_array($step, $allowed, true)) {
        fc_fail('Unknown step.');
    }
    // Steps must run in journal order (idempotent resume allowed).
    $expected = (string) ($journal['step'] ?? 'preflight');
    if ($step !== $expected) {
        fc_fail("Out of order: expected '{$expected}', got '{$step}'.", 409);
    }
    // Mid-chain steps must still OWN the lock (first steps acquire it).
    if (!in_array($step, ['preflight', 'manual_verify'], true)) {
        $ownerNow = (string) @file_get_contents(FC_UPDATE_ROOT . '/storage/ops.lock/owner');
        if ($ownerNow !== 'updater:' . FC_REQ_RUN) {
            fc_fail('Update lock lost — start again from the settings page.', 409);
        }
    }
    set_time_limit(0);
    ('fc_step_' . $step)();
}

// GET: minimal self-contained progress UI (no app assets — they may be mid-swap).
// Auth for the steps travels in the HttpOnly cookie — never in the URL.
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Family Castel — Update</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               font-family: system-ui, sans-serif; background: #1a1f3c; color: #fff; }
        main { max-width: 30rem; width: 92%; background: rgba(255,255,255,.06);
               border-radius: 16px; padding: 1.5rem; }
        h1 { font-size: 1.3rem; margin: 0 0 1rem; }
        #log { font-family: monospace; font-size: .8rem; white-space: pre-wrap;
               background: rgba(0,0,0,.35); border-radius: 8px; padding: .8rem;
               max-height: 40vh; overflow-y: auto; }
        .bar { height: .9rem; background: rgba(0,0,0,.4); border-radius: 999px; overflow: hidden; margin: .8rem 0; }
        .bar div { height: 100%; width: 0; background: #58cc02; transition: width .4s; }
        .error { color: #ff8a95; font-weight: 700; }
        .done { color: #9be564; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>🏰 Family Castel Update</h1>
    <div class="bar"><div id="fill"></div></div>
    <p id="status">Bereit.</p>
    <div id="log"></div>
</main>
<script>
(function () {
    'use strict';
    var chain = <?= json_encode((string) ($journal['chain'] ?? 'github'), JSON_HEX_TAG) ?>;
    var steps = chain === 'manual'
        ? ['manual_verify','maintenance_on','backup','migrate','health','finish']
        : ['preflight','download','verify','maintenance_on','backup','extract','stage_verify','swap','migrate','health','finish'];
    var startAt = <?= json_encode((string) ($journal['step'] ?? 'preflight'), JSON_HEX_TAG) ?>;
    var index = Math.max(0, steps.indexOf(startAt));
    var fill = document.getElementById('fill');
    var status = document.getElementById('status');
    var log = document.getElementById('log');

    function line(text) { log.textContent += text + '\n'; log.scrollTop = log.scrollHeight; }

    function run() {
        if (index >= steps.length) { return; }
        var step = steps[index];
        status.textContent = 'Schritt: ' + step + ' …';
        fill.style.width = Math.round(100 * index / steps.length) + '%';
        var body = new URLSearchParams();
        body.set('step', step);
        fetch('update.php', { method: 'POST', body: body })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) {
                    status.textContent = '';
                    var errorSpan = document.createElement('span');
                    errorSpan.className = 'error';
                    errorSpan.textContent = 'Fehler: ' + (data.error || 'unbekannt');
                    status.appendChild(errorSpan);
                    line('FEHLER: ' + (data.error || ''));
                    return;
                }
                line('OK: ' + step);
                if (data.step === 'done') {
                    fill.style.width = '100%';
                    status.textContent = '';
                    var doneSpan = document.createElement('span');
                    doneSpan.className = 'done';
                    doneSpan.textContent = (data.message || 'Fertig!') + ' ';
                    var link = document.createElement('a');
                    link.href = './';
                    link.textContent = 'Zur App';
                    link.style.color = '#9be564';
                    doneSpan.appendChild(link);
                    status.appendChild(doneSpan);
                    return;
                }
                index = steps.indexOf(data.step);
                run();
            })
            .catch(function (err) {
                status.textContent = '';
                var netSpan = document.createElement('span');
                netSpan.className = 'error';
                netSpan.textContent = 'Verbindungsfehler — Seite neu laden zum Fortsetzen.';
                status.appendChild(netSpan);
                line('Netzwerkfehler: ' + err);
            });
    }
    run();
})();
</script>
</body>
</html>
