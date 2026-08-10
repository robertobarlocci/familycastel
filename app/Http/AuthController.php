<?php

declare(strict_types=1);

namespace FamilyCastel\Http;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\RememberLogin;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\AuditService;
use FamilyCastel\Domain\AuthService;

/** Parent login/logout. */
final class AuthController
{
    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
    }

    public function showLogin(): string
    {
        if (Auth::parentId() !== null) {
            header('Location: ' . url('/parent'), true, 302);

            return '';
        }

        return $this->view->render('auth/login', [], 'layouts/auth');
    }

    public function login(array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            return $this->view->render('auth/login', ['error' => t('auth.error_csrf')], 'layouts/auth');
        }

        $username = trim((string) ($post['username'] ?? ''));
        $password = (string) ($post['password'] ?? '');

        $auth = new AuthService($this->db);
        $user = $username === '' || $password === ''
            ? null
            : $auth->attemptParentLogin($username, $password, $ip);

        if ($user === null) {
            // One message for wrong credentials AND throttling — no enumeration.
            return $this->view->render('auth/login', [
                'error' => t('auth.error_failed'),
                'username' => $username,
            ], 'layouts/auth');
        }

        Auth::loginParent((int) $user['id']);
        // A password was just proved: this session may reach the destructive
        // operations without asking again for the next few minutes.
        RememberLogin::markPasswordVerified();
        // Persistence is decoration on a successful login — it must never be
        // able to fail the login itself.
        try {
            RememberLogin::start($this->db, 'user', (int) $user['id']);
        } catch (\Throwable) {
            // Stay signed in for this session only; nothing else changes.
        }
        (new AuditService($this->db))->log('user', (int) $user['id'], 'parent.login', ip: $ip);

        header('Location: ' . url('/parent'), true, 302);

        return '';
    }

    /** Step-up screen shown before an irreversible or disclosing operation. */
    public function confirmPasswordForm(): string
    {
        return $this->view->render('auth/confirm-password', [], 'layouts/auth');
    }

    /**
     * Re-authenticate the CURRENT parent.
     *
     * The form deliberately carries no username: the account is taken from the
     * session, and the verified user id is compared back against it. Without
     * that binding, a second parent's valid password would grant sudo to
     * someone else's session — a silent privilege grant on exactly the routes
     * that can wipe or download the whole installation.
     */
    public function confirmPassword(array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            return $this->view->render('auth/confirm-password', ['error' => t('auth.error_csrf')], 'layouts/auth');
        }

        $parentId = Auth::parentId();
        $current = $parentId === null ? null : $this->db->fetchOne(
            'SELECT id, username FROM users WHERE id = ? AND role = ? AND is_active = 1',
            [$parentId, 'parent']
        );

        if ($current === null) {
            header('Location: ' . url('/login'), true, 302);

            return '';
        }

        $password = (string) ($post['password'] ?? '');
        // Reuse the one credential-checking path so throttling, the dummy-hash
        // timing equalisation and the audit trail all still apply.
        $verified = $password === ''
            ? null
            : (new AuthService($this->db))->attemptParentLogin((string) $current['username'], $password, $ip);

        if ($verified === null || (int) $verified['id'] !== (int) $current['id']) {
            return $this->view->render('auth/confirm-password', [
                'error' => t('auth.error_failed'),
            ], 'layouts/auth');
        }

        RememberLogin::markPasswordVerified();
        (new AuditService($this->db))->log('user', (int) $current['id'], 'parent.reauth', ip: $ip);

        header('Location: ' . url(RememberLogin::takeReturnPath() ?? '/parent/settings/backups'), true, 302);

        return '';
    }

    public function logout(array $post, string $ip): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            $parentId = Auth::parentId();
            $childId = Auth::childId();
            // "Sign out" must mean this device forgets me — otherwise the very
            // next request would restore the session from the cookie.
            RememberLogin::forget($this->db);
            Auth::logout();
            if ($parentId !== null || $childId !== null) {
                (new AuditService($this->db))->log(
                    $parentId !== null ? 'user' : 'child',
                    $parentId ?? $childId,
                    'logout',
                    ip: $ip
                );
            }
        }

        header('Location: ' . url('/login'), true, 302);

        return '';
    }
}
