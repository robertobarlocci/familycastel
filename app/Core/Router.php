<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

final class Router
{
    /** @var array<string, list<array{pattern: string, regex: string, handler: callable}>> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[strtoupper($method)][] = [
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    public function match(string $method, string $path): ?RouteMatch
    {
        foreach ($this->routes[strtoupper($method)] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m) === 1) {
                $params = array_filter($m, is_string(...), ARRAY_FILTER_USE_KEY);

                return new RouteMatch($route['handler'], $params);
            }
        }

        return null;
    }

    /**
     * Normalize the request URI into a route path:
     * - decodes and collapses dot-segments (traversal-safe),
     * - strips the installation base path,
     * - supports the no-mod_rewrite fallback: /index.php?r=/some/path
     */
    public static function resolvePath(string $requestPath, string $basePath, array $query): string
    {
        $path = rawurldecode(parse_url($requestPath, PHP_URL_PATH) ?: '/');
        $path = self::collapseDotSegments($path);

        // Strip the base only on a real segment boundary: '/family' must not
        // match '/familyevil/...', and collapsed traversal can't fake a prefix.
        // Anything outside the base resolves to a sentinel that matches no route.
        if ($basePath !== '') {
            if ($path === $basePath) {
                $path = '/';
            } elseif (str_starts_with($path, $basePath . '/')) {
                $path = substr($path, strlen($basePath));
            } else {
                return '/__outside-base__';
            }
        }

        if ($path === '' || $path === false) {
            $path = '/';
        }

        if (basename($path) === 'index.php') {
            $r = (string) ($query['r'] ?? '/');
            $path = self::collapseDotSegments('/' . ltrim($r, '/'));
        }

        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    private static function collapseDotSegments(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }
}
