<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/', fn () => 'home');
        $r->get('/health', fn () => 'health');
        $r->get('/kid/quests/{id}', fn (array $p) => 'quest:' . $p['id']);
        $r->post('/api/award', fn () => 'award');
        return $r;
    }

    public function testMatchesStaticRoute(): void
    {
        $m = $this->router()->match('GET', '/health');
        self::assertNotNull($m);
        self::assertSame('health', ($m->handler)($m->params));
    }

    public function testMatchesRootRoute(): void
    {
        $m = $this->router()->match('GET', '/');
        self::assertNotNull($m);
        self::assertSame('home', ($m->handler)($m->params));
    }

    public function testExtractsNamedParams(): void
    {
        $m = $this->router()->match('GET', '/kid/quests/42');
        self::assertNotNull($m);
        self::assertSame(['id' => '42'], $m->params);
        self::assertSame('quest:42', ($m->handler)($m->params));
    }

    public function testMethodMismatchDoesNotMatch(): void
    {
        self::assertNull($this->router()->match('GET', '/api/award'));
        self::assertNotNull($this->router()->match('POST', '/api/award'));
    }

    public function testUnknownPathReturnsNull(): void
    {
        self::assertNull($this->router()->match('GET', '/nope'));
    }

    public function testParamsDoNotSpanSlashes(): void
    {
        self::assertNull($this->router()->match('GET', '/kid/quests/42/edit'));
    }

    public function testResolvePathStripsBasePath(): void
    {
        self::assertSame('/health', Router::resolvePath('/family/health', '/family', []));
        self::assertSame('/health', Router::resolvePath('/health', '', []));
        self::assertSame('/', Router::resolvePath('/family', '/family', []));
        self::assertSame('/', Router::resolvePath('/family/', '/family', []));
    }

    public function testResolvePathSupportsQueryFallbackWithoutRewrite(): void
    {
        // No mod_rewrite: /index.php?r=/kid/quests/42
        self::assertSame('/kid/quests/42', Router::resolvePath('/family/index.php', '/family', ['r' => '/kid/quests/42']));
        self::assertSame('/', Router::resolvePath('/index.php', '', ['r' => '']));
    }

    public function testResolvePathRejectsEscapesFromTheBase(): void
    {
        // Collapsed traversal that leaves the base → sentinel that matches no route (404).
        self::assertSame('/__outside-base__', Router::resolvePath('/family/../etc/passwd', '/family', []));
        self::assertSame('/__outside-base__', Router::resolvePath('/family/%2e%2e/x', '/family', []));
        // Sibling directory sharing the prefix is NOT the base.
        self::assertSame('/__outside-base__', Router::resolvePath('/familyevil/index.php', '/family', ['r' => '/health']));
    }
}
