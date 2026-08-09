<?php

declare(strict_types=1);

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;

/**
 * Initial schema — plan §4 (approved v1.11). utf8mb4/InnoDB, UTC timestamps.
 * History tables are append-only by contract (INV-001/INV-002); operational
 * tables (claim slots, login attempts, ops_state) are the documented
 * exceptions that may be pruned/reset.
 */
return static fn (Db $db) => new class($db) extends Migration {
    private const CHARSET = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $c = self::CHARSET;

        $this->db->execute("CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` JSON NOT NULL,
            updated_at DATETIME NOT NULL
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            username VARCHAR(100) NOT NULL,
            email VARCHAR(190) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'parent',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_users_username (username),
            UNIQUE KEY uq_users_email (email)
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS children (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            character_key VARCHAR(50) NOT NULL DEFAULT 'knight',
            theme VARCHAR(50) NOT NULL DEFAULT 'fantasy',
            allowed_themes JSON NULL,
            pin_hash VARCHAR(255) NULL,
            level INT UNSIGNED NOT NULL DEFAULT 1,
            xp_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            coin_balance BIGINT NOT NULL DEFAULT 0,
            sound_enabled TINYINT(1) NOT NULL DEFAULT 1,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS auth_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            child_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            label VARCHAR(100) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            last_used_at DATETIME NULL,
            UNIQUE KEY uq_auth_tokens_hash (token_hash),
            KEY ix_auth_tokens_child (child_id),
            CONSTRAINT fk_auth_tokens_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_auth_tokens_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            subject_type VARCHAR(10) NOT NULL,
            subject_key VARCHAR(190) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL,
            KEY ix_login_attempts_lookup (subject_type, subject_key, attempted_at)
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            child_id BIGINT UNSIGNED NOT NULL,
            coins_delta INT NOT NULL,
            xp_delta INT UNSIGNED NOT NULL DEFAULT 0,
            type ENUM('award','deduction','sidequest','suggestion','reward_spend','milestone_spend','adjustment','reversal') NOT NULL,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            comment TEXT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            source_type VARCHAR(30) NULL,
            source_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(64) NULL,
            reversal_of BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_transactions_idempotency (idempotency_key),
            UNIQUE KEY uq_transactions_reversal (reversal_of),
            KEY ix_transactions_child_time (child_id, created_at),
            CONSTRAINT fk_transactions_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_transactions_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_transactions_reversal FOREIGN KEY (reversal_of) REFERENCES transactions (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS point_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            coins_delta INT NOT NULL,
            xp_delta INT UNSIGNED NOT NULL DEFAULT 0,
            scope VARCHAR(10) NOT NULL DEFAULT 'all',
            child_ids JSON NULL,
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            sort INT NOT NULL DEFAULT 0,
            requires_confirm TINYINT(1) NOT NULL DEFAULT 0,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS sidequests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            coins_reward INT NOT NULL DEFAULT 0,
            xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
            type ENUM('once','daily','weekly','repeating') NOT NULL DEFAULT 'once',
            ownership ENUM('first_come','assigned','per_child') NOT NULL DEFAULT 'first_come',
            assigned_child_ids JSON NULL,
            available_from DATETIME NULL,
            expires_at DATETIME NULL,
            max_completions INT UNSIGNED NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NULL,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY ix_sidequests_status (status),
            CONSTRAINT fk_sidequests_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS sidequest_claims (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sidequest_id BIGINT UNSIGNED NOT NULL,
            child_id BIGINT UNSIGNED NOT NULL,
            recurrence_bucket VARCHAR(16) NOT NULL DEFAULT '',
            status ENUM('accepted','completed_pending','approved','rejected','cancelled','expired') NOT NULL,
            title VARCHAR(190) NOT NULL,
            coins_reward INT NOT NULL DEFAULT 0,
            xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
            approved_coins INT NULL,
            approved_xp INT UNSIGNED NULL,
            parent_comment TEXT NULL,
            accepted_at DATETIME NULL,
            completed_at DATETIME NULL,
            decided_at DATETIME NULL,
            decided_by BIGINT UNSIGNED NULL,
            transaction_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY ix_claims_quest_status (sidequest_id, status),
            KEY ix_claims_child (child_id, status),
            CONSTRAINT fk_claims_quest FOREIGN KEY (sidequest_id) REFERENCES sidequests (id) ON DELETE RESTRICT,
            CONSTRAINT fk_claims_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_claims_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_claims_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS sidequest_claim_slots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sidequest_id BIGINT UNSIGNED NOT NULL,
            recurrence_bucket VARCHAR(16) NOT NULL DEFAULT '',
            scope_key VARCHAR(24) NOT NULL,
            claim_id BIGINT UNSIGNED NOT NULL,
            UNIQUE KEY uq_claim_slot (sidequest_id, recurrence_bucket, scope_key),
            CONSTRAINT fk_slots_quest FOREIGN KEY (sidequest_id) REFERENCES sidequests (id) ON DELETE RESTRICT,
            CONSTRAINT fk_slots_claim FOREIGN KEY (claim_id) REFERENCES sidequest_claims (id) ON DELETE CASCADE
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS suggestions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            child_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            suggested_coins INT NOT NULL DEFAULT 0,
            comment TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            approved_coins INT NULL,
            approved_xp INT UNSIGNED NULL,
            parent_comment TEXT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            transaction_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY ix_suggestions_child_status (child_id, status),
            CONSTRAINT fk_suggestions_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_suggestions_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_suggestions_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS rewards (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            cost_coins INT UNSIGNED NOT NULL,
            duration_minutes INT UNSIGNED NULL,
            icon VARCHAR(50) NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS reward_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reward_id BIGINT UNSIGNED NULL,
            child_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            cost_coins INT UNSIGNED NOT NULL,
            duration_minutes INT UNSIGNED NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            parent_comment TEXT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            transaction_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY ix_reward_requests_child_status (child_id, status),
            CONSTRAINT fk_reward_requests_reward FOREIGN KEY (reward_id) REFERENCES rewards (id) ON DELETE RESTRICT,
            CONSTRAINT fk_reward_requests_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_reward_requests_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_reward_requests_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS milestones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            child_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            target_coins INT UNSIGNED NOT NULL,
            icon VARCHAR(50) NULL,
            spend_mode ENUM('spend','progress_only') NOT NULL DEFAULT 'spend',
            status ENUM('active','claimed','archived') NOT NULL DEFAULT 'active',
            claimed_at DATETIME NULL,
            transaction_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY ix_milestones_child_status (child_id, status),
            CONSTRAINT fk_milestones_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_milestones_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS milestone_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            child_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            suggested_coins INT UNSIGNED NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            parent_comment TEXT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            milestone_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY ix_milestone_requests_child (child_id, status),
            CONSTRAINT fk_milestone_requests_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_milestone_requests_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_milestone_requests_milestone FOREIGN KEY (milestone_id) REFERENCES milestones (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS achievements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(60) NOT NULL,
            title_key VARCHAR(100) NOT NULL,
            description_key VARCHAR(100) NOT NULL,
            icon VARCHAR(50) NULL,
            rarity ENUM('common','rare','epic','legendary') NOT NULL DEFAULT 'common',
            rule JSON NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_achievements_code (code)
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS child_achievements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            child_id BIGINT UNSIGNED NOT NULL,
            achievement_id BIGINT UNSIGNED NOT NULL,
            unlocked_at DATETIME NOT NULL,
            seen_at DATETIME NULL,
            UNIQUE KEY uq_child_achievement (child_id, achievement_id),
            CONSTRAINT fk_child_achievements_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE RESTRICT,
            CONSTRAINT fk_child_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES achievements (id) ON DELETE RESTRICT
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recipient_type VARCHAR(10) NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            payload JSON NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY ix_notifications_recipient (recipient_type, recipient_id, read_at)
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_type VARCHAR(10) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            subject_type VARCHAR(30) NULL,
            subject_id BIGINT UNSIGNED NULL,
            details JSON NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            KEY ix_audit_log_time (created_at)
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS update_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            from_version VARCHAR(20) NOT NULL,
            to_version VARCHAR(20) NOT NULL,
            status ENUM('success','failed','rolled_back') NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            log MEDIUMTEXT NULL,
            backup_file VARCHAR(255) NULL
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS backup_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            kind ENUM('manual','pre_update','emergency') NOT NULL DEFAULT 'manual',
            status ENUM('ok','failed','deleted') NOT NULL DEFAULT 'ok',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_backup_history_filename (filename)
        ) {$c}");

        $this->db->execute("CREATE TABLE IF NOT EXISTS ops_state (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            write_locked TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) {$c}");

        // Seed the single ops_state row with the gate unlocked.
        $this->db->execute(
            'INSERT INTO ops_state (id, write_locked, updated_at)
             VALUES (1, 0, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id = id'
        );
    }

    public function down(): void
    {
        // Reverse FK order.
        foreach ([
            'ops_state', 'backup_history', 'update_history', 'audit_log',
            'notifications', 'child_achievements', 'achievements',
            'milestone_requests', 'milestones', 'reward_requests', 'rewards',
            'suggestions', 'sidequest_claim_slots', 'sidequest_claims',
            'sidequests', 'point_templates', 'transactions', 'login_attempts',
            'auth_tokens', 'children', 'users', 'settings',
        ] as $table) {
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
    }
};
