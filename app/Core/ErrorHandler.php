<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Production-safe error handling: full details go to storage/logs/app.log,
 * the browser only ever sees a friendly error page. No stack traces leak.
 */
final class ErrorHandler
{
    public static function register(string $logDir, bool $debug = false): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');

        set_exception_handler(static function (\Throwable $e) use ($logDir, $debug): void {
            self::log($logDir, $e);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }
            if ($debug) {
                echo '<pre>' . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
            } else {
                echo '<!doctype html><meta charset="utf-8"><title>Family Castel</title>'
                    . '<style>body{font-family:system-ui;display:grid;place-items:center;min-height:90vh;background:#f7f4ec}'
                    . 'div{text-align:center;max-width:26rem}</style>'
                    . '<div><h1>🏰 Oh no!</h1><p>Something went wrong in the castle. '
                    . 'Please try again in a moment.</p></div>';
            }
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function log(string $logDir, \Throwable $e): void
    {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n",
            gmdate('c'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents($logDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
