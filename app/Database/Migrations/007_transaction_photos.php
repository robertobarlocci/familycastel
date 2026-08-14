<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * Photo evidence for a penalty ("Minuspunkt"): the picture a parent attaches so
 * the child can see WHY a deduction happened, not just that it did.
 *
 * A separate table rather than a column on `transactions`, so the append-only
 * ledger keeps its exact shape and LedgerService::post()'s signature is not
 * widened by a concern exactly one caller has.
 *
 * Only a relative path is stored (`YYYY/MM/<32 hex>.<ext>`); the bytes live
 * under storage/uploads/penalties/, which Apache refuses to serve
 * (storage/.htaccess) and which PHP streams only behind an authorisation check.
 *
 * Key choices:
 * - ON DELETE RESTRICT matches transactions' own FK style and INV-001: a
 *   history row carrying evidence can never be deleted out from under it.
 * - uq_transaction_photos_tx makes "one photo per penalty" a database fact, so
 *   an idempotent replay of a double-submitted form lands on ONE row.
 * - uq_transaction_photos_path means a stored file can never be referenced
 *   twice, which is what lets the rollback delete-by-path be provably safe.
 */
return static fn (Db $db) => new class($db) extends Migration {
    public function up(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS transaction_photos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                transaction_id BIGINT UNSIGNED NOT NULL,
                path VARCHAR(255) NOT NULL,
                mime VARCHAR(50) NOT NULL,
                byte_size INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_transaction_photos_tx (transaction_id),
                UNIQUE KEY uq_transaction_photos_path (path),
                CONSTRAINT fk_transaction_photos_tx FOREIGN KEY (transaction_id)
                    REFERENCES transactions (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS transaction_photos');
    }
};
