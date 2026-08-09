<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start([
            'use_strict_mode' => 1,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => self::isHttps(),
            'cookie_path' => (BasePath::get() ?: '/') === '/' ? '/' : BasePath::get() . '/',
            'name' => 'fc_session',
        ]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
