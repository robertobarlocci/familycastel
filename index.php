<?php

declare(strict_types=1);

// First lines of defense: never display errors to the browser, even if a later
// require fails before the real ErrorHandler is registered (shared hosts often
// ship display_errors=On).
ini_set('display_errors', '0');
error_reporting(E_ALL);

define('FC_ROOT', __DIR__);
define('FC_VERSION', trim((string) @file_get_contents(__DIR__ . '/VERSION')) ?: '0.0.0');

require __DIR__ . '/app/Core/Autoloader.php';

use FamilyCastel\Core\Assets;
use FamilyCastel\Core\Autoloader;
use FamilyCastel\Core\BasePath;
use FamilyCastel\Core\Config;
use FamilyCastel\Core\Db;
use FamilyCastel\Core\ErrorHandler;
use FamilyCastel\Core\FileModeHeal;
use FamilyCastel\Core\I18n;
use FamilyCastel\Core\Router;

Autoloader::register(__DIR__ . '/app');
require __DIR__ . '/app/Core/helpers.php';

$config = new Config(__DIR__ . '/config');
ErrorHandler::register(__DIR__ . '/storage/logs', (bool) $config->get('app.debug', false));

// Asset URLs carry the app version so a released CSS/JS change is not masked by
// a cached copy (no Cache-Control ships — see Assets).
Assets::setVersion(FC_VERSION);

BasePath::set(BasePath::detect((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
BasePath::setPrettyUrls(BasePath::shouldUsePrettyUrls(
    $config->isInstalled(),
    (bool) $config->get('app.pretty_urls', true)
));
I18n::init(__DIR__ . '/lang', (string) $config->get('app.locale', I18n::DEFAULT_LOCALE));
date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

// Manual-FTP-update boot gate (plan §9): when the CODE version differs from
// the recorded app version, no request may run on mixed code/schema. Parents
// keep a narrow path to finish the update; everything else gets the 503.
if ($config->isInstalled()) {
    try {
        $gateDb = FamilyCastel\Core\Db::fromConfig($config);
        $row = $gateDb->fetchOne('SELECT `value` FROM settings WHERE `key` = ?', ['app.version']);
        if ($row === null) {
            // Legacy install predating the gate: no comparison possible.
            $recorded = FC_VERSION;
        } else {
            $decoded = json_decode((string) $row['value'], true);
            // Malformed value = FAIL CLOSED (treat as mismatch, never as OK).
            $recorded = is_string($decoded) && $decoded !== '' ? $decoded : '0-malformed';
        }
        if ($recorded !== FC_VERSION) {
            // EXACT route match after base-path stripping — no suffix tricks.
            $path = FamilyCastel\Core\Router::resolvePath(
                (string) ($_SERVER['REQUEST_URI'] ?? '/'),
                FamilyCastel\Core\BasePath::get(),
                $_GET
            );
            $allowed = ['/login', '/logout', '/parent/settings/updates', '/parent/settings/updates/check', '/parent/settings/updates/start-manual'];
            if (!in_array($path, $allowed, true)) {
                http_response_code(503);
                header('Retry-After: 300');
                header('Content-Type: text/html; charset=UTF-8');
                echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                    . '<title>Family Castel</title>'
                    . '<style>body{font-family:system-ui;display:grid;place-items:center;min-height:90vh;background:#1a1f3c;color:#fff}'
                    . 'div{text-align:center;max-width:28rem;padding:1rem}a{color:#9be564}</style>'
                    . '<div><h1>🏰🔧</h1><h2>' . e(t('maintenance.title')) . '</h2>'
                    . '<p>' . e(t('maintenance.update_pending', ['code' => FC_VERSION, 'db' => $recorded])) . '</p>'
                    . '<p><a href="' . e(url('/parent/settings/updates')) . '">' . e(t('maintenance.finish_update')) . '</a></p></div>';
                exit;
            }
        }
    } catch (Throwable) {
        // Gate check must never take the site down harder than the problem itself.
    }
}

// Maintenance mode: friendly 503 before anything else touches the app.
if (is_file(__DIR__ . '/storage/maintenance.flag')) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Family Castel</title>'
        . '<style>body{font-family:system-ui;display:grid;place-items:center;min-height:90vh;background:#1a1f3c;color:#fff}'
        . 'div{text-align:center;max-width:26rem;padding:1rem}</style>'
        . '<div><h1>🏰💤</h1><h2>' . e(t('maintenance.title')) . '</h2><p>' . e(t('maintenance.body')) . '</p></div>';
    exit;
}

// Post-update file-mode self-heal. The updater never swaps update.php — the
// release's copy is installed last — so an update that fixes the updater is
// itself driven by the OLD executor and can still lay down directories the
// static file server cannot traverse. The NEW code therefore repairs the tree
// the old code just wrote. Steady state is a single stat(); the walk only runs
// when assets are provably unservable, and it joins the updater's writer drain
// so it can never overlap a swap.
$healStatus = FileModeHeal::ensure(
    __DIR__,
    __DIR__ . '/storage/cache',
    static fn (): ?PDO => $config->isInstalled() ? Db::fromConfig($config)->pdo() : null
);
if ($healStatus === FileModeHeal::FAILED) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Family Castel</title>'
        . '<style>body{font-family:system-ui;display:grid;place-items:center;min-height:90vh;background:#1a1f3c;color:#fff}'
        . 'div{text-align:center;max-width:30rem;padding:1rem}code{background:rgba(0,0,0,.35);padding:.1rem .3rem;border-radius:4px}</style>'
        . '<div><h1>🏰🔑</h1><h2>' . e(t('maintenance.title')) . '</h2>'
        . '<p>' . e(t('maintenance.permissions')) . '</p>'
        . '<p><code>app, views, lang, public-assets → 0755 / 0644</code></p></div>';
    exit;
}

// Security headers (global).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
// worker-src blob: lets the vendored canvas-confetti render off-thread; only
// scripts already allowed by script-src 'self' can create such workers.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; worker-src 'self' blob:; connect-src 'self'; base-uri 'self'; form-action 'self'");

$router = new Router();
require __DIR__ . '/app/routes.php';

$path = Router::resolvePath(
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    BasePath::get(),
    $_GET
);

$match = $router->match((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path);

if ($match === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>404</title><h1>🗺️ ' . e(t('error.404_title')) . '</h1>'
        . '<p>' . e(t('error.404_body')) . '</p>';
    exit;
}

echo ($match->handler)($match->params);
