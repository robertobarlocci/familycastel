<?php

/**
 * Route table. Guards are applied here, centrally — never ad hoc inside
 * controllers (plan §5). Handlers echo nothing; they return the body.
 *
 * Expects: $router (Router), $config (Config). DB/View are created lazily so
 * the not-installed path never needs credentials.
 */

declare(strict_types=1);

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\Config;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\Router;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;

$db = null;
$lazyDb = function () use (&$db, $config): Db {
    return $db ??= Db::fromConfig($config);
};
$view = new View(FC_ROOT . '/views');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$requireInstalled = function (callable $handler) use ($config): callable {
    return function (array $params) use ($handler, $config): string {
        if (!$config->isInstalled()) {
            header('Location: ' . url('/install'), true, 302);

            return '';
        }
        Session::start();

        return $handler($params);
    };
};

$requireParent = function (callable $handler) use ($requireInstalled, $lazyDb): callable {
    return $requireInstalled(function (array $params) use ($handler, $lazyDb): string {
        // Sessions alone are not enough: revalidate CURRENT eligibility so a
        // deactivated parent loses access immediately, not at session expiry.
        $eligible = Auth::parentId() !== null && $lazyDb()->fetchOne(
            'SELECT id FROM users WHERE id = ? AND role = ? AND is_active = 1',
            [Auth::parentId(), 'parent']
        ) !== null;

        if (!$eligible) {
            Auth::logout();
            header('Location: ' . url('/login'), true, 302);

            return '';
        }

        return $handler($params);
    });
};

$requireChild = function (callable $handler) use ($requireInstalled, $lazyDb): callable {
    return $requireInstalled(function (array $params) use ($handler, $lazyDb): string {
        $eligible = Auth::childId() !== null && $lazyDb()->fetchOne(
            'SELECT id FROM children WHERE id = ? AND archived_at IS NULL',
            [Auth::childId()]
        ) !== null;

        if (!$eligible) {
            Auth::logout();
            header('Location: ' . url('/kid/login'), true, 302);

            return '';
        }

        return $handler($params);
    });
};

// ---------------------------------------------------------------- public
$router->get('/manifest.webmanifest', function (): string {
    header('Content-Type: application/manifest+json');

    return json_encode([
        'name' => 'Family Castel',
        'short_name' => 'Family Castel',
        'description' => 'Das Familien-Abenteuer: Coins, Sidequests, XP und grosse Ziele.',
        'start_url' => './',
        'scope' => './',
        'display' => 'standalone',
        'background_color' => '#2b2f6b',
        'theme_color' => '#2b2f6b',
        'icons' => [
            ['src' => 'public-assets/icons/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ['src' => 'public-assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => 'public-assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

$router->get('/health', function () use ($config): string {
    header('Content-Type: application/json');

    return json_encode([
        'ok' => true,
        'app' => 'Family Castel',
        'version' => FC_VERSION,
        'installed' => $config->isInstalled(),
    ], JSON_THROW_ON_ERROR);
});

$router->get('/', $requireInstalled(function () : string {
    if (Auth::parentId() !== null) {
        header('Location: ' . url('/parent'), true, 302);
    } elseif (Auth::childId() !== null) {
        header('Location: ' . url('/kid'), true, 302);
    } else {
        header('Location: ' . url('/login'), true, 302);
    }

    return '';
}));

// ---------------------------------------------------------------- installer
$installHandler = function () use ($config): string {
    Session::start();
    header('Content-Type: text/html; charset=UTF-8');
    $controller = new \FamilyCastel\Http\InstallController(FC_ROOT, $config);

    return $controller->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_POST);
};
$router->get('/install', $installHandler);
$router->post('/install', $installHandler);

// ---------------------------------------------------------------- auth
$router->get('/login', $requireInstalled(fn () => (new \FamilyCastel\Http\AuthController($lazyDb(), $view))->showLogin()));
$router->post('/login', $requireInstalled(fn () => (new \FamilyCastel\Http\AuthController($lazyDb(), $view))->login($_POST, $ip)));
$router->post('/logout', $requireInstalled(fn () => (new \FamilyCastel\Http\AuthController($lazyDb(), $view))->logout($_POST, $ip)));

$router->get('/kid/login', $requireInstalled(fn () => (new \FamilyCastel\Http\KidLoginController($lazyDb(), $view))->picker()));
$router->post('/kid/login', $requireInstalled(fn () => (new \FamilyCastel\Http\KidLoginController($lazyDb(), $view))->pin($_POST, $ip)));
$router->get('/kid/qr/{token}', $requireInstalled(fn (array $p) => (new \FamilyCastel\Http\KidLoginController($lazyDb(), $view))->qr((string) $p['token'], $ip)));

// ---------------------------------------------------------------- parent area
$router->get('/parent', $requireParent(function () use ($lazyDb, $view): string {
    $user = $lazyDb()->fetchOne('SELECT * FROM users WHERE id = ?', [Auth::parentId()]);
    $children = $lazyDb()->fetchAll(
        'SELECT * FROM children WHERE archived_at IS NULL ORDER BY name'
    );

    return $view->render('parent/dashboard', ['user' => $user, 'children' => $children], 'layouts/parent');
}));

$router->get('/parent/child/{id}', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildScreenController($lazyDb(), $view))->show((int) $p['id'])));
$router->post('/parent/child/{id}/award', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildScreenController($lazyDb(), $view))->applyTemplate((int) $p['id'], $_POST, $ip)));
$router->post('/parent/child/{id}/custom', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildScreenController($lazyDb(), $view))->custom((int) $p['id'], $_POST, $ip)));

