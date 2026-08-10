<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * INV-001 fix (Codex T8-T12 review): the child's request-time cost is a
 * snapshot and must never be overwritten by a parent's approval override —
 * the approved cost gets its own column.
 */
return static fn (Db $db) => new class($db) extends Migration {
    public function up(): void
    {
        $column = $this->db->fetchOne(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reward_requests' AND COLUMN_NAME = 'approved_cost_coins'"
        );
        if ($column === null) {
            $this->db->execute(
                'ALTER TABLE reward_requests ADD COLUMN approved_cost_coins INT UNSIGNED NULL AFTER cost_coins'
            );
        }
    }

    public function down(): void
    {
        $this->db->execute('ALTER TABLE reward_requests DROP COLUMN IF EXISTS approved_cost_coins');
    }
};
