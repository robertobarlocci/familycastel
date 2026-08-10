<?php

declare(strict_types=1);

/**
 * Headless install for CI / dev automation (never shipped, never web-reachable).
 * Uses the SAME Installer service as the wizard — no shortcut code path.
 *
 *   php scripts/ci-install.php --db-host=db --db-name=familycastel \
 *       --db-user=fc --db-pass=fc-dev-password [--demo]
 *
 * The parent account defaults to the documented demo credentials.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('FC_ROOT', dirname(__DIR__));

require FC_ROOT . '/app/Core/Autoloader.php';
\FamilyCastel\Core\Autoloader::register(FC_ROOT . '/app');
require FC_ROOT . '/app/Core/helpers.php';

$options = getopt('', ['db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::', 'demo', 'parent-user::', 'parent-pass::']);
$str = static fn (string $key, string $default): string => is_string($options[$key] ?? null) && $options[$key] !== '' ? $options[$key] : $default;

try {
    (new \FamilyCastel\Install\Installer(FC_ROOT . '/config', FC_ROOT . '/app/Database/Migrations'))->perform([
        'db' => [
            'host' => $str('db-host', 'db'),
            'port' => (int) $str('db-port', '3306'),
            'name' => $str('db-name', 'familycastel'),
            'user' => $str('db-user', 'fc'),
            'password' => $str('db-pass', 'fc-dev-password'),
            'prefix' => '',
        ],
        'family' => ['name' => 'Familie Barlocci-Demo', 'locale' => 'de', 'timezone' => 'Europe/Zurich'],
        'parent' => [
            'name' => 'Demo Parent',
            'username' => $str('parent-user', 'demo-parent'),
            'password' => $str('parent-pass', 'Schloss-Demo-2026'),
        ],
        'demo' => array_key_exists('demo', $options),
    ]);
    fwrite(STDOUT, "Installed OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'INSTALL FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
