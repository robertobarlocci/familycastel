<?php

declare(strict_types=1);

namespace FamilyCastel\Http;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
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
        (new AuditService($this->db))->log('user', (int) $user['id'], 'parent.login', ip: $ip);

        header('Location: ' . url('/parent'), true, 302);

        return '';
    }

    public function logout(array $post, string $ip): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            $parentId = Auth::parentId();
            $childId = Auth::childId();
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
