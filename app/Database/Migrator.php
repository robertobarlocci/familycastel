<?php

declare(strict_types=1);

namespace FamilyCastel\Database;

use FamilyCastel\Core\Db;

final class Migrator
{
    public function __construct(
        private readonly Db $db,
        private readonly string $migrationsDir,
    ) {
    }

    /** @return list<MigrationFile> */
    public function all(): array
    {
        $files = glob($this->migrationsDir . '/[0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);

        return array_map(
            static fn (string $file) => new MigrationFile($file),
            $files
        );
    }

    /** @return list<MigrationFile> */
    public function pending(): array
    {
        $applied = $this->appliedVersions();

        return array_values(array_filter(
            $this->all(),
            static fn (MigrationFile $m) => !in_array($m->version(), $applied, true)
        ));
    }

    /**
     * Apply all pending migrations in order. Returns the applied versions.
     * The version row is written only after up() succeeds (DDL is not
     * transactional in MariaDB — never pre-record).
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        return $this->withAdvisoryLock(function (): array {
            $this->ensureMigrationsTable();
            $appliedNow = [];

            foreach ($this->pending() as $file) {
                $start = microtime(true);
                $file->instantiate($this->db)->up();
                $this->db->execute(
                    'INSERT INTO schema_migrations (version, name, applied_at, execution_ms) VALUES (?, ?, UTC_TIMESTAMP(), ?)',
                    [$file->version(), $file->name(), (int) ((microtime(true) - $start) * 1000)]
                );
                $appliedNow[] = $file->version();
            }

            return $appliedNow;
        });
    }

    /**
     * Serialize migration runs across processes (installer, updater, boot check)
     * with a MariaDB advisory lock — two concurrent runners must never compute
     * the same pending set.
     */
    private function withAdvisoryLock(callable $fn): mixed
    {
        $acquired = $this->db->fetchOne('SELECT GET_LOCK(?, 15) AS ok', ['familycastel_migrations']);
        if ((int) ($acquired['ok'] ?? 0) !== 1) {
            throw new \RuntimeException('Another migration run is in progress — try again shortly.');
        }

        try {
            return $fn();
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', ['familycastel_migrations']);
        }
    }

    /** Roll back every applied migration, newest first (dev/manual use only). */
    public function rollbackAll(): void
    {
        $this->withAdvisoryLock(function (): void {
            $this->doRollbackAll();
        });
    }

    private function doRollbackAll(): void
    {
        $this->ensureMigrationsTable();
        $byVersion = [];
        foreach ($this->all() as $file) {
            $byVersion[$file->version()] = $file;
        }

        $applied = array_reverse($this->appliedVersions());
        foreach ($applied as $version) {
            if (!isset($byVersion[$version])) {
                throw new \RuntimeException("No migration file for applied version {$version}");
            }
            $byVersion[$version]->instantiate($this->db)->down();
            $this->db->execute('DELETE FROM schema_migrations WHERE version = ?', [$version]);
        }
    }

    public function currentVersion(): ?string
    {
        $this->ensureMigrationsTable();
        $row = $this->db->fetchOne('SELECT MAX(version) AS v FROM schema_migrations');

        return $row['v'] ?? null;
    }

    /** @return list<string> */
    private function appliedVersions(): array
    {
        $this->ensureMigrationsTable();

        return array_column(
            $this->db->fetchAll('SELECT version FROM schema_migrations ORDER BY version'),
            'version'
        );
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(10) NOT NULL PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                applied_at DATETIME NOT NULL,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
