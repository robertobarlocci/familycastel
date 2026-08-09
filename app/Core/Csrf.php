<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Synchronizer-token CSRF protection. Token lives in the session, is compared
 * constant-time, and is rotated on privilege changes (login).
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    public static function validate(?string $token): bool
    {
        if ($token === null || $token === '' || !isset($_SESSION[self::KEY])) {
            return false;
        }

        return hash_equals($_SESSION[self::KEY], $token);
    }

    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }

    /** Hidden input for POST forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }
}
