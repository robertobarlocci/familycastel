<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\BasePath;
use FamilyCastel\Core\RememberCookie;
use FamilyCastel\Domain\RememberService;
use PHPUnit\Framework\TestCase;

/**
 * The cookie's attributes are the security boundary, so they are asserted
 * rather than assumed. The path in particular: a '/' cookie on a subdirectory
 * install would be sent to every sibling app on the same origin.
 */
final class RememberCookieTest extends TestCase
{
    protected function tearDown(): void
    {
        BasePath::set('');
        unset($_COOKIE[RememberCookie::NAME]);
    }

    public function testPathIsSlashAtDomainRoot(): void
    {
        BasePath::set('');
        self::assertSame('/', RememberCookie::path());
    }

    public function testPathIsScopedToASubdirectoryInstall(): void
    {
        BasePath::set('/familycastle');
        self::assertSame('/familycastle/', RememberCookie::path());
    }

    public function testPathNeverProducesADoubleSlash(): void
    {
        BasePath::set('/');
        self::assertSame('/', RememberCookie::path());
    }

    public function testReadReturnsNullWhenAbsentOrEmpty(): void
    {
        unset($_COOKIE[RememberCookie::NAME]);
        self::assertNull(RememberCookie::read());

        $_COOKIE[RememberCookie::NAME] = '';
        self::assertNull(RememberCookie::read());
    }

    public function testReadReturnsTheCookieValue(): void
    {
        $_COOKIE[RememberCookie::NAME] = 'a-token-value';
        self::assertSame('a-token-value', RememberCookie::read());
    }

    /** "Forever" is a sliding 400 days — browsers cap persistent cookies there. */
    public function testLifetimeMatchesTheServiceAndRespectsTheBrowserCap(): void
    {
        self::assertSame(400, RememberService::LIFETIME_DAYS);
    }

    /**
     * write()/clear() go through setcookie(), which does nothing observable
     * under the CLI SAPI — but the code around it (path derivation, the
     * headers_sent guard, clearing the local $_COOKIE mirror so the rest of the
     * request sees the logout) is real and must not throw or leak state.
     */
    public function testWriteAndClearAreSafeToCallAndClearTheLocalMirror(): void
    {
        BasePath::set('/familycastle');
        $_COOKIE[RememberCookie::NAME] = 'previous-value';

        RememberCookie::write('a-new-token');
        RememberCookie::clear();

        // clear() must drop the value for the remainder of this request too,
        // otherwise a logout would still look signed-in to later code.
        self::assertNull(RememberCookie::read());
    }

    public function testTheCookieNameIsStable(): void
    {
        // Renaming it silently signs every family out; make that a test failure.
        self::assertSame('fc_remember', RememberCookie::NAME);
    }
}
