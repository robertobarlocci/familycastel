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

use FamilyCastel\Core\Autoloader;
use FamilyCastel\Core\BasePath;
use FamilyCastel\Core\Config;
use FamilyCastel\Core\ErrorHandler;
use FamilyCastel\Core\I18n;
use FamilyCastel\Core\Router;

Autoloader::register(__DIR__ . '/app');
require __DIR__ . '/app/Core/helpers.php';

$config = new Config(__DIR__ . '/config');
ErrorHandler::register(__DIR__ . '/storage/logs', (bool) $config->get('app.debug', false));

BasePath::set(BasePath::detect((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
I18n::init(__DIR__ . '/lang', (string) $config->get('app.locale', I18n::DEFAULT_LOCALE));
date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

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
