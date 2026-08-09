<?php

declare(strict_types=1);

namespace FamilyCastel\Database;

use FamilyCastel\Core\Db;

/**
 * One versioned schema change. MariaDB DDL is non-transactional, so each
 * migration must stay small and idempotent; the version row is recorded only
 * after up() succeeds. Every migration MUST implement down() (hard rule) —
 * but updater rollback always restores the pre-update DB snapshot instead.
 */
abstract class Migration
{
    final public function __construct(protected readonly Db $db)
    {
    }

    abstract public function up(): void;

    abstract public function down(): void;
}
