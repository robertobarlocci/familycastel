<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * Update-history idempotency (Codex ops round 3): the updater records history
 * with INSERT IGNORE, so duplicates must be impossible AT THE DATABASE — a
 * SELECT-before-INSERT has a race window and a journal flag can lag a crash.
 *
 * One run is identified by (to_version, started_at); status separates the
 * success row from a rolled_back row of the same run. Pre-existing exact
 * duplicates (crash artifacts of the pre-004 updater — ops metadata, not
 * family history, so INV-001 does not apply) are collapsed to their first
 * row before the key is added.
 */
return static fn (Db $db) => new class($db) extends Migration {
    public function up(): void
    {
        $this->db->execute(
            'DELETE h1 FROM update_history h1
             INNER JOIN update_history h2
                ON h2.to_version = h1.to_version
               AND h2.started_at = h1.started_at
               AND h2.status = h1.status
               AND h2.id < h1.id'
        );
        $this->db->execute(
            'ALTER TABLE update_history
             ADD UNIQUE KEY uq_update_history_run (to_version, started_at, status)'
        );
    }

    public function down(): void
    {
        $this->db->execute('ALTER TABLE update_history DROP INDEX uq_update_history_run');
    }
};
