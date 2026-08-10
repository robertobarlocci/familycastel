<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * Persistent login ("stay signed in") for parents and children.
 *
 * A separate table is required rather than reusing auth_tokens: that one is
 * child-only (child_id NOT NULL, FK to children) and has no expiry, so it
 * cannot carry a parent session.
 *
 * Operational table, like login_attempts — prunable, never Journal data
 * (INV-001). Only the sha256 of a token is stored, so a database leak (or a
 * downloaded backup) yields no usable cookie.
 *
 * previous_hash + rotated_at implement rotation with a short grace window: a
 * cold page load fires several parallel requests, and without the window the
 * second would present a token the first had just rotated away and log the
 * family out.
 */
return static fn (Db $db) => new class($db) extends Migration {
    public function up(): void
    {
        $this->db->execute(
            "CREATE TABLE IF NOT EXISTS remember_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                principal_type VARCHAR(10) NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                child_id BIGINT UNSIGNED NULL,
                token_hash CHAR(64) NOT NULL,
                previous_hash CHAR(64) NULL,
                rotated_at DATETIME NULL,
                label VARCHAR(100) NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                UNIQUE KEY uq_remember_token_hash (token_hash),
                KEY ix_remember_previous (previous_hash),
                KEY ix_remember_user (user_id),
                KEY ix_remember_child (child_id),
                KEY ix_remember_expiry (expires_at),
                CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_remember_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE CASCADE,
                CONSTRAINT ck_remember_principal CHECK (
                    (principal_type = 'user' AND user_id IS NOT NULL AND child_id IS NULL)
                    OR (principal_type = 'child' AND child_id IS NOT NULL AND user_id IS NULL)
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS remember_tokens');
    }
};
