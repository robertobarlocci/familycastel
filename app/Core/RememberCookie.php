<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

use FamilyCastel\Domain\RememberService;

/**
 * The `fc_remember` cookie.
 *
 * Path comes from BasePath, exactly as Session::start() derives it — NOT the
 * hardcoded '/' the updater's short-lived token cookie uses. On a subdirectory
 * install (/familycastle/) a '/' cookie would be sent to every sibling app on
 * the same origin.
 *
 * SameSite=Lax, matching the session cookie: Strict would withhold the cookie on
 * the first navigation from any external link or bookmark, so the family would
 * look logged out at exactly the moment this feature exists to fix. Lax still
 * blocks cross-site POST, and every state-changing route is CSRF-protected.
 */
final class RememberCookie
{
    public const NAME = 'fc_remember';

    public static function read(): ?string
    {
        $value = $_COOKIE[self::NAME] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function write(string $token): void
    {
        self::send($token, time() + (RememberService::LIFETIME_DAYS * 86400));
    }

    public static function clear(): void
    {
        unset($_COOKIE[self::NAME]);
        self::send('', time() - 3600);
    }

    private static function send(string $value, int $expires): void
    {
        if (headers_sent()) {
            return; // Nothing sensible to do; never emit a warning into a page.
        }

        setcookie(self::NAME, $value, [
            'expires' => $expires,
            'path' => self::path(),
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => Session::isHttps(),
        ]);
    }

    public static function path(): string
    {
        $base = BasePath::get();

        return ($base ?: '/') === '/' ? '/' : $base . '/';
    }
}
