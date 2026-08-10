<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\RememberLogin;
use PHPUnit\Framework\TestCase;

/**
 * The step-up window (INV-007 clause 2).
 *
 * The load-bearing assertion is the negative one: a session restored from a
 * cookie must NOT be treated as having proved a password. That is the whole
 * reason a stolen phone cannot wipe or download the family's installation.
 */
final class SudoWindowTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAFreshSessionHasNotProvedAPassword(): void
    {
        self::assertFalse(RememberLogin::hasRecentPassword());
    }

    public function testMarkingAPasswordVerifiedOpensTheWindow(): void
    {
        RememberLogin::markPasswordVerified();
        self::assertTrue(RememberLogin::hasRecentPassword());
    }

    public function testTheWindowExpires(): void
    {
        RememberLogin::markPasswordVerified();
        $_SESSION['auth_sudo_at'] = time() - RememberLogin::SUDO_WINDOW_SECONDS - 1;

        self::assertFalse(RememberLogin::hasRecentPassword());
    }

    public function testAGarbageTimestampIsNotAWindow(): void
    {
        $_SESSION['auth_sudo_at'] = 'soon';
        self::assertFalse(RememberLogin::hasRecentPassword());

        $_SESSION['auth_sudo_at'] = null;
        self::assertFalse(RememberLogin::hasRecentPassword());
    }

    public function testTheReturnPathOnlyAcceptsAnAppInternalPath(): void
    {
        RememberLogin::rememberReturnPath('/parent/settings/diagnostics');
        self::assertSame('/parent/settings/diagnostics', RememberLogin::takeReturnPath());

        // Consumed on read, so a stale target cannot redirect a later confirm.
        self::assertNull(RememberLogin::takeReturnPath());
    }

    public function testTheReturnPathRejectsAnOffSiteTarget(): void
    {
        RememberLogin::rememberReturnPath('//evil.example/parent');
        self::assertNull(RememberLogin::takeReturnPath());

        RememberLogin::rememberReturnPath('https://evil.example/parent');
        self::assertNull(RememberLogin::takeReturnPath());
    }

    public function testTheSudoWindowMatchesTheAuthThrottleWindow(): void
    {
        // One notion of "recent" across the app (AuthService::WINDOW_MINUTES = 15).
        self::assertSame(900, RememberLogin::SUDO_WINDOW_SECONDS);
    }
}
