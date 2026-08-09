<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Tiny PSR-4 autoloader for the FamilyCastel\ namespace so production runs
 * with zero Composer runtime (INV-003). Dev/tests additionally load
 * vendor/autoload.php for PHPUnit.
 */
final class Autoloader
{
    public static function register(string $appDir): void
    {
        spl_autoload_register(static function (string $class) use ($appDir): void {
            $prefix = 'FamilyCastel\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $appDir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
