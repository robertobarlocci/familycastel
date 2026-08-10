<?php

declare(strict_types=1);

namespace FamilyCastel\Http;

use FamilyCastel\Core\BasePath;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\RememberCookie;
use FamilyCastel\Core\Session;

/** Dismissal of the informational cookie notice. */
final class CookieNoticeController
{
    private const NAME = 'fc_cookie_notice';

    public function dismiss(array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null) && !headers_sent()) {
            setcookie(self::NAME, '1', [
                'expires' => time() + (400 * 86400),
                'path' => RememberCookie::path(),
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => Session::isHttps(),
            ]);
        }

        header('Location: ' . url($this->safeReturn($post)), true, 302);

        return '';
    }

    /**
     * The return target is a path this app produced, echoed back through a
     * hidden field — so it is treated as untrusted: app-internal absolute paths
     * only, never a scheme, a host, or a protocol-relative `//evil.example`.
     */
    private function safeReturn(array $post): string
    {
        $candidate = (string) ($post['return'] ?? '/');

        if ($candidate === '' || !str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return '/';
        }
        if (str_contains($candidate, "\r") || str_contains($candidate, "\n") || str_contains($candidate, '\\')) {
            return '/';
        }
        // Strip any query/fragment an attacker might append; we only ever need
        // the path we rendered the notice on.
        $path = parse_url($candidate, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
