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
use FamilyCastel\Core\RememberLogin;
use FamilyCastel\Core\Router;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;

$db = null;
$lazyDb = function () use (&$db, $config): Db {
    return $db ??= Db::fromConfig($config);
};
$view = new View(FC_ROOT . '/views');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$requireInstalled = function (callable $handler) use ($config, $lazyDb): callable {
    return function (array $params) use ($handler, $config, $lazyDb): string {
        if (!$config->isInstalled()) {
            header('Location: ' . url('/install'), true, 302);

            return '';
        }
        Session::start();
        // The single point where identity is established, so the single place a
        // "stay signed in" cookie can restore it. No-op (and no query) when the
        // session already carries a principal.
        RememberLogin::restore($lazyDb());

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
            // Only destroy the device token when a PARENT session really lost
            // its eligibility (deactivated). If there is no parent principal at
            // all we are just the wrong door — a signed-in child following a
            // /parent link — and revoking here would throw away their valid
            // 400-day login for a mistaken tap.
            if (Auth::parentId() !== null) {
                RememberLogin::forget($lazyDb());
                Auth::logout();
            }
            header('Location: ' . url('/login'), true, 302);

            return '';
        }

        return $handler($params);
    });
};

/**
 * Step-up guard for the operations that are irreversible or that disclose the
 * whole installation. A persistent login keeps the family signed in for everyday
 * use; it deliberately does NOT carry the authority to wipe, restore, update or
 * download the database, so those routes want the password again.
 *
 * POSTs are refused rather than queued and replayed after re-auth: replaying a
 * stored destructive POST across an authentication boundary is a far worse
 * failure mode than one extra click.
 */
$requireRecentAuth = function (callable $handler) use (&$requireParent): callable {
    return $requireParent(function (array $params) use ($handler): string {
        if (RememberLogin::hasRecentPassword()) {
            return $handler($params);
        }

        $path = Router::resolvePath(
            (string) ($_SERVER['REQUEST_URI'] ?? '/parent'),
            \FamilyCastel\Core\BasePath::get(),
            $_GET
        );
        // Only remember a GET target: a POST must be re-issued by the parent,
        // never replayed by us.
        RememberLogin::rememberReturnPath(
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? $path : '/parent/settings/backups'
        );
        Session::flash('error', t('auth.confirm_required'));
        header('Location: ' . url('/parent/confirm-password'), true, 302);

        return '';
    });
};

$requireChild = function (callable $handler) use ($requireInstalled, $lazyDb): callable {
    return $requireInstalled(function (array $params) use ($handler, $lazyDb): string {
        $eligible = Auth::childId() !== null && $lazyDb()->fetchOne(
            'SELECT id FROM children WHERE id = ? AND archived_at IS NULL',
            [Auth::childId()]
        ) !== null;

        if (!$eligible) {
            // Same reasoning as the parent guard: revoke only when a CHILD
            // session lost its eligibility (archived). A signed-in parent who
            // opens /kid must keep their own token.
            if (Auth::childId() !== null) {
                RememberLogin::forget($lazyDb());
                Auth::logout();
            }
            header('Location: ' . url('/kid/login'), true, 302);

            return '';
        }

        return $handler($params);
    });
};

// ---------------------------------------------------------------- public
// Rewrite-capability probe: reachable under its PRETTY path only when
// mod_rewrite routes it here — the installer uses this to decide between
// pretty URLs and the ?r= fallback. Static marker, no state, no auth.
$router->get('/__fc/rewrite-probe', function (): string {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');

    return 'fc-rewrite-ok';
});

// Browsers request /favicon.ico unconditionally — answer with the castle
// icon instead of littering every session with 404s.
$router->get('/favicon.ico', function (): string {
    header('Location: ' . url('/public-assets/icons/icon.svg'), true, 302);
    header('Cache-Control: public, max-age=86400');

    return '';
});

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
// Cookie notice dismissal — available to anyone with a session (the notice is
// shown on the login page too), CSRF-protected, no user-controlled redirect.
$router->post('/cookie-notice', $requireInstalled(fn () => (new \FamilyCastel\Http\CookieNoticeController())->dismiss($_POST)));
// Step-up re-authentication. Guarded by $requireParent (not $requireRecentAuth —
// that would be circular): you must already be signed in to confirm a password.
$router->get('/parent/confirm-password', $requireParent(fn () => (new \FamilyCastel\Http\AuthController($lazyDb(), $view))->confirmPasswordForm()));
$router->post('/parent/confirm-password', $requireParent(fn () => (new \FamilyCastel\Http\AuthController($lazyDb(), $view))->confirmPassword($_POST, $ip)));

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

$ops = fn () => new \FamilyCastel\Http\Parent\OpsController($lazyDb(), $view);
$router->get('/parent/settings/updates', $requireParent(fn () => $ops()->updates()));
$router->post('/parent/settings/updates/check', $requireParent(fn () => $ops()->checkNow($_POST)));
$router->post('/parent/settings/updates/start', $requireRecentAuth(fn () => $ops()->startUpdate($_POST, $ip)));
$router->post('/parent/settings/updates/start-manual', $requireRecentAuth(fn () => $ops()->startManualUpdate($_POST, $ip)));
$router->get('/parent/settings/backups', $requireParent(fn () => $ops()->backupsPage()));
$router->post('/parent/settings/backups/create', $requireRecentAuth(fn () => $ops()->createBackup($_POST, $ip)));
$router->get('/parent/settings/backups/{id}/download', $requireRecentAuth(fn (array $p) => $ops()->downloadBackup((string) $p['id'])));
$router->post('/parent/settings/backups/{id}/delete', $requireRecentAuth(fn (array $p) => $ops()->deleteBackup((string) $p['id'], $_POST, $ip)));
$router->post('/parent/settings/backups/{id}/restore', $requireRecentAuth(fn (array $p) => $ops()->restore((string) $p['id'], $_POST, $ip)));
$router->get('/parent/settings/status', $requireParent(fn () => $ops()->status()));
$router->get('/parent/settings/diagnostics', $requireRecentAuth(fn () => $ops()->diagnostics()));

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
