<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Persistent login ("stay signed in") for parents and children.
 *
 * Shape follows the reviewed QR-token convention (AuthService::regenerateChildToken):
 * 32 random bytes as base64url, sha256 at rest, strict shape check before any
 * query. Two things are specific to this credential:
 *
 * 1. It rotates on every use, so a stolen cookie stops working as soon as the
 *    real device visits again — and reuse of a superseded token after the grace
 *    window is treated as theft and revokes the row.
 * 2. Rotation is a COMPARE-AND-SWAP carrying every precondition (current hash,
 *    not revoked, not expired, principal still eligible) inside one statement.
 *    A cold page load fires parallel requests, so "two actors, one token" is the
 *    normal case here, not the exotic one; deciding from a SELECT that has
 *    already gone stale is how such code authenticates revoked credentials.
 *
 * Operational table (like login_attempts): pruned opportunistically because
 * shared hosting has no cron, and deliberately NOT wrapped in WriteGate — every
 * page load would otherwise take the write gate and logins would fail during a
 * backup drain.
 */
final class RememberService
{
    /** Sliding window. Chromium caps persistent cookies near 400 days anyway. */
    public const LIFETIME_DAYS = 400;

    /** Parallel requests from one page load must not log each other out. */
    private const GRACE_SECONDS = 60;

    public function __construct(private readonly Db $db)
    {
    }

    /** Issue a token for a principal. Returns the PLAINTEXT exactly once. */
    public function issue(string $principalType, int $principalId, ?string $label): string
    {
        $token = self::newToken();

        $this->db->execute(
            'INSERT INTO remember_tokens
                (principal_type, user_id, child_id, token_hash, label, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? DAY)',
            [
                $principalType,
                $principalType === 'user' ? $principalId : null,
                $principalType === 'child' ? $principalId : null,
                hash('sha256', $token),
                $label,
                self::LIFETIME_DAYS,
            ]
        );

        $this->pruneOccasionally();

        return $token;
    }

