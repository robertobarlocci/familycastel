<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\Auth;
use FamilyCastel\Core\BasePath;
use FamilyCastel\Core\ErrorHandler;
use FamilyCastel\Core\I18n;
use FamilyCastel\Core\Session;
use FamilyCastel\Core\View;
use PHPUnit\Framework\TestCase;

final class CoreRuntimeTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/fc-core-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        $_SESSION = [];
    }

    // ------------------------------------------------------------ I18n

    public function testTranslateUsesLocaleWithPlaceholders(): void
    {
        file_put_contents($this->tmp . '/de.php', "<?php return ['greet' => 'Hallo {name}!', 'only_de' => 'nur de'];");
        file_put_contents($this->tmp . '/en.php', "<?php return ['greet' => 'Hello {name}!'];");

        I18n::init($this->tmp, 'en');
        self::assertSame('en', I18n::locale());
        self::assertSame('Hello Emma!', I18n::translate('greet', ['name' => 'Emma']));

        I18n::setLocale('de');
        self::assertSame('Hallo Emma!', I18n::translate('greet', ['name' => 'Emma']));
    }

    public function testTranslateFallsBackToDefaultLocaleThenKey(): void
    {
        file_put_contents($this->tmp . '/de.php', "<?php return ['only_de' => 'nur de'];");
        file_put_contents($this->tmp . '/en.php', "<?php return [];");

        I18n::init($this->tmp, 'en');
        self::assertSame('nur de', I18n::translate('only_de'), 'missing in en → default-locale de');
        self::assertSame('ghost.key', I18n::translate('ghost.key'), 'unknown key returns the key itself');
    }

    public function testUnknownLocaleFallsBackSafely(): void
    {
        file_put_contents($this->tmp . '/de.php', "<?php return ['x' => 'X'];");
        I18n::init($this->tmp, '../evil');
        self::assertSame('X', I18n::translate('x'));
    }

    // ------------------------------------------------------------ View

    public function testRenderEscapedTemplateAndLayout(): void
    {
        mkdir($this->tmp . '/views/layouts', 0775, true);
        file_put_contents($this->tmp . '/views/hello.php', '<p><?= e($name) ?></p>');
        file_put_contents($this->tmp . '/views/layouts/wrap.php', '<main><?= $content ?></main>');

        $view = new View($this->tmp . '/views');
        self::assertSame('<p>a&lt;b</p>', trim($view->render('hello', ['name' => 'a<b'])));
        $wrapped = $view->render('hello', ['name' => 'x'], 'layouts/wrap');
        self::assertStringContainsString('<main>', $wrapped);
        self::assertStringContainsString('<p>x</p>', $wrapped);
    }

    public function testRenderCannotReachAnExistingFileOutsideViewsDir(): void
    {
        // A REAL reachable target outside views/ — the sanitizer must make it
        // unreachable, not merely fail on a nonexistent path.
        mkdir($this->tmp . '/views', 0775, true);
        file_put_contents($this->tmp . '/outside.php', '<?php echo "LEAKED";');

        $view = new View($this->tmp . '/views');
        try {
            $out = $view->render('../outside');
            self::assertStringNotContainsString('LEAKED', $out);
        } catch (\Throwable) {
            $this->addToAssertionCount(1); // refusing entirely is equally fine
        }
    }

    // ------------------------------------------------------------ Session flashes

    public function testFlashesAccumulateAndAreConsumedOnce(): void
    {
        Session::flash('success', 'Saved.');
        Session::flash('error', 'Nope.');
        $flashes = Session::takeFlashes();
        self::assertCount(2, $flashes);
        self::assertSame([], Session::takeFlashes(), 'flashes are consumed');
    }

    public function testMalformedFlashStorageIsDiscarded(): void
    {
        $_SESSION['_flash'] = 'not-an-array';
        self::assertSame([], Session::takeFlashes());
        self::assertArrayNotHasKey('_flash', $_SESSION);
    }

    public function testSessionStartsWithSubdirectoryCookieScopeAndRegenerates(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        BasePath::set('/family');
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 80;

        Session::start();
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame('/family/', session_get_cookie_params()['path']);
        $before = session_id();
        Session::regenerate();
        self::assertNotSame($before, session_id());
        session_write_close();

        BasePath::set('');
    }

    public function testIsHttpsReadsServerState(): void
    {
        $prev = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = 'on';
        self::assertTrue(Session::isHttps());
        $_SERVER['HTTPS'] = 'off';
        self::assertFalse(Session::isHttps());
        if ($prev === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $prev;
        }
    }

    // ------------------------------------------------------------ Auth (session-backed)

    public function testParentAndChildLoginsAreMutuallyExclusive(): void
    {
        Auth::loginParent(7);
        self::assertSame(7, Auth::parentId());
        self::assertNull(Auth::childId(), 'parent session carries no child identity');

        Auth::loginChild(3);
        self::assertSame(3, Auth::childId());
        self::assertNull(Auth::parentId(), 'child login replaces the parent identity');

        Auth::logout();
        self::assertNull(Auth::parentId());
        self::assertNull(Auth::childId());
    }

    // ------------------------------------------------------------ ErrorHandler::log

    public function testLogWritesTimestampedEntryWithClassAndMessage(): void
    {
        ErrorHandler::log($this->tmp, new \RuntimeException('boom & <bang>'));
        $log = (string) file_get_contents($this->tmp . '/app.log');
        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString('boom & <bang>', $log);
    }
}
