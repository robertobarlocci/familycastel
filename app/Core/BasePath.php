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
    private static bool $prettyUrls = true;

    public static function set(string $base): void
    {
        $base = '/' . trim($base, '/');
        self::$base = $base === '/' ? '' : $base;
    }

    public static function get(): string
    {
        return self::$base;
    }

    /**
     * Hosts WITHOUT mod_rewrite (plan §15 fallback): every generated link
     * becomes index.php?r=<path> — detected once at install time (loopback
     * probe) and stored in config; pretty URLs stay the default.
     */
    public static function setPrettyUrls(bool $pretty): void
    {
        self::$prettyUrls = $pretty;
    }

    public static function prettyUrls(): bool
    {
        return self::$prettyUrls;
    }

    public static function detect(string $scriptName): string
    {
        $dir = str_replace('\\', '/', dirname($scriptName));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function prefix(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        if (self::$prettyUrls || self::isDirectFile($path)) {
            return self::$base . $path;
        }

        // ?r= form: move any query string of the target into &-params.
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $rest] = explode('?', $path, 2);
            $query = '&' . $rest;
        }

        return self::$base . '/index.php?r=' . rawurlencode($path) . $query;
    }

    /**
     * Real files Apache serves directly (no router involved) must keep their
     * plain path even in ?r= mode — routing them through index.php would 404
     * CSS/JS/icons/fonts, the service worker and the standalone updater.
     */
    private static function isDirectFile(string $path): bool
    {
        return str_starts_with($path, '/public-assets/')
            || in_array(strtok($path, '?'), ['/sw.js', '/offline.html', '/update.php'], true);
    }
}
