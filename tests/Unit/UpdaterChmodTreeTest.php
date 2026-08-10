<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * fc_chmod_tree() — the updater's explicit-mode normalizer.
 *
 * Context: update.php never set a mode. Staged directories were created with
 * mkdir(..., 0770) and fc_step_swap promotes the staged inode with rename(),
 * so the live tree inherited 0750 (or 0700 under a tighter umask). On hosting
 * where PHP runs as the account owner but Apache serves static files as a
 * different uid, a 0750 directory is not traversable by the static-file
 * server: every asset falls through mod_rewrite's !-f test to the front
 * controller and 404s. chmod() ignores umask, which is exactly why the mode
 * must be set explicitly rather than inherited.
 *
 * These tests are loaded through the dormant library hook at the bottom of
 * update.php (`if (defined('FC_UPDATE_LIB')) { return; }`), which exposes the
 * fc_* functions without running the request dispatcher.
 */
final class UpdaterChmodTreeTest extends TestCase
{
    private string $dir = '';
    private int $umask = 0;

    public static function setUpBeforeClass(): void
    {
        if (!defined('FC_UPDATE_LIB')) {
            define('FC_UPDATE_LIB', true);
        }
        require_once FC_ROOT . '/update.php';
    }

    protected function setUp(): void
    {
        $this->umask = umask();
        $this->dir = sys_get_temp_dir() . '/fc-chmodtree-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** Build the shape a staged release has: nested directories holding files. */
    private function seedTree(string $root): void
    {
        mkdir($root . '/public-assets/css', 0700, true);
        mkdir($root . '/public-assets/fonts', 0700, true);
        mkdir($root . '/app/Core', 0700, true);
        file_put_contents($root . '/public-assets/css/app.css', 'body{}');
        file_put_contents($root . '/public-assets/fonts/nunito.woff2', 'font');
        file_put_contents($root . '/app/Core/Router.php', '<?php');
        file_put_contents($root . '/index.php', '<?php');
        chmod($root . '/public-assets/css/app.css', 0600);
        chmod($root . '/public-assets/fonts/nunito.woff2', 0600);
        chmod($root . '/app/Core/Router.php', 0600);
        chmod($root . '/index.php', 0600);
    }

    /** @return array{dirs: list<string>, files: list<string>} */
    private function walk(string $root): array
    {
        $dirs = [$root];
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }
            $item->isDir() ? $dirs[] = $item->getPathname() : $files[] = $item->getPathname();
        }

        return ['dirs' => $dirs, 'files' => $files];
    }

    private function assertTreeNormalized(string $root): void
    {
        ['dirs' => $dirs, 'files' => $files] = $this->walk($root);

        self::assertNotEmpty($dirs, 'the fixture must contain directories');
        self::assertNotEmpty($files, 'the fixture must contain files');

        foreach ($dirs as $dir) {
            self::assertSame(
                0755,
                fileperms($dir) & 0777,
                $dir . ' must be world-traversable — Apache serves static files as another uid'
            );
        }
        foreach ($files as $file) {
            self::assertSame(0644, fileperms($file) & 0777, $file . ' must be world-readable');
        }
    }

    public function test_it_sets_0755_on_directories_and_0644_on_files(): void
    {
        $this->seedTree($this->dir);

        self::assertTrue(fc_chmod_tree($this->dir));
        $this->assertTreeNormalized($this->dir);
    }

    /**
     * The regression that shipped: modes were whatever `mode & ~umask` produced.
     * chmod() is not masked by umask, so a hostile umask must change nothing.
     */
    public function test_it_is_unaffected_by_a_restrictive_umask(): void
    {
        umask(0077);
        $this->seedTree($this->dir);

        // Guard: prove the umask really is biting, or this test proves nothing.
        mkdir($this->dir . '/ambient', 0770, true);
        self::assertSame(0700, fileperms($this->dir . '/ambient') & 0777);

        self::assertTrue(fc_chmod_tree($this->dir));
        $this->assertTreeNormalized($this->dir);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedTree($this->dir);

        self::assertTrue(fc_chmod_tree($this->dir));
        self::assertTrue(fc_chmod_tree($this->dir), 'a resumed step re-runs normalization');
        $this->assertTreeNormalized($this->dir);
    }

    public function test_it_returns_false_when_the_root_does_not_exist(): void
    {
        self::assertFalse(fc_chmod_tree($this->dir . '/missing'));
    }

    /**
     * chmod() follows symlinks, so walking one would change a file OUTSIDE the
     * staged tree. The extractor only ever writes regular files and the release
     * builder refuses to package symlinks, so encountering one means something
     * is wrong — skip it rather than follow it out of tree.
     */
    public function test_it_skips_symlinks_instead_of_following_them(): void
    {
        $this->seedTree($this->dir);

        $outside = sys_get_temp_dir() . '/fc-chmodtree-outside-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'secret');
        chmod($outside, 0600);
        symlink($outside, $this->dir . '/app/link.php');

        try {
            self::assertTrue(fc_chmod_tree($this->dir));
            self::assertSame(
                0600,
                fileperms($outside) & 0777,
                'a symlinked target outside the staged tree must never be chmodded'
            );
        } finally {
            @unlink($outside);
        }
    }
}
