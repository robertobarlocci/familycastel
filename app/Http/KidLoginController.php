<?php

declare(strict_types=1);

namespace FamilyCastel\Http;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\AuditService;
use FamilyCastel\Domain\AuthService;

/** Child login: profile picker + PIN, and QR token exchange. */
final class KidLoginController
{
    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    public function picker(): string
    {
        if (Auth::childId() !== null) {
            header('Location: ' . url('/kid'), true, 302);

            return '';
        }

        $children = $this->db->fetchAll(
            'SELECT id, name, character_key, theme, level, pin_hash IS NOT NULL AS has_pin
             FROM children WHERE archived_at IS NULL ORDER BY name'
        );

        return $this->view->render('kid/login', ['children' => $children], 'layouts/auth');
    }

    public function pin(array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            return $this->picker();
        }

        $childId = (int) ($post['child_id'] ?? 0);
        $pin = (string) ($post['pin'] ?? '');

        $auth = new AuthService($this->db);

        // No-PIN profiles first (tap-to-enter, parents chose this trust level
        // for young kids) — this path must never record throttle failures,
        // even when a browser autofilled some value into the PIN field.
        $child = $auth->childWithoutPin($childId);
        if ($child === null) {
            $child = $auth->attemptChildPin($childId, $pin, $ip);
        }

        if ($child === null) {
            $children = $this->db->fetchAll(
                'SELECT id, name, character_key, theme, level, pin_hash IS NOT NULL AS has_pin
                 FROM children WHERE archived_at IS NULL ORDER BY name'
            );

            return $this->view->render('kid/login', [
                'children' => $children,
                'error' => t('kid.error_pin'),
                'selected' => $childId,
            ], 'layouts/auth');
        }

        Auth::loginChild((int) $child['id']);
        (new AuditService($this->db))->log('child', (int) $child['id'], 'child.login', ip: $ip);

        header('Location: ' . url('/kid'), true, 302);

        return '';
    }

    /** QR token exchange: token → session → immediate redirect (token never lingers). */
    public function qr(string $token, string $ip): string
    {
        $auth = new AuthService($this->db);
        $child = $auth->childForToken($token);

        if ($child === null) {
            http_response_code(404);

            return $this->view->render('kid/qr-invalid', [], 'layouts/auth');
        }

        Auth::loginChild((int) $child['id']);
        (new AuditService($this->db))->log('child', (int) $child['id'], 'child.login_qr', ip: $ip);

        header('Location: ' . url('/kid'), true, 302);

        return '';
    }
}
