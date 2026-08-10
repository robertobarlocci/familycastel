<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * Form-replay idempotency for reward redemptions (final quality check Q9):
 * the redeem form carries a one-time nonce; a double-submit must map onto
 * the SAME pending request instead of reserving coins twice. NULL stays
 * allowed (parent-side flows and pre-005 rows have no nonce).
 */
return static fn (Db $db) => new class($db) extends Migration {
    public function up(): void
    {
        $this->db->execute(
            'ALTER TABLE reward_requests
             ADD COLUMN request_key VARCHAR(64) NULL AFTER duration_minutes,
             ADD UNIQUE KEY uq_reward_requests_key (request_key)'
        );
    }

    public function down(): void
    {
        $this->db->execute(
            'ALTER TABLE reward_requests
             DROP INDEX uq_reward_requests_key,
             DROP COLUMN request_key'
        );
    }
};
