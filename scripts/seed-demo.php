<?php

/**
 * Dev/CI helper: seed the demo family into the CURRENT installation.
 * Usage (dev): docker compose -f docker/compose.yaml exec app php scripts/seed-demo.php
 * Refuses to run when children already exist.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('FC_ROOT', dirname(__DIR__));
require FC_ROOT . '/app/Core/Autoloader.php';
FamilyCastel\Core\Autoloader::register(FC_ROOT . '/app');
require FC_ROOT . '/app/Core/helpers.php';

$config = new FamilyCastel\Core\Config(FC_ROOT . '/config');
if (!$config->isInstalled()) {
    exit("Not installed — run the web installer first.\n");
}

$db = FamilyCastel\Core\Db::fromConfig($config);
$parent = $db->fetchOne("SELECT id FROM users WHERE role = 'parent' ORDER BY id LIMIT 1");
if ($parent === null) {
    exit("No parent account found.\n");
}

$seeded = (new FamilyCastel\Domain\DemoSeeder($db))->run((int) $parent['id']);
echo $seeded ? "Demo family seeded. 🏰\n" : "Refused: children already exist.\n";
exit($seeded ? 0 : 1);
