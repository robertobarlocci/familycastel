<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

use FamilyCastel\Domain\AuditService;
use FamilyCastel\Domain\RememberService;

/**
 * Glue between the remember cookie and the session principal, plus the
 * "recently proved the password" (sudo) window.
 *
 * Sudo is deliberately NOT granted by a cookie restore. That is the whole point
 * of the operator's decision: a device stays signed in for everyday use, but the
 * irreversible operations (backups, restore, updater) still want the password.
 */
final class RememberLogin
{
    private const SUDO_KEY = 'auth_sudo_at';
    private const RETURN_KEY = 'auth_sudo_return';

    /** Matches AuthService::WINDOW_MINUTES so the app has one notion of "recent". */
    public const SUDO_WINDOW_SECONDS = 900;

    /**
     * Restore a session from the cookie. Cheap no-op on every request that is
     * already authenticated — two array reads, no query.
     */
    public static function restore(Db $db): void
    {
        if (Auth::parentId() !== null || Auth::childId() !== null) {
            return;
        }

        $token = RememberCookie::read();
        if ($token === null) {
            return;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $result = (new RememberService($db))->resolve($token, $ip !== '' ? $ip : null);

        if ($result === null) {
            RememberCookie::clear();

            return;
        }

        if ($result->principalType === 'user') {
            Auth::loginParent($result->principalId);
        } else {
            Auth::loginChild($result->principalId);
        }

        // A restored session is NOT sudo, whatever it was before.
        unset($_SESSION[self::SUDO_KEY]);

        if ($result->newToken !== null) {
            RememberCookie::write($result->newToken);
        }

        (new AuditService($db))->log(
            $result->principalType === 'user' ? 'user' : 'child',
            $result->principalId,
            $result->principalType === 'user' ? 'parent.login_remembered' : 'child.login_remembered',
            ip: $ip
        );
    }

    /** Issue a fresh token for a principal that just proved a credential. */
    public static function start(Db $db, string $principalType, int $principalId): void
    {
        $token = (new RememberService($db))->issue($principalType, $principalId, self::deviceLabel());
        RememberCookie::write($token);
    }

    /** This device forgets me. Other devices keep their own tokens. */
    public static function forget(Db $db): void
    {
        $token = RememberCookie::read();
        if ($token !== null) {
            (new RememberService($db))->revoke($token);
        }
        RememberCookie::clear();
    }

    public static function markPasswordVerified(): void
    {
        $_SESSION[self::SUDO_KEY] = time();
    }

    public static function hasRecentPassword(): bool
    {
        $at = $_SESSION[self::SUDO_KEY] ?? null;

        return is_int($at) && (time() - $at) <= self::SUDO_WINDOW_SECONDS;
    }

    /** Where to send the parent back to after they confirm. Server-side only. */
    public static function rememberReturnPath(string $path): void
    {
        $_SESSION[self::RETURN_KEY] = $path;
    }

    public static function takeReturnPath(): ?string
    {
        $path = $_SESSION[self::RETURN_KEY] ?? null;
        unset($_SESSION[self::RETURN_KEY]);

        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }
        // Backslash and CRLF are rejected for the same reason CookieNoticeController
        // rejects them: browsers treat `\` as `/` in special schemes, so a leading
        // `/\host` reads as protocol-relative and turns a Location: header into an
        // off-site redirect. Not reachable today (every gated route has a fixed
        // literal prefix), but the two return-path filters must not diverge.
        if (str_contains($path, "\r") || str_contains($path, "\n") || str_contains($path, '\\')) {
            return null;
        }

        $clean = parse_url($path, PHP_URL_PATH);

        return is_string($clean) && $clean !== '' ? $clean : null;
    }

    /**
     * A coarse hint so a future device list is readable. Never the raw
     * User-Agent: that is fingerprintable and long, and we only need a word.
     */
    private static function deviceLabel(): ?string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return null;
        }

        foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Macintosh' => 'Mac',
            'Windows' => 'Windows', 'Linux' => 'Linux'] as $needle => $label) {
            if (str_contains($ua, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
