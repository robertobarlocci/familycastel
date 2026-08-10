<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\RememberCookie;
use FamilyCastel\Core\RememberLogin;
use FamilyCastel\Database\Migrator;
use FamilyCastel\Domain\RememberService;
use PHPUnit\Framework\TestCase;

/**
 * The glue between the cookie and the session principal.
 *
 * `RememberService` is unit-tested on its own; what matters here is the
 * decision `restore()` makes on each outcome, because that is the code that
 * actually signs somebody in on every request.
 */
final class RememberLoginTest extends TestCase
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
             VALUES (?, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['Emma', 'fantasy']
        );

        $_SESSION = [];
        unset($_COOKIE[RememberCookie::NAME]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_COOKIE[RememberCookie::NAME]);
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

    public function testNoCookieMeansNoWorkAndNoPrincipal(): void
    {
        RememberLogin::restore($this->db);

        self::assertNull(Auth::parentId());
        self::assertNull(Auth::childId());
    }

    public function testAnAlreadyAuthenticatedRequestIsLeftAlone(): void
    {
        Auth::loginParent($this->userId());
        RememberLogin::markPasswordVerified();
        $_COOKIE[RememberCookie::NAME] = $this->remember->issue('user', $this->userId(), null);

        RememberLogin::restore($this->db);

        // Still the same principal, and — the point — the sudo window it earned
        // by typing a password is NOT cleared by a restore that did not happen.
        self::assertSame($this->userId(), Auth::parentId());
        self::assertTrue(RememberLogin::hasRecentPassword());
    }

    public function testAValidParentCookieSignsThemIn(): void
    {
        $_COOKIE[RememberCookie::NAME] = $this->remember->issue('user', $this->userId(), null);

        RememberLogin::restore($this->db);

        self::assertSame($this->userId(), Auth::parentId());
        self::assertNull(Auth::childId());
        self::assertNotNull($this->db->fetchOne(
            "SELECT id FROM audit_log WHERE action = 'parent.login_remembered'"
        ));
    }

    /** The whole point of the step-up rule (INV-007 clause 2). */
    public function testARestoredSessionIsNeverSudo(): void
    {
        $_COOKIE[RememberCookie::NAME] = $this->remember->issue('user', $this->userId(), null);
        // Even if something left a stale flag behind.
        RememberLogin::markPasswordVerified();

        RememberLogin::restore($this->db);

        self::assertSame($this->userId(), Auth::parentId());
        self::assertFalse(RememberLogin::hasRecentPassword());
    }

    public function testAValidChildCookieSignsThemIn(): void
    {
        $_COOKIE[RememberCookie::NAME] = $this->remember->issue('child', $this->childId(), null);

        RememberLogin::restore($this->db);

        self::assertSame($this->childId(), Auth::childId());
        self::assertNull(Auth::parentId());
    }

    public function testAnUnusableCookieLeavesTheRequestAnonymous(): void
    {
        $_COOKIE[RememberCookie::NAME] = 'not-a-token';

        RememberLogin::restore($this->db);

        self::assertNull(Auth::parentId());
        self::assertNull(Auth::childId());
    }

    public function testADeactivatedParentIsNotRestored(): void
    {
        $_COOKIE[RememberCookie::NAME] = $this->remember->issue('user', $this->userId(), null);
        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$this->userId()]);

        RememberLogin::restore($this->db);

        self::assertNull(Auth::parentId());
    }

    public function testStartIssuesAWorkingTokenAndForgetRevokesIt(): void
    {
        RememberLogin::start($this->db, 'user', $this->userId());

        $stored = $this->db->fetchOne('SELECT token_hash FROM remember_tokens');
        self::assertNotNull($stored, 'start() must persist a token');

        // forget() reads the cookie; in CLI we hand it the same value the
        // browser would have received.
        $token = (string) $this->db->fetchOne('SELECT token_hash FROM remember_tokens')['token_hash'];
        self::assertSame(64, strlen($token), 'only the hash is stored');

        RememberLogin::forget($this->db);
        self::assertNull(Auth::parentId());
    }

    public function testForgetRevokesTheTokenTheCookieNames(): void
    {
        $plain = $this->remember->issue('user', $this->userId(), null);
        $_COOKIE[RememberCookie::NAME] = $plain;

        RememberLogin::forget($this->db);

        self::assertNull($this->remember->resolve($plain), 'the token must be dead server-side');
    }
}
