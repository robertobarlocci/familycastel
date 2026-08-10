<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Backups (plan §10): ZIP with database.sql (pure-PHP dump under ONE
 * consistent InnoDB snapshot — the --single-transaction equivalent),
 * config.php, uploads/ and meta.json. The FILESYSTEM is the source of truth
 * for backup existence (backup_history is a cache); emergency backups are
 * never auto-pruned. Restore validates structure and never blind-extracts.
 */
final class BackupService
{
    private const META_APP = 'Family Castel';
    private const DUMP_BATCH = 500;

    public function __construct(
        private readonly Db $db,
        private readonly string $backupsDir,
        private readonly string $configFile,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * @return array{id: string, zip: string, size: int}
     */
    public function create(string $kind, ?int $createdBy): array
    {
        if (!in_array($kind, ['manual', 'pre_update', 'emergency'], true)) {
            throw new \InvalidArgumentException('Unknown backup kind.');
        }

        $id = gmdate('Ymd-His') . '-' . $kind . '-' . bin2hex(random_bytes(3));
        $dir = $this->backupsDir . '/' . $id;
        if (!@mkdir($dir, 0770, true)) {
            throw new \RuntimeException('Cannot create backup directory.');
        }

        $sqlFile = $dir . '/database.sql';
        $this->dumpDatabase($sqlFile);

        $meta = [
            'app' => self::META_APP,
            'app_version' => defined('FC_VERSION') ? FC_VERSION : trim((string) @file_get_contents(dirname($this->configFile, 2) . '/VERSION')),
            'schema_version' => $this->schemaVersion(),
            'kind' => $kind,
            'created_at' => gmdate('c'),
            'created_by' => $createdBy,
        ];

        $zipPath = $dir . '/backup.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Cannot create backup archive.');
        }
        $zip->addFile($sqlFile, 'database.sql');
        $zip->addFromString('meta.json', json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        if (is_file($this->configFile)) {
            $zip->addFile($this->configFile, 'config/config.php');
        }
        $this->addDirToZip($zip, $this->uploadsDir, 'uploads');
        if (!$zip->close()) {
            throw new \RuntimeException('Cannot finalize backup archive.');
        }
        @unlink($sqlFile); // contents live inside the zip

        // Sidecar meta for the filesystem listing (source of truth).
        file_put_contents($dir . '/meta.json', json_encode(
            $meta + ['size_bytes' => filesize($zipPath)],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ), LOCK_EX);

        $this->syncHistory();

        return ['id' => $id, 'zip' => $zipPath, 'size' => (int) filesize($zipPath)];
    }

    /**
     * Exact point-in-time DATABASE restore from a validated backup ZIP.
     * Caller is responsible for maintenance/gate orchestration (plan §10).
     */
    public function restoreDatabase(string $zipPath): void
    {
        $zip = $this->openValidated($zipPath);
        $sql = $zip->getFromName('database.sql');
        $zip->close();
        if ($sql === false || $sql === '') {
            throw new \InvalidArgumentException('Backup contains no database dump.');
        }

        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // Drop everything, then replay the dump statement by statement.
            foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
                $table = str_replace('`', '``', (string) array_values($row)[0]);
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }
            foreach ($this->splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->syncHistory();
    }

