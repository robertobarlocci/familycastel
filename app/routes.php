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

// ---------------------------------------------------------------- kid area
$router->get('/kid', $requireChild(function () use ($lazyDb, $view): string {
    $child = $lazyDb()->fetchOne('SELECT * FROM children WHERE id = ?', [Auth::childId()]);

    return $view->render('kid/home', ['child' => $child], 'layouts/kid');
}));
