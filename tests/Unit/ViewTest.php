<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * The view layer must never silently drop a variable it was handed.
 *
 * Issue #12: `renderFile()` held the view NAME in a local called `$template`
 * and called `extract($data, EXTR_SKIP)`, so a controller passing
 * `['template' => …]` had its value discarded — and the template then saw the
 * view-name string instead. `$template['child_ids']` became a string offset,
 * a TypeError, and a 500 on every create/edit screen for point templates.
 *
 * The failure mode is what makes this worth a test: nothing warned, and the
 * error surfaced far from its cause, inside an unrelated-looking template.
 */
final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fc-view-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/sub', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/sub/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir . '/sub');
        @rmdir($this->dir);
    }

    private function writeView(string $name, string $php): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', $php);
    }

    /** The exact shape of issue #12. */
    public function testAViewVariableNamedTemplateIsNotSwallowed(): void
    {
        $this->writeView('sub/form', '<?= $template === null ? "NULL" : $template["id"] ?>');

        $view = new View($this->dir);

        self::assertSame('NULL', $view->render('sub/form', ['template' => null]));
        self::assertSame('7', $view->render('sub/form', ['template' => ['id' => 7]]));
    }

    /**
     * `$data` and `$file` were the other two locals in renderFile(), i.e. the
     * same trap waiting for the next controller that happens to use those names.
     */
    public function testTheOtherFormerlyCollidingNamesAlsoArrive(): void
    {
        $this->writeView('sub/other', '<?= $data ?>|<?= $file ?>');

        self::assertSame(
            'D|F',
            (new View($this->dir))->render('sub/other', ['data' => 'D', 'file' => 'F'])
        );
    }

    public function testLayoutsStillReceiveTheirDataAndTheRenderedContent(): void
    {
        $this->writeView('sub/inner', 'INNER:<?= $template ?>');
        $this->writeView('shell', '[<?= $content ?>|<?= $template ?>]');

        self::assertSame(
            '[INNER:X|X]',
            (new View($this->dir))->render('sub/inner', ['template' => 'X'], 'shell')
        );
    }

    /** A reserved internal name must fail loudly rather than be dropped. */
    public function testAReservedInternalNameIsRejected(): void
    {
        $this->writeView('sub/plain', 'ok');

        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('sub/plain', ['__view' => 'nope']);
    }

    /** `this` always exists in method scope, so extract() would skip it. */
    public function testAViewVariableNamedThisIsRejectedRatherThanSkipped(): void
    {
        $this->writeView('sub/plain', 'ok');

        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('sub/plain', ['this' => 'nope']);
    }

    /** extract() silently ignores numeric keys — another quiet drop. */
    public function testAKeyThatIsNotAUsableVariableNameIsRejected(): void
    {
        $this->writeView('sub/plain', 'ok');

        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('sub/plain', ['not a name' => 'nope']);
    }

    /**
     * `$data + ['content' => …]` keeps the LEFT side, so a caller-supplied
     * 'content' used to replace the rendered body — the same silent-drop family
     * as #12, in the other direction.
     */
    public function testACallerCannotOverwriteTheRenderedBody(): void
    {
        $this->writeView('sub/inner', 'REAL');
        $this->writeView('shell', '[<?= $content ?>]');

        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('sub/inner', ['content' => 'HIJACKED'], 'shell');
    }

    /**
     * renderFile() must own no local a view could be handed. An earlier attempt
     * at this fix introduced `$__key`/`$__ignored` in a validation loop — and
     * promptly reopened the same hole for those two names. Key validation now
     * lives in another scope; this asserts the surviving locals are the only
     * reserved ones and that they are all actually rejected.
     */
    public function testEveryReservedNameIsRejectedAndThereAreNoOthers(): void
    {
        $this->writeView('sub/plain', 'ok');
        $view = new View($this->dir);

        foreach (['__view', '__data', '__file', 'this'] as $reserved) {
            try {
                $view->render('sub/plain', [$reserved => 'x']);
                self::fail("reserved name \${$reserved} was accepted");
            } catch (\RuntimeException) {
                // expected
            }
        }

        // Names a previous iteration of this fix would have swallowed.
        self::assertSame('ok', $view->render('sub/plain', ['__key' => 'x', '__ignored' => 'y']));
    }

    public function testTraversalIsStillStripped(): void
    {
        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('../../etc/passwd');
    }
}
