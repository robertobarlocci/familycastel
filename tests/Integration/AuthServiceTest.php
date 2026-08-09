<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private Db $db;
    private AuthService $auth;

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
        $this->auth = new AuthService($this->db);

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

    private function childId(): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM children LIMIT 1')['id'];
    }

    public function testParentLoginSucceedsWithCorrectPassword(): void
    {
        $user = $this->auth->attemptParentLogin('alex', 'correct-horse-battery', '10.0.0.1');
        self::assertNotNull($user);
        self::assertSame('alex', $user['username']);
    }

    public function testParentLoginFailsWithWrongPassword(): void
    {
        self::assertNull($this->auth->attemptParentLogin('alex', 'wrong', '10.0.0.1'));
    }

    public function testParentLoginFailsForUnknownUserWithoutEnumerationTiming(): void
    {
        // Same code path (dummy hash verify) — just assert behavior here.
        self::assertNull($this->auth->attemptParentLogin('nobody', 'whatever', '10.0.0.1'));
    }

    public function testParentLoginFailsForInactiveUser(): void
    {
        $this->db->execute('UPDATE users SET is_active = 0 WHERE username = ?', ['alex']);
        self::assertNull($this->auth->attemptParentLogin('alex', 'correct-horse-battery', '10.0.0.1'));
    }

    public function testThrottleLocksAfterFiveFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->auth->attemptParentLogin('alex', 'wrong', '10.0.0.2'));
        }
        // Even the CORRECT password is now rejected while throttled.
        self::assertNull($this->auth->attemptParentLogin('alex', 'correct-horse-battery', '10.0.0.2'));
        self::assertTrue($this->auth->isThrottled('user', 'alex'));
    }

    public function testSuccessfulLoginResetsUserWindowButNeverIpWindow(): void
    {
        // Distinct IPs per phase — the USER window resets on success, but IP
        // failure counting deliberately never resets (spray protection).
        for ($i = 0; $i < 3; $i++) {
            $this->auth->attemptParentLogin('alex', 'wrong', '10.0.1.1');
        }
        $user = $this->auth->attemptParentLogin('alex', 'correct-horse-battery', '10.0.1.2');
        self::assertNotNull($user);
        // After the success, four MORE failures stay under the user limit…
        for ($i = 0; $i < 4; $i++) {
            $this->auth->attemptParentLogin('alex', 'wrong', '10.0.1.3');
        }
        // …so the correct password still works from a clean IP.
        self::assertNotNull($this->auth->attemptParentLogin('alex', 'correct-horse-battery', '10.0.1.4'));
    }

    public function testChildPinLogin(): void
    {
        $childId = $this->childId();
        self::assertNotNull($this->auth->attemptChildPin($childId, '1234', '10.0.0.4'));
        self::assertNull($this->auth->attemptChildPin($childId, '9999', '10.0.0.4'));
    }

    public function testChildPinThrottlesFast(): void
    {
        $childId = $this->childId();
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attemptChildPin($childId, '0000', '10.0.0.5');
        }
        self::assertNull($this->auth->attemptChildPin($childId, '1234', '10.0.0.5'));
    }

    public function testArchivedChildCannotLogIn(): void
    {
        $childId = $this->childId();
        $this->db->execute('UPDATE children SET archived_at = UTC_TIMESTAMP() WHERE id = ?', [$childId]);
        self::assertNull($this->auth->attemptChildPin($childId, '1234', '10.0.0.6'));
    }

    public function testQrTokenLifecycle(): void
    {
        $childId = $this->childId();
        $parentId = (int) $this->db->fetchOne('SELECT id FROM users LIMIT 1')['id'];

        $token = $this->auth->regenerateChildToken($childId, $parentId);
        self::assertGreaterThanOrEqual(43, strlen($token)); // 32 bytes base64url

        $resolved = $this->auth->childForToken($token);
        self::assertNotNull($resolved);
        self::assertSame($childId, (int) $resolved['id']);

        // Regeneration revokes the old token.
        $newToken = $this->auth->regenerateChildToken($childId, $parentId);
        self::assertNull($this->auth->childForToken($token));
        self::assertNotNull($this->auth->childForToken($newToken));

        // Garbage tokens resolve to nothing.
        self::assertNull($this->auth->childForToken('garbage'));
        self::assertNull($this->auth->childForToken(''));
    }

    public function testQrTokenForArchivedChildIsRejected(): void
    {
        $childId = $this->childId();
        $parentId = (int) $this->db->fetchOne('SELECT id FROM users LIMIT 1')['id'];
        $token = $this->auth->regenerateChildToken($childId, $parentId);
        $this->db->execute('UPDATE children SET archived_at = UTC_TIMESTAMP() WHERE id = ?', [$childId]);

        self::assertNull($this->auth->childForToken($token));
    }

    public function testChildWithoutPinTapLogin(): void
    {
        $this->db->execute(
            'INSERT INTO children (name, theme, pin_hash, created_at, updated_at)
             VALUES (?, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Noah', 'football']
        );
        $noahId = (int) $this->db->fetchOne('SELECT id FROM children WHERE name = ?', ['Noah'])['id'];

        self::assertNotNull($this->auth->childWithoutPin($noahId));
        // A child WITH a PIN must never pass the no-PIN path.
        self::assertNull($this->auth->childWithoutPin($this->childId()));
        // No failed attempts recorded by the tap-login path.
        $count = $this->db->fetchOne('SELECT COUNT(*) AS c FROM login_attempts')['c'];
        self::assertSame(0, (int) $count);
    }

    public function testParentSuccessDoesNotResetIpWindow(): void
    {
        // 4 failures for one account from an IP…
        for ($i = 0; $i < 4; $i++) {
            $this->auth->attemptParentLogin('alex', 'wrong', '10.0.0.9');
        }
        // …a success on a DIFFERENT valid account from the same IP…
        $this->db->execute(
            'INSERT INTO users (name, username, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Sam', 'sam', password_hash('another-good-password', PASSWORD_DEFAULT), 'parent']
        );
        self::assertNotNull($this->auth->attemptParentLogin('sam', 'another-good-password', '10.0.0.9'));
        // …must NOT reset the IP failure window: one more failure trips it.
        $this->auth->attemptParentLogin('alex', 'wrong', '10.0.0.9');
        self::assertTrue($this->auth->isThrottled('ip', '10.0.0.9'));
    }

    public function testPasswordRehashUpgradesWeakHash(): void
    {
        // Simulate an old, low-cost hash.
        $old = password_hash('correct-horse-battery', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->db->execute('UPDATE users SET password_hash = ? WHERE username = ?', [$old, 'alex']);

        $user = $this->auth->attemptParentLogin('alex', 'correct-horse-battery', '10.0.0.7');
        self::assertNotNull($user);

        $stored = $this->db->fetchOne('SELECT password_hash FROM users WHERE username = ?', ['alex'])['password_hash'];
        self::assertNotSame($old, $stored, 'hash must be transparently upgraded on login');
        self::assertTrue(password_verify('correct-horse-battery', $stored));
    }
}