$router->get('/parent/children', $requireParent(fn () => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->index()));
$router->get('/parent/children/new', $requireParent(fn () => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->form(null)));
$router->get('/parent/children/{id}/edit', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->form((int) $p['id'])));
$router->post('/parent/children/save', $requireParent(fn () => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->save($_POST, $ip)));
$router->post('/parent/children/{id}/archive', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->archive((int) $p['id'], $_POST, $ip)));
$router->post('/parent/children/{id}/unarchive', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->unarchive((int) $p['id'], $_POST, $ip)));
$router->get('/parent/children/{id}/qr', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->qr((int) $p['id'])));
$router->post('/parent/children/{id}/qr/regenerate', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\ChildrenController($lazyDb(), $view))->regenerateQr((int) $p['id'], $_POST, $ip)));

$router->get('/parent/templates', $requireParent(fn () => (new \FamilyCastel\Http\Parent\TemplatesController($lazyDb(), $view))->index()));
$router->get('/parent/templates/new', $requireParent(fn () => (new \FamilyCastel\Http\Parent\TemplatesController($lazyDb(), $view))->form(null)));
$router->get('/parent/templates/{id}/edit', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\TemplatesController($lazyDb(), $view))->form((int) $p['id'])));
$router->post('/parent/templates/save', $requireParent(fn () => (new \FamilyCastel\Http\Parent\TemplatesController($lazyDb(), $view))->save($_POST)));
$router->post('/parent/templates/{id}/archive', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\TemplatesController($lazyDb(), $view))->archive((int) $p['id'], $_POST)));
$router->post('/parent/templates/{id}/favorite', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\TemplatesController($lazyDb(), $view))->toggleFavorite((int) $p['id'], $_POST)));

$router->get('/parent/approvals', $requireParent(fn () => (new \FamilyCastel\Http\Parent\ApprovalsController($lazyDb(), $view))->index()));
$router->post('/parent/approvals/decide', $requireParent(fn () => (new \FamilyCastel\Http\Parent\ApprovalsController($lazyDb(), $view))->decide($_POST, $ip)));

$router->get('/parent/sidequests', $requireParent(fn () => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->sidequests()));
$router->post('/parent/sidequests/create', $requireParent(fn () => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->createSidequest($_POST)));
$router->post('/parent/sidequests/{id}/archive', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->archiveSidequest((int) $p['id'], $_POST)));
$router->get('/parent/rewards', $requireParent(fn () => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->rewards()));
$router->post('/parent/rewards/create', $requireParent(fn () => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->createReward($_POST)));
$router->post('/parent/rewards/{id}/archive', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->archiveReward((int) $p['id'], $_POST)));
$router->get('/parent/milestones', $requireParent(fn () => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->milestones()));
$router->post('/parent/milestones/create', $requireParent(fn () => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->createMilestone($_POST)));
$router->post('/parent/milestones/{id}/claim', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->claimMilestone((int) $p['id'], $_POST)));
$router->post('/parent/milestones/{id}/archive', $requireParent(fn (array $p) => (new \FamilyCastel\Http\Parent\QuestAdminController($lazyDb(), $view))->archiveMilestone((int) $p['id'], $_POST)));

// ---------------------------------------------------------------- kid area
$kid = fn () => new \FamilyCastel\Http\Kid\KidController($lazyDb(), $view);
$router->get('/kid', $requireChild(fn () => $kid()->home()));
$router->get('/kid/sidequests', $requireChild(fn () => $kid()->sidequests()));
$router->post('/kid/sidequests/accept', $requireChild(fn () => $kid()->acceptQuest($_POST)));
$router->post('/kid/sidequests/complete', $requireChild(fn () => $kid()->completeQuest($_POST)));
$router->post('/kid/sidequests/cancel', $requireChild(fn () => $kid()->cancelQuest($_POST)));
$router->post('/kid/suggestions', $requireChild(fn () => $kid()->submitSuggestion($_POST)));
$router->get('/kid/rewards', $requireChild(fn () => $kid()->rewards()));
$router->post('/kid/rewards/redeem', $requireChild(fn () => $kid()->redeemReward($_POST)));
$router->post('/kid/rewards/wish', $requireChild(fn () => $kid()->wishReward($_POST)));
$router->post('/kid/rewards/cancel', $requireChild(fn () => $kid()->cancelReward($_POST)));
$router->get('/kid/milestones', $requireChild(fn () => $kid()->milestones()));
$router->post('/kid/milestones/wish', $requireChild(fn () => $kid()->wishMilestone($_POST)));
$router->get('/kid/achievements', $requireChild(fn () => $kid()->achievements()));
$router->get('/kid/settings', $requireChild(fn () => $kid()->settings()));
$router->post('/kid/settings', $requireChild(fn () => $kid()->saveSettings($_POST)));
$router->get('/kid/journal', $requireChild(fn () => $kid()->journal()));
