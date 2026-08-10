<?php

/**
 * Global template/view helpers. Contextual escaping is mandatory:
 * e() for HTML text, eattr() for attributes, ejs() for script/JSON,
 * eurl() for URL components. No template may echo a variable raw.
 */

declare(strict_types=1);

use FamilyCastel\Core\Assets;
use FamilyCastel\Core\BasePath;
use FamilyCastel\Core\I18n;

if (!function_exists('e')) {
    function e(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('eattr')) {
    function eattr(string|int|float|null $value): string
    {
        return e($value);
    }
}

if (!function_exists('ejs')) {
    /**
     * Encode a value for safe embedding inside a <script> context.
     * HEX flags neutralize <, >, &, ' and " so "</script>" breakouts are impossible.
     */
    function ejs(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    }
}

if (!function_exists('eurl')) {
    function eurl(string|int|float|null $value): string
    {
        return rawurlencode((string) ($value ?? ''));
    }
}

if (!function_exists('url')) {
    /** Base-path aware URL for links/redirects — never hardcode absolute paths. */
    function url(string $path): string
    {
        return BasePath::prefix($path);
    }
}

if (!function_exists('asset')) {
    /**
     * Versioned URL for a CSS/JS asset — busts browser and service-worker caches
     * on every release. Use url() for fonts and icons: those are requested from
     * inside fonts.css and the webmanifest, which cannot carry the token.
     */
    function asset(string $path): string
    {
        return Assets::url($path);
    }
}

if (!function_exists('t')) {
    /** Translate a key with optional {placeholder} params. */
    function t(string $key, array $params = []): string
    {
        return I18n::translate($key, $params);
    }
}

if (!function_exists('op_nonce')) {
    /** One-time form nonce for coin-bearing operations (replay idempotency). */
    function op_nonce(): string
    {
        return bin2hex(random_bytes(8));
    }
}

if (!function_exists('op_from_post')) {
    /** Validated operation nonce from a POST body, or null when absent/odd. */
    function op_from_post(array $post): ?string
    {
        $op = (string) ($post['op'] ?? '');

        return preg_match('/^[a-f0-9]{16,64}$/', $op) === 1 ? $op : null;
    }
}
