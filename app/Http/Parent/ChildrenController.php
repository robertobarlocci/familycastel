<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Parent;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\AuditService;
use FamilyCastel\Domain\AuthService;
use FamilyCastel\Domain\ChildService;

/** Parent area: children management (create/edit/archive/QR). */
final class ChildrenController
{
    private ChildService $children;

    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
        $this->children = new ChildService($db);
    }

    public function index(): string
    {
        return $this->view->render('parent/children/index', [
            'children' => $this->children->listAll(),
        ], 'layouts/parent');
    }

    public function form(?int $id): string
    {
        $child = $id !== null ? $this->children->find($id) : null;

        return $this->view->render('parent/children/form', [
            'child' => $child,
            'themes' => ChildService::THEMES,
            'characters' => ChildService::CHARACTERS,
        ], 'layouts/parent');
    }

    public function save(array $post, string $ip): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/children');
        }

        $id = ($post['id'] ?? '') !== '' ? (int) $post['id'] : null;
        $data = [
            'name' => (string) ($post['name'] ?? ''),
            'theme' => (string) ($post['theme'] ?? 'fantasy'),
            'character_key' => (string) ($post['character_key'] ?? ''),
            'sound_enabled' => !empty($post['sound_enabled']),
        ];

        $originalId = $id;
        try {
            // Child fields + PIN change commit together — a PIN validation
            // failure must not leave a half-saved child behind.
            $created = $this->db->transaction(function () use (&$id, $data, $post): bool {
                $created = false;
                if ($id === null) {
                    $id = $this->children->create($data);
                    $created = true;
                } else {
                    $this->children->update($id, $data);
                }

                $pin = trim((string) ($post['pin'] ?? ''));
                if ($pin !== '') {
                    $this->children->setPin($id, $pin);
                } elseif (!empty($post['clear_pin'])) {
                    $this->children->clearPin($id);
                }

                return $created;
            });

            if ($created) {
                (new AuditService($this->db))->log('user', Auth::parentId(), 'child.created', 'child', $id, ip: $ip);
            }
            Session::flash('success', $created ? t('children.created') : t('children.updated'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());

            // On rollback, $id may hold a rolled-back insert id — route by the
            // ORIGINAL id so a failed creation returns to the new-child form.
            return $this->redirect($originalId === null ? '/parent/children/new' : '/parent/children/' . $originalId . '/edit');
        }

        return $this->redirect('/parent/children');
    }

    public function archive(int $id, array $post, string $ip): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            $this->children->archive($id);
            // Archiving only makes a stay-signed-in token UNUSABLE (the resolve
            // query checks archived_at). A copy that is never presented during
            // the archived window stays unrevoked and would silently come back
            // to life on un-archive — so end those logins here, for good.
            (new \FamilyCastel\Domain\RememberService($this->db))->revokeAllFor('child', $id);
            (new AuditService($this->db))->log('user', Auth::parentId(), 'child.archived', 'child', $id, ip: $ip);
            Session::flash('success', t('children.archived'));
        }

        return $this->redirect('/parent/children');
    }

    public function unarchive(int $id, array $post, string $ip): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            $this->children->unarchive($id);
            (new AuditService($this->db))->log('user', Auth::parentId(), 'child.unarchived', 'child', $id, ip: $ip);
            Session::flash('success', t('children.unarchived'));
        }

        return $this->redirect('/parent/children');
    }

    /** QR page: shows the login link; regeneration is a separate POST. */
    public function qr(int $id): string
    {
        $child = $this->children->find($id);
        if ($child === null) {
            return $this->redirect('/parent/children');
        }

        // The plaintext token exists only right after regeneration (flash-scoped).
        $freshToken = $_SESSION['_fresh_qr_token'][$id] ?? null;
        unset($_SESSION['_fresh_qr_token'][$id]);

        return $this->view->render('parent/children/qr', [
            'child' => $child,
            'freshToken' => $freshToken,
            'qrUrl' => $freshToken !== null
                ? rtrim($this->canonicalBaseUrl(), '/') . url('/kid/qr/' . eurl($freshToken))
                : null,
            'hasToken' => $this->db->fetchOne(
                'SELECT id FROM auth_tokens WHERE child_id = ? AND revoked_at IS NULL', [$id]
            ) !== null,
        ], 'layouts/parent');
    }

    /**
     * Canonical absolute base URL for QR links. Captured ONCE from an
     * authenticated parent's own request and persisted — the QR destination
     * must never follow a per-request Host header (poisoning would leak the
     * bearer token to an attacker-controlled host).
     */
    private function canonicalBaseUrl(): string
    {
        $settings = new \FamilyCastel\Domain\SettingsService($this->db);
        $stored = $settings->get('app.base_url');
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) !== 1) {
            $host = 'localhost';
        }
        $base = (\FamilyCastel\Core\Session::isHttps() ? 'https://' : 'http://') . $host;
        $settings->set('app.base_url', $base);

        return $base;
    }

    public function regenerateQr(int $id, array $post, string $ip): string
    {
        $child = $this->children->find($id);
        if (Csrf::validate($post['_csrf'] ?? null) && $child !== null && $child['archived_at'] === null) {
            $token = (new AuthService($this->db))->regenerateChildToken($id, (int) Auth::parentId());
            $_SESSION['_fresh_qr_token'][$id] = $token;
            (new AuditService($this->db))->log('user', Auth::parentId(), 'child.qr_regenerated', 'child', $id, ip: $ip);
            Session::flash('success', t('children.qr_regenerated'));
        }

        return $this->redirect('/parent/children/' . $id . '/qr');
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