    /**
     * Resolve a cookie value to its principal, rotating the token.
     * Returns null whenever the caller must fall back to anonymous — in which
     * case the caller also clears the cookie.
     */
    public function resolve(string $token, ?string $ip = null): ?RememberResult
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null; // Not our shape — never touch the database for it.
        }

        $hash = hash('sha256', $token);

        $row = $this->db->fetchOne(
            'SELECT id, principal_type, user_id, child_id, token_hash, previous_hash, rotated_at
               FROM remember_tokens
              WHERE token_hash = ? OR previous_hash = ?',
            [$hash, $hash]
        );

        if ($row === null) {
            return null;
        }

        return hash_equals((string) $row['token_hash'], $hash)
            ? $this->rotate($row, $hash, $ip)
            : $this->acceptSupersededOrDetectTheft($row, $hash, $ip);
    }

    /**
     * The current token: compare-and-swap it forward. Every precondition lives
     * in the WHERE — including eligibility, which lives in another table — so
     * rowCount()===1 means "was valid at the instant of the write, and we won".
     */
    private function rotate(array $row, string $hash, ?string $ip): ?RememberResult
    {
        $fresh = self::newToken();

        $updated = $this->db->execute(
            'UPDATE remember_tokens t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN children c ON c.id = t.child_id
                SET t.previous_hash = t.token_hash,
                    t.token_hash    = ?,
                    t.rotated_at    = UTC_TIMESTAMP(),
                    t.last_used_at  = UTC_TIMESTAMP(),
                    t.expires_at    = UTC_TIMESTAMP() + INTERVAL ? DAY
              WHERE t.id = ? AND t.token_hash = ?
                AND t.revoked_at IS NULL
                AND t.expires_at > UTC_TIMESTAMP()
                AND ((t.principal_type = \'user\' AND u.is_active = 1 AND u.role = \'parent\')
                  OR (t.principal_type = \'child\' AND c.archived_at IS NULL))',
            [hash('sha256', $fresh), self::LIFETIME_DAYS, $row['id'], $hash]
        );

        if ($updated === 1) {
            return new RememberResult($this->principalType($row), $this->principalId($row), $fresh);
        }

        // Zero rows is AMBIGUOUS: a sibling may have rotated first, or the row
        // may have been revoked, expired or made ineligible since the SELECT.
        // Re-read and decide from fresh state; never infer consent from a count.
        return $this->acceptSupersededOrDetectTheft($this->reload((int) $row['id']), $hash, $ip);
    }

    /**
     * A token matching previous_hash. Inside the grace window that is the
     * sibling request of one page load; outside it, two parties hold the same
     * cookie — which is theft, and costs the whole row.
     */
    private function acceptSupersededOrDetectTheft(?array $row, string $hash, ?string $ip): ?RememberResult
    {
        if ($row === null || !$this->isLive($row)) {
            return null;
        }

        if (!is_string($row['previous_hash']) || !hash_equals($row['previous_hash'], $hash)) {
            return null;
        }

        $rotatedAt = $row['rotated_at'] === null ? null : strtotime((string) $row['rotated_at'] . ' UTC');
        if ($rotatedAt === null || $rotatedAt === false) {
            return null;
        }

        if (time() - $rotatedAt > self::GRACE_SECONDS) {
            $this->db->execute(
                'UPDATE remember_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL',
                [$row['id']]
            );
            (new AuditService($this->db))->log(
                $this->principalType($row) === 'user' ? 'user' : 'child',
                $this->principalId($row),
                'remember.reuse_detected',
                details: ['token_id' => (int) $row['id']],
                ip: $ip
            );

            return null;
        }

        // Accepted, but NOT re-issued: only hashes are stored, so the sole thing
        // we could hand back is the superseded token — which would clobber the
        // live cookie the winning request just set.
        return new RememberResult($this->principalType($row), $this->principalId($row), null);
    }

    public function revoke(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return;
        }

        $hash = hash('sha256', $token);
        $this->db->execute(
            'UPDATE remember_tokens SET revoked_at = UTC_TIMESTAMP()
              WHERE (token_hash = ? OR previous_hash = ?) AND revoked_at IS NULL',
            [$hash, $hash]
        );
    }

    public function revokeAllFor(string $principalType, int $principalId): void
    {
        $column = $principalType === 'user' ? 'user_id' : 'child_id';
        $this->db->execute(
            "UPDATE remember_tokens SET revoked_at = UTC_TIMESTAMP()
              WHERE principal_type = ? AND {$column} = ? AND revoked_at IS NULL",
            [$principalType, $principalId]
        );
    }

    /**
     * Every device signs in again. Called after a database restore: a snapshot
     * predating a revocation would otherwise put a revoked — possibly stolen —
     * token back into a live table.
     */
    public function revokeAll(): void
    {
        $this->db->execute('UPDATE remember_tokens SET revoked_at = UTC_TIMESTAMP() WHERE revoked_at IS NULL');
    }

    public function prune(): void
    {
        $this->db->execute(
            'DELETE FROM remember_tokens
              WHERE expires_at < UTC_TIMESTAMP()
                 OR (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)'
        );
    }

    private function reload(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT t.id, t.principal_type, t.user_id, t.child_id, t.token_hash, t.previous_hash,
                    t.rotated_at, t.revoked_at, t.expires_at,
                    u.is_active, u.role, c.archived_at
               FROM remember_tokens t
               LEFT JOIN users u ON u.id = t.user_id
               LEFT JOIN children c ON c.id = t.child_id
              WHERE t.id = ?',
            [$id]
        );
    }

    /** Live = not revoked, not expired, and the principal is still eligible. */
    private function isLive(array $row): bool
    {
        if (!array_key_exists('revoked_at', $row)) {
            $row = $this->reload((int) $row['id']) ?? [];
            if ($row === []) {
                return false;
            }
        }

        if ($row['revoked_at'] !== null) {
            return false;
        }

        $expires = strtotime((string) $row['expires_at'] . ' UTC');
        if ($expires === false || $expires <= time()) {
            return false;
        }

        return $row['principal_type'] === 'user'
            ? (int) ($row['is_active'] ?? 0) === 1 && ($row['role'] ?? '') === 'parent'
            : ($row['archived_at'] ?? null) === null;
    }

    private function principalType(array $row): string
    {
        return (string) $row['principal_type'];
    }

    private function principalId(array $row): int
    {
        return (int) ($row['principal_type'] === 'user' ? $row['user_id'] : $row['child_id']);
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** No cron on shared hosting — same 1% pattern as login_attempts. */
    private function pruneOccasionally(): void
    {
        if (random_int(1, 100) === 1) {
            $this->prune();
        }
    }
}
