<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Authentication: parent credentials, child PINs, child QR tokens.
 * Throttling is DB-backed (login_attempts) — no Redis on shared hosting.
 * All checks are constant-time where secrets are involved; unknown users
 * still burn a dummy password_verify (no enumeration via timing).
 */
final class AuthService
{
    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null the user row on success */
    public function attemptParentLogin(string $usernameOrEmail, string $password, string $ip): ?array
    {
        $subject = strtolower(trim($usernameOrEmail));

        // Serialize check+verify+record per subject AND per IP so concurrent
        // requests (same account or a spray across accounts from one IP)
        // cannot all slip past the pre-checks before failures are recorded.
        $user = $this->withAuthLocks(['u:' . $subject, 'ip:' . $ip], function () use ($subject, $password, $ip): ?array {
            if ($this->isThrottled('user', $subject) || $this->isThrottled('ip', $ip)) {
                return null;
            }

            $user = $this->db->fetchOne(
                'SELECT * FROM users WHERE (username = ? OR email = ?) AND role = ?',
                [$subject, $subject, 'parent']
            );

            $hash = $user['password_hash'] ?? self::dummyHash();
            $valid = password_verify($password, $hash)
                && $user !== null
                && (int) $user['is_active'] === 1;

            $this->recordAttempt('user', $subject, $valid);
            $this->recordAttempt('ip', $ip, $valid);

            return $valid ? $user : null;
        });

        if ($user === null) {
            return null;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->execute(
                'UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $user['id']]
            );
        }
        $this->db->execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);

        return $user;
    }

    /** @return array<string, mixed>|null the child row on success */
    public function attemptChildPin(int $childId, string $pin, string $ip): ?array
    {
        $subject = 'child:' . $childId;

        return $this->withAuthLocks(['c:' . $childId, 'ip:' . $ip], function () use ($subject, $childId, $pin, $ip): ?array {
            if ($this->isThrottled('child', $subject) || $this->isThrottled('ip', $ip)) {
                return null;
            }

            $child = $this->db->fetchOne(
                'SELECT * FROM children WHERE id = ? AND archived_at IS NULL',
                [$childId]
            );

            // Always burn a verify — missing/archived/PIN-less children must be
            // timing-indistinguishable from a wrong PIN.
            $hash = $child['pin_hash'] ?? self::dummyHash();
            $verified = password_verify($pin, $hash);
            $valid = $child !== null && $child['pin_hash'] !== null && $verified;

            $this->recordAttempt('child', $subject, $valid);
            $this->recordAttempt('ip', $ip, $valid);

            return $valid ? $child : null;
        });
    }

    /**
     * Tap-to-enter login for children whose parents chose no PIN. Not a
     * secret-guessing surface (nothing to guess), so it records no failed
     * attempts — legitimate no-PIN logins must never throttle a sibling's
     * PIN attempts sharing the same IP.
     *
     * @return array<string, mixed>|null
     */
    public function childWithoutPin(int $childId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM children WHERE id = ? AND archived_at IS NULL AND pin_hash IS NULL',
            [$childId]
        );
    }

    /**
     * Resolve a QR login token to its child. Tokens are stored as sha256
     * hashes; lookup is by hash (indexed), so no timing side channel exists.
     *
     * @return array<string, mixed>|null
     */
    public function childForToken(string $token): ?array
    {
        // Exactly the generated shape: 32 bytes base64url without padding.
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT c.* FROM auth_tokens t
             JOIN children c ON c.id = t.child_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND c.archived_at IS NULL',
            [hash('sha256', $token)]
        );

        if ($row !== null) {
            $this->db->execute(
                'UPDATE auth_tokens SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = ?',
                [hash('sha256', $token)]
            );
        }

        return $row;
    }

    /**
     * Issue a fresh QR token for a child, revoking all previous ones.
     * Returns the PLAINTEXT token exactly once — only its hash is stored.
     */
    public function regenerateChildToken(int $childId, int $byUserId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        WriteGate::transaction($this->db, function (Db $db) use ($childId, $byUserId, $token): void {
            $db->execute(
                'UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP() WHERE child_id = ? AND revoked_at IS NULL',
                [$childId]
            );
            $db->execute(
                'INSERT INTO auth_tokens (child_id, token_hash, label, created_by, created_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                [$childId, hash('sha256', $token), 'qr-login', $byUserId]
            );
        });

        return $token;
    }

    public function isThrottled(string $subjectType, string $subjectKey): bool
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS failures FROM login_attempts
             WHERE subject_type = ? AND subject_key = ? AND success = 0
               AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE
               AND attempted_at > COALESCE(
                   (SELECT MAX(attempted_at) FROM login_attempts la2
                    WHERE la2.subject_type = ? AND la2.subject_key = ? AND la2.success = 1),
                   \'1970-01-01\')',
            [$subjectType, $subjectKey, self::WINDOW_MINUTES, $subjectType, $subjectKey]
        );

        return (int) ($row['failures'] ?? 0) >= self::MAX_FAILURES;
    }

    private function recordAttempt(string $subjectType, string $subjectKey, bool $success): void
    {
        // IP subjects record FAILURES ONLY: a success row would reset the IP
        // window, letting an attacker with one valid credential interleave
        // successes while spraying other accounts from the same IP.
        if ($success && $subjectType === 'ip') {
            return;
        }

        $this->db->execute(
            'INSERT INTO login_attempts (subject_type, subject_key, success, attempted_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$subjectType, $subjectKey, $success ? 1 : 0]
        );

        // Opportunistic pruning of old OPERATIONAL rows (not Journal data):
        // ~1% of logins clear entries older than a day.
        if (random_int(1, 100) === 1) {
            $this->db->execute(
                'DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
            );
        }
    }

    /**
     * MariaDB advisory locks serializing auth attempts. Keys are acquired in
     * the given order at every call site (subject first, then ip) — a fixed
     * global order, so no deadlock. MariaDB 10.11 supports multiple
     * user-level locks per connection.
     *
     * @param list<string> $keys
     */
    private function withAuthLocks(array $keys, callable $fn): mixed
    {
        $held = [];
        try {
            foreach ($keys as $key) {
                $lockName = 'fc_auth_' . substr(hash('sha256', $key), 0, 32);
                $acquired = $this->db->fetchOne('SELECT GET_LOCK(?, 3) AS ok', [$lockName]);
                if ((int) ($acquired['ok'] ?? 0) !== 1) {
                    return null; // contention = failure — never bypass throttling
                }
                $held[] = $lockName;
            }

            return $fn();
        } finally {
            foreach (array_reverse($held) as $lockName) {
                $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        }
    }

    private static function dummyHash(): string
    {
        // Precomputed constant — generating a hash per request would make
        // unknown-user requests measurably SLOWER (hash+verify vs verify),
        // an enumeration signal. The plaintext is random and discarded.
        return '$2y$10$FPxgWhELaNgBoAturSmztu9supJCwsLZlHOHeEU21/JqLXxHJ5IXy';
    }
}