    /** Extract uploads/ from a validated backup into a target dir (zip-slip safe). */
    public function extractUploads(string $zipPath, string $targetDir): void
    {
        $zip = $this->openValidated($zipPath);

        // A single unsafe name anywhere marks the whole archive hostile —
        // reject outright instead of silently skipping.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
                $zip->close();
                throw new \InvalidArgumentException('Backup archive contains an unsafe path.');
            }
        }
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0770, true)) {
            $zip->close();
            throw new \RuntimeException('Cannot create restore target.');
        }
        $targetReal = realpath($targetDir);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'uploads/')) {
                continue;
            }
            if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
                $zip->close();
                throw new \InvalidArgumentException('Backup archive contains an unsafe path.');
            }
            $relative = substr($name, strlen('uploads/'));
            if ($relative === '' || str_ends_with($name, '/')) {
                continue;
            }
            $dest = $targetDir . '/' . $relative;
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0770, true)) {
                $zip->close();
                throw new \RuntimeException('Cannot create restore subdirectory.');
            }
            // Containment double-check after directory creation.
            $destDirReal = realpath($destDir);
            if ($destDirReal === false || !str_starts_with($destDirReal . '/', $targetReal . '/')) {
                $zip->close();
                throw new \InvalidArgumentException('Backup archive escapes the restore target.');
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false || file_put_contents($dest, $contents) === false) {
                $zip->close();
                throw new \RuntimeException('Cannot write restored upload: ' . $relative);
            }
        }
        $zip->close();
    }

    /** @return array<string, mixed> meta.json of a validated backup */
    public function readMeta(string $zipPath): array
    {
        $zip = $this->openValidated($zipPath);
        $meta = json_decode((string) $zip->getFromName('meta.json'), true);
        $zip->close();

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return list<array{id: string, zip: string, kind: string, created_at: string, size_bytes: int, app_version: string}>
     *     newest first
     */
    public function listFromFilesystem(): array
    {
        $list = [];
        foreach (glob($this->backupsDir . '/*/meta.json') ?: [] as $metaFile) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            if (!is_array($meta) || ($meta['app'] ?? '') !== self::META_APP) {
                continue;
            }
            $dir = dirname($metaFile);
            $zip = $dir . '/backup.zip';
            if (!is_file($zip)) {
                continue;
            }
            $list[] = [
                'id' => basename($dir),
                'zip' => $zip,
                'kind' => (string) ($meta['kind'] ?? 'manual'),
                'created_at' => (string) ($meta['created_at'] ?? ''),
                'size_bytes' => (int) ($meta['size_bytes'] ?? filesize($zip)),
                'app_version' => (string) ($meta['app_version'] ?? ''),
            ];
        }
        usort($list, static fn (array $a, array $b) => strcmp($b['id'], $a['id']));

        return $list;
    }

    /** Re-sync the backup_history cache from the filesystem (source of truth). */
    public function syncHistory(): void
    {
        try {
            foreach ($this->listFromFilesystem() as $backup) {
                $this->db->execute(
                    "INSERT INTO backup_history (filename, size_bytes, kind, status, created_at)
                     VALUES (?, ?, ?, 'ok', UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE size_bytes = VALUES(size_bytes)",
                    [$backup['id'], $backup['size_bytes'], $backup['kind']]
                );
            }
        } catch (\Throwable) {
            // The cache is best-effort; the filesystem listing stays authoritative.
        }
    }

    /** Prune old pre_update backups; emergency backups are NEVER auto-pruned. */
    public function pruneRetention(int $keepPreUpdate): void
    {
        $preUpdates = array_values(array_filter(
            $this->listFromFilesystem(),
            static fn (array $b) => $b['kind'] === 'pre_update'
        ));
        foreach (array_slice($preUpdates, max(0, $keepPreUpdate)) as $backup) {
            $dir = dirname($backup['zip']);
            @unlink($backup['zip']);
            @unlink($dir . '/meta.json');
            @rmdir($dir);
        }
        $this->syncHistory();
    }

    // ------------------------------------------------------------ internals

    private function openValidated(string $zipPath): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Cannot open backup archive.');
        }
        // Structural limits (hostile archives): entry count, expanded sizes.
        if ($zip->numFiles > 20000) {
            $zip->close();
            throw new \InvalidArgumentException('Backup archive has too many entries.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int) $stat['size'];
            if ($stat['size'] > 512 * 1024 * 1024 || $total > 2 * 1024 * 1024 * 1024) {
                $zip->close();
                throw new \InvalidArgumentException('Backup archive expands beyond safe limits.');
            }
        }
        $meta = json_decode((string) $zip->getFromName('meta.json'), true);
        if (!is_array($meta) || ($meta['app'] ?? '') !== self::META_APP
            || $zip->locateName('database.sql') === false) {
            $zip->close();
            throw new \InvalidArgumentException('This is not a Family Castel backup archive.');
        }

        return $zip;
    }

    private function dumpDatabase(string $file): void
    {
        $handle = fopen($file, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write dump file.');
        }

        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            fwrite($handle, "-- Family Castel database backup " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

            foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
                $table = (string) array_values($row)[0];
                $quoted = '`' . str_replace('`', '``', $table) . '`';

                $create = $this->db->fetchOne("SHOW CREATE TABLE {$quoted}");
                $createSql = (string) array_values($create)[1];
                fwrite($handle, "DROP TABLE IF EXISTS {$quoted};\n{$createSql};\n\n");

                // Deterministic KEYSET pagination on the primary key —
                // OFFSET pagination without an order can skip/duplicate rows.
                // Tables without a PK (none in our schema) are read in ONE
                // query: under the consistent snapshot the data is frozen,
                // so a single unpaginated read is exact.
                $pkColumns = array_map('strval', array_column($this->db->fetchAll(
                    "SHOW KEYS FROM {$quoted} WHERE Key_name = 'PRIMARY'"
                ), 'Column_name'));
                $pkQuoted = array_map(
                    static fn (string $c) => '`' . str_replace('`', '``', $c) . '`',
                    $pkColumns
                );
                $orderBy = $pkQuoted === [] ? '' : ' ORDER BY ' . implode(', ', $pkQuoted);

                $lastKey = null;
                while (true) {
                    if ($pkQuoted === []) {
                        $rows = $this->db->fetchAll("SELECT * FROM {$quoted}");
                    } elseif ($lastKey === null) {
                        $rows = $this->db->fetchAll(
                            "SELECT * FROM {$quoted}{$orderBy} LIMIT " . self::DUMP_BATCH
                        );
                    } else {
                        $tuple = '(' . implode(', ', $pkQuoted) . ')';
                        $marks = '(' . implode(', ', array_fill(0, count($pkQuoted), '?')) . ')';
                        $rows = $this->db->fetchAll(
                            "SELECT * FROM {$quoted} WHERE {$tuple} > {$marks}{$orderBy} LIMIT " . self::DUMP_BATCH,
                            $lastKey
                        );
                    }
                    if ($rows === []) {
                        break;
                    }
                    $values = [];
                    foreach ($rows as $dataRow) {
                        $values[] = '(' . implode(',', array_map(
                            static function ($v) use ($pdo): string {
                                if ($v === null) {
                                    return 'NULL';
                                }
                                $str = (string) $v;
                                // Binary-safe: anything that is not clean UTF-8
                                // text becomes a hex literal (charset/SQL-mode
                                // independent round-trip).
                                if ($str !== '' && (!preg_match('//u', $str) || str_contains($str, "\0"))) {
                                    return '0x' . bin2hex($str);
                                }

                                return $pdo->quote($str);
                            },
                            array_values($dataRow)
                        )) . ')';
                    }
                    $columns = implode(',', array_map(
                        static fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`',
                        array_keys($rows[0])
                    ));
                    fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n");
                    if ($pkQuoted === [] || count($rows) < self::DUMP_BATCH) {
                        break;
                    }
                    $lastRow = $rows[count($rows) - 1];
                    $lastKey = [];
                    foreach ($pkColumns as $c) {
                        $lastKey[] = $lastRow[$c];
                    }
                }
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            $pdo->exec('COMMIT');
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        // The dump is machine-generated: statements end with ";\n" and string
        // literals are PDO-quoted (quotes/backslashes escaped), so a
        // state-machine split on unquoted semicolons is reliable.
        $statements = [];
        $buffer = '';
        $inString = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $buffer .= $char;
            if ($inString) {
                if ($char === '\\') {
                    $buffer .= $sql[++$i] ?? '';
                } elseif ($char === "'") {
                    $inString = false;
                }
            } elseif ($char === "'") {
                $inString = true;
            } elseif ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== ';' && !str_starts_with($trimmed, '--')) {
                    $statements[] = $trimmed;
                }
                $buffer = '';
            }
        }

        return $statements;
    }

    private function schemaVersion(): string
    {
        try {
            $row = $this->db->fetchOne('SELECT MAX(version) AS v FROM schema_migrations');

            return (string) ($row['v'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep' && $file->getFilename() !== '.htaccess') {
                $relative = substr($file->getPathname(), strlen($dir) + 1);
                $zip->addFile($file->getPathname(), $prefix . '/' . $relative);
            }
        }
    }
}
