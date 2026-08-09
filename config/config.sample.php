<?php

/**
 * Family Castel configuration SAMPLE.
 * The real config/config.php is written by the web installer — do not create
 * it by hand unless you know what you are doing, and never commit it.
 */

declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'familycastel',
        'user' => 'familycastel',
        'password' => 'CHANGE-ME',
        'prefix' => '',
    ],
    'app' => [
        'locale' => 'de',            // de | en | fr | it
        'timezone' => 'Europe/Zurich',
        'debug' => false,            // never true in production
        'secret' => 'GENERATED-BY-INSTALLER',
    ],
];
