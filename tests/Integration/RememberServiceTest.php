<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\RememberService;
use PHPUnit\Framework\TestCase;

/**
 * Persistent-login tokens.
 *
 * The interesting cases are all races. A remember token is resolved on a cold
 * page load, which fires several parallel requests, so "two actors, one token"
 * is the normal case rather than the exotic one — and the rotation must never
 * let a revoked, expired or ineligible credential through just because two
 * requests arrived together.
 */
final class RememberServiceTest extends TestCase
{
    private Db $db;
    private RememberService $remember;

    protected function setUp(): void
    {
        $this->db = Db::fromParams(
            host: getenv('FC_TEST_DB_HOST') ?: '127.0.0.1',
            port: (int) (getenv('FC_TEST_DB_PORT') ?: 3306),
            name: getenv('FC_TEST_DB_NAME') ?: 'familycastel_test',
            user: getenv('FC_TEST_DB_USER') ?: 'fc',
            password: getenv('FC_TEST_DB_PASS') ?: 'fc-dev-password',
        );
        $this->wipe();
        (new Migrator($this->db, FC_ROOT . '/app/Database/Migrations'))->migrate();
        $this->remember = new RememberService($this->db);

        $this->db->execute(
            'INSERT INTO users (name, username, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Alex', 'alex', password_hash('correct-horse-battery', PASSWORD_DEFAULT), 'parent']
        );
        $this->db->execute(
            'INSERT INTO children (name, theme, pin_hash, created_at, updated_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Emma', 'fantasy', password_hash('1234', PASSWORD_DEFAULT)]
        );
    }

    protected function tearDown(): void
    {
        $this->wipe();
    }

    private function wipe(): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW TABLES') as $row) {
            $table = str_replace('`', '``', (string) array_values($row)[0]);
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function userId(): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM users LIMIT 1')['id'];
    }

    private function childId(): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM children LIMIT 1')['id'];
    }

    private function rowFor(string $token): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM remember_tokens WHERE token_hash = ? OR previous_hash = ?',
            [hash('sha256', $token), hash('sha256', $token)]
        );
    }

    // ---------------------------------------------------------------- issue

    public function testIssueReturnsABase64UrlTokenAndStoresOnlyItsHash(): void
    {
        $token = $this->remember->issue('user', $this->userId(), 'phone');

        self::assertSame(43, strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);

        $row = $this->db->fetchOne('SELECT * FROM remember_tokens');
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        // The plaintext must appear nowhere in the row.
        self::assertStringNotContainsString($token, json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testResolveReturnsTheParent(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);

        $result = $this->remember->resolve($token);

        self::assertNotNull($result);
        self::assertSame('user', $result->principalType);
        self::assertSame($this->userId(), $result->principalId);
    }

    public function testResolveReturnsTheChild(): void
    {
        $token = $this->remember->issue('child', $this->childId(), null);

        $result = $this->remember->resolve($token);

        self::assertNotNull($result);
        self::assertSame('child', $result->principalType);
        self::assertSame($this->childId(), $result->principalId);
    }

    // ------------------------------------------------------------- rotation

    public function testResolveRotatesAndTheOldTokenStopsWorkingAfterTheGrace(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);

        $first = $this->remember->resolve($token);
        self::assertNotNull($first);
        self::assertNotNull($first->newToken, 'the winning rotation must hand back a new token');
        self::assertNotSame($token, $first->newToken);

        // The new token works.
        self::assertNotNull($this->remember->resolve($first->newToken));

        // Age the rotation past the grace window; the original must now be dead.
        $this->ageRotation($first->newToken, 120);
        self::assertNull($this->remember->resolve($token));
    }

    public function testTheGraceWindowAcceptsThePreviousTokenAndIssuesNoNewCookie(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $first = $this->remember->resolve($token);
        self::assertNotNull($first);

        $rotatedAtBefore = $this->rowFor($token)['rotated_at'];

        // The sibling request of the same cold page load, still holding the old token.
        $second = $this->remember->resolve($token);

        self::assertNotNull($second, 'a parallel request must not be logged out');
        self::assertSame($this->userId(), $second->principalId);
        self::assertNull($second->newToken, 'the grace path cannot re-issue: only hashes are stored');
        self::assertSame($rotatedAtBefore, $this->rowFor($token)['rotated_at'], 'it must not rotate again');
    }

    public function testReuseAfterTheGraceIsTreatedAsTheftAndRevokesTheToken(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $first = $this->remember->resolve($token);
        self::assertNotNull($first);

        $this->ageRotation($first->newToken, 120);

        self::assertNull($this->remember->resolve($token), 'a stale token must not authenticate');

        // Both halves are now dead — the family re-authenticates, the thief gains nothing.
        self::assertNull($this->remember->resolve($first->newToken));
        self::assertNotNull($this->db->fetchOne(
            "SELECT id FROM audit_log WHERE action = 'remember.reuse_detected'"
        ));
    }

    // ------------------------------------------------------------- rejection

    public function testMalformedTokensAreRejectedWithoutAQuery(): void
    {
        self::assertNull($this->remember->resolve(''));
        self::assertNull($this->remember->resolve('garbage'));
        self::assertNull($this->remember->resolve(str_repeat('a', 44)));
        self::assertNull($this->remember->resolve('has spaces and !!! symbols in it aaaaaaaaaaa'));
        // Well-formed but unknown.
        self::assertNull($this->remember->resolve(str_repeat('A', 43)));
    }

    public function testADeactivatedParentCannotBeRestored(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$this->userId()]);

        self::assertNull($this->remember->resolve($token));
    }

    public function testAnArchivedChildCannotBeRestored(): void
    {
        $token = $this->remember->issue('child', $this->childId(), null);
        $this->db->execute('UPDATE children SET archived_at = UTC_TIMESTAMP() WHERE id = ?', [$this->childId()]);

        self::assertNull($this->remember->resolve($token));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $this->db->execute('UPDATE remember_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY');

        self::assertNull($this->remember->resolve($token));
    }

    public function testARevokedTokenIsRejected(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $this->remember->revoke($token);

        self::assertNull($this->remember->resolve($token));
    }

    // --------------------------------------------------------------- revoke

    public function testRevokeAllForInvalidatesEveryTokenOfThatPrincipal(): void
    {
        $a = $this->remember->issue('user', $this->userId(), 'phone');
        $b = $this->remember->issue('user', $this->userId(), 'laptop');
        $childToken = $this->remember->issue('child', $this->childId(), null);

        $this->remember->revokeAllFor('user', $this->userId());

        self::assertNull($this->remember->resolve($a));
        self::assertNull($this->remember->resolve($b));
        self::assertNotNull($this->remember->resolve($childToken), 'other principals are untouched');
    }

    /**
     * Archiving alone only makes a token unusable — the row stays unrevoked, so
     * a copy that is never presented during the archived window would come back
     * to life on un-archive. The archive action must therefore revoke, and this
     * pins that: unusable-while-archived is not the same as dead.
     */
    public function testArchivingMustRevokeNotMerelyBlock(): void
    {
        $token = $this->remember->issue('child', $this->childId(), null);

        // The state the controller is responsible for producing.
        $this->db->execute('UPDATE children SET archived_at = UTC_TIMESTAMP() WHERE id = ?', [$this->childId()]);
        $this->remember->revokeAllFor('child', $this->childId());

        // Un-archiving must NOT bring the old login back.
        $this->db->execute('UPDATE children SET archived_at = NULL WHERE id = ?', [$this->childId()]);

        self::assertNull($this->remember->resolve($token));
    }

    public function testRevokeAllInvalidatesEverything(): void
    {
        $parent = $this->remember->issue('user', $this->userId(), null);
        $child = $this->remember->issue('child', $this->childId(), null);

        $this->remember->revokeAll();

        self::assertNull($this->remember->resolve($parent));
        self::assertNull($this->remember->resolve($child));
    }

    public function testPruningRemovesExpiredRowsAndKeepsLiveOnes(): void
    {
        $live = $this->remember->issue('user', $this->userId(), null);
        $this->remember->issue('child', $this->childId(), null);
        $this->db->execute(
            'UPDATE remember_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE principal_type = ?',
            ['child']
        );

        $this->remember->prune();

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) AS c FROM remember_tokens')['c']);
        self::assertNotNull($this->remember->resolve($live));
    }

    public function testExpirySlidesForwardOnRotation(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $this->db->execute('UPDATE remember_tokens SET expires_at = UTC_TIMESTAMP() + INTERVAL 5 DAY');

        $this->remember->resolve($token);

        $days = (int) $this->db->fetchOne(
            'SELECT DATEDIFF(expires_at, UTC_TIMESTAMP()) AS d FROM remember_tokens'
        )['d'];
        self::assertGreaterThan(300, $days, 'an active device must not drift towards expiry');
    }

    public function testTheCheckConstraintRejectsAMalformedPrincipal(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->execute(
            'INSERT INTO remember_tokens (principal_type, user_id, child_id, token_hash, created_at, expires_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 1 DAY)',
            ['user', $this->userId(), $this->childId(), str_repeat('a', 64)]
        );
    }

    // ------------------------------------------- races against the CAS write

    public function testRevocationBeatsALosingCas(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);

        // A sibling request rotates first…
        $winner = $this->remember->resolve($token);
        self::assertNotNull($winner);
        // …and then the token is revoked (logout elsewhere / theft / restore)
        // before our slow request gets to use the grace window.
        $this->db->execute('UPDATE remember_tokens SET revoked_at = UTC_TIMESTAMP()');

        self::assertNull($this->remember->resolve($token), 'revocation must win over the grace window');
    }

    public function testExpiryBeatsALosingCas(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        self::assertNotNull($this->remember->resolve($token));
        $this->db->execute('UPDATE remember_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');

        self::assertNull($this->remember->resolve($token));
    }

    public function testIneligibilityBeatsALosingCas(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        self::assertNotNull($this->remember->resolve($token));
        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$this->userId()]);

        self::assertNull($this->remember->resolve($token));
    }

    /**
     * The winning path needs the same rigour as the losing one: without
     * expires_at in the CAS WHERE, this rotation would resurrect an expired
     * credential by stamping a fresh 400-day expiry onto it.
     */
    public function testExpiryBeatsAWinningCasAndDoesNotExtendTheToken(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $this->db->execute('UPDATE remember_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');

        self::assertNull($this->remember->resolve($token));

        $stillExpired = (int) $this->db->fetchOne(
            'SELECT (expires_at <= UTC_TIMESTAMP()) AS expired FROM remember_tokens'
        )['expired'];
        self::assertSame(1, $stillExpired, 'a failed CAS must not have extended the expiry');
    }

    public function testDeactivationBeatsAWinningCas(): void
    {
        $token = $this->remember->issue('user', $this->userId(), null);
        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$this->userId()]);

        self::assertNull($this->remember->resolve($token));

        $rotated = $this->db->fetchOne('SELECT rotated_at FROM remember_tokens')['rotated_at'];
        self::assertNull($rotated, 'an ineligible principal must not rotate the token either');
    }

    /** Push rotated_at into the past so the grace window has demonstrably closed. */
    private function ageRotation(string $currentToken, int $seconds): void
    {
        $this->db->execute(
            'UPDATE remember_tokens SET rotated_at = UTC_TIMESTAMP() - INTERVAL ? SECOND WHERE token_hash = ?',
            [$seconds, hash('sha256', $currentToken)]
        );
    }
}
