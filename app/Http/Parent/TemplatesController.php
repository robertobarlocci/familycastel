<?php

declare(strict_types=1);

namespace FamilyCastel\Http\Parent;

use FamilyCastel\Core\Csrf;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use FamilyCastel\Domain\ChildService;
use FamilyCastel\Domain\TemplateService;

/** Parent area: point template management. */
final class TemplatesController
{
    private TemplateService $templates;

    public function __construct(
        private readonly Db $db,
        private readonly View $view,
    ) {
        $this->templates = new TemplateService($db);
    }

    public function index(): string
    {
        return $this->view->render('parent/templates/index', [
            'templates' => $this->templates->listActive(),
        ], 'layouts/parent');
    }

    public function form(?int $id): string
    {
        return $this->view->render('parent/templates/form', [
            'template' => $id !== null ? $this->templates->find($id) : null,
            'children' => (new ChildService($this->db))->listActive(),
        ], 'layouts/parent');
    }

    public function save(array $post): string
    {
        if (!Csrf::validate($post['_csrf'] ?? null)) {
            Session::flash('error', t('common.error_csrf'));

            return $this->redirect('/parent/templates');
        }

        $id = ($post['id'] ?? '') !== '' ? (int) $post['id'] : null;
        $data = [
            'title' => (string) ($post['title'] ?? ''),
            'coins_delta' => (int) ($post['coins_delta'] ?? 0),
            'xp_delta' => (int) ($post['xp_delta'] ?? 0),
            'scope' => (string) ($post['scope'] ?? 'all'),
            'child_ids' => (array) ($post['child_ids'] ?? []),
            'is_favorite' => !empty($post['is_favorite']),
            'requires_confirm' => !empty($post['requires_confirm']),
        ];

        try {
            if ($id === null) {
                $this->templates->create($data);
                Session::flash('success', t('templates.created'));
            } else {
                $this->templates->update($id, $data);
                Session::flash('success', t('templates.updated'));
            }
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());

            return $this->redirect($id === null ? '/parent/templates/new' : '/parent/templates/' . $id . '/edit');
        }

        return $this->redirect('/parent/templates');
    }

    public function archive(int $id, array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            $this->templates->archive($id);
            Session::flash('success', t('templates.archived'));
        }

        return $this->redirect('/parent/templates');
    }

    public function toggleFavorite(int $id, array $post): string
    {
        if (Csrf::validate($post['_csrf'] ?? null)) {
            $this->templates->toggleFavorite($id);
        }

        return $this->redirect('/parent/templates');
    }

    private function redirect(string $path): string
    {
        header('Location: ' . url($path), true, 302);

        return '';
    }
}
