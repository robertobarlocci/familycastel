<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Session principal management. One principal per session: logging in as a
 * parent ends a child session and vice versa. Session ID regeneration + CSRF
 * rotation on every privilege change.
 */
final class Auth
{
    private const PARENT_KEY = 'auth_parent_id';
    private const CHILD_KEY = 'auth_child_id';

    public static function loginParent(int $userId): void
    {
        Session::regenerate();
        unset($_SESSION[self::CHILD_KEY]);
        $_SESSION[self::PARENT_KEY] = $userId;
        Csrf::rotate();
    }

    public static function loginChild(int $childId): void
    {
        Session::regenerate();
        unset($_SESSION[self::PARENT_KEY]);
        $_SESSION[self::CHILD_KEY] = $childId;
        Csrf::rotate();
    }

    public static function logout(): void
    {
        unset($_SESSION[self::PARENT_KEY], $_SESSION[self::CHILD_KEY]);
        Session::regenerate();
        Csrf::rotate();
    }

    public static function parentId(): ?int
    {
        $id = $_SESSION[self::PARENT_KEY] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function childId(): ?int
    {
        $id = $_SESSION[self::CHILD_KEY] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }
}
