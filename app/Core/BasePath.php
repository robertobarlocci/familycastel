<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Detected installation base path ('' at domain root, '/family' in a subdirectory).
 * Derived from SCRIPT_NAME only — never from the Host header (untrusted).
 */
final class BasePath
{
    private static string $base = '';

    public static function set(string $base): void
    {
        $base = '/' . trim($base, '/');
        self::$base = $base === '/' ? '' : $base;
    }

    public static function get(): string
    {
        return self::$base;
    }

    public static function detect(string $scriptName): string
    {
        $dir = str_replace('\\', '/', dirname($scriptName));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function prefix(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return self::$base . $path;
    }
}
