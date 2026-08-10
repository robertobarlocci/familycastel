<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Cache-busting version token for the CSS/JS the app emits.
 *
 * Nothing in the release ships a Cache-Control header — mod_headers and
 * mod_expires are not guaranteed on the target hosting (INV-003) — so Apache
 * serves static assets with Last-Modified/ETag only and browsers fall back to
 * HEURISTIC freshness. A returning visitor can therefore keep using a stylesheet
 * from a previous release for days. That is not theoretical: it is why a phone
 * rendered the parent portal's desktop navbar after the mobile CSS had shipped.
 *
 * The token is the app version, carried as a query string on the asset URL. It
 * is deliberately ONE global value rather than a per-file mtime, because the
 * service worker — a static file that cannot stat the filesystem — has to be
 * able to compute the exact same URLs for its precache (`caches.match()` matches
 * on the full URL, query included).
 */
final class Assets
{
    /** Used until a real version is set, and whenever one sanitises to nothing. */
    private const FALLBACK = '0';

    private static string $version = self::FALLBACK;

    /**
     * VERSION is a file on disk, so its content is an input like any other:
     * validate at the boundary. The allowlist keeps quotes, spaces, '?', '&'
     * and '<' out of a value that gets rendered into an HTML attribute and used
     * as a service-worker cache key.
     */
    public static function setVersion(string $version): void
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $version) ?? '';

        self::$version = $clean === '' ? self::FALLBACK : $clean;
    }

    public static function version(): string
    {
        return self::$version;
    }

    /**
     * Versioned URL for an asset Apache serves directly. Built on BasePath so a
     * subdirectory install and the ?r= fallback keep working: prefix() returns
     * direct files (/public-assets/**, /sw.js) verbatim in BOTH routing modes,
     * so the token rides along and the file is never routed through index.php.
     */
    public static function url(string $path): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        return BasePath::prefix($path . $separator . 'v=' . self::$version);
    }
}
