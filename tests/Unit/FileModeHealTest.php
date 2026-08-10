<?php

declare(strict_types=1);

namespace Tests\Unit;

use FamilyCastel\Core\FileModeHeal;
use PHPUnit\Framework\TestCase;

/**
 * The post-update self-heal.
 *
 * update.php is excluded from the swap and replaced LAST, so the update that
 * carries the updater's own mode fix is driven by the OLD, unfixed executor —
 * proven with the real e2e suite. Without this heal the owner's next click of
 * "Update" still ships 0750 directories and breaks every asset.
 *
 * The design deliberately keeps NO "already healed" state: it probes the real
 * property (is public-assets/ world-traversable) on every request, and the walk
 * sets that bit LAST so it is a completion witness rather than a sample.
 */
final class FileModeHealTest extends TestCase
{
    private string $root = '';
    private int $umask = 0;

    protected function setUp(): void
    {
        $this->umask = umask();
        $this->root = sys_get_temp_dir() . '/fc-heal-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->seedApp();
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** A tree in the exact broken shape a swap by the old executor produces. */
    private function seedApp(int $mode = 0750): void
    {
        foreach (['app/Core', 'views/parent', 'lang', 'public-assets/css', 'public-assets/fonts', 'storage/cache', 'config'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }
        $files = [
            'app/Core/Router.php', 'views/parent/home.php', 'lang/de.php',
            'public-assets/css/app.css', 'public-assets/fonts/nunito.woff2',
            'index.php', '.htaccess', 'sw.js', 'offline.html', 'VERSION',
            'config/config.php', 'storage/secret.txt',
        ];
        foreach ($files as $file) {
            file_put_contents($this->root . '/' . $file, 'x');
        }
        // Everything the updater swapped is broken; config/ and storage/ hold
        // secrets whose tight modes must survive untouched.
        foreach (['app', 'app/Core', 'views', 'views/parent', 'lang', 'public-assets', 'public-assets/css', 'public-assets/fonts'] as $dir) {
            chmod($this->root . '/' . $dir, $mode);
        }
        foreach ($files as $file) {
            chmod($this->root . '/' . $file, 0600);
        }
        chmod($this->root . '/config/config.php', 0640);
        chmod($this->root . '/storage/secret.txt', 0600);
    }

    private function heal(): string
    {
        return FileModeHeal::ensure($this->root, $this->root . '/storage/cache', null);
    }

    private function mode(string $relative): int
    {
        clearstatcache(true, $this->root . '/' . $relative);

        return fileperms($this->root . '/' . $relative) & 0777;
    }

    public function test_it_heals_a_tree_a_swap_left_unservable(): void
    {
        self::assertSame(FileModeHeal::OK, $this->heal());

        foreach (['app', 'app/Core', 'views', 'views/parent', 'lang', 'public-assets', 'public-assets/css', 'public-assets/fonts'] as $dir) {
            self::assertSame(0755, $this->mode($dir), $dir);
        }
        foreach (['app/Core/Router.php', 'public-assets/css/app.css', 'index.php', '.htaccess', 'sw.js', 'VERSION'] as $file) {
            self::assertSame(0644, $this->mode($file), $file);
        }
    }

    public function test_it_never_touches_config_or_storage(): void
    {
        self::assertSame(FileModeHeal::OK, $this->heal());

        self::assertSame(0640, $this->mode('config/config.php'), 'config.php must stay 0640');
        self::assertSame(0600, $this->mode('storage/secret.txt'), 'storage secrets must stay 0600');
    }

    /** The steady state: probe passes, so nothing is walked and nothing changes. */
    public function test_it_is_a_no_op_once_the_witness_bit_is_set(): void
    {
        self::assertSame(FileModeHeal::OK, $this->heal());

        // Break a descendant WITHOUT clearing the witness. The probe short-circuits,
        // which is the documented cost of a one-stat fast path.
        chmod($this->root . '/public-assets/css/app.css', 0600);
        self::assertSame(FileModeHeal::OK, $this->heal());
        self::assertSame(0600, $this->mode('public-assets/css/app.css'));
    }

    /**
     * The witness must be a COMPLETION witness: an interrupted walk leaves
     * public-assets/ unset, so the next request re-heals everything.
     */
    public function test_an_interrupted_walk_is_redone(): void
    {
        // Simulates a walk that fixed some descendants and died before the witness.
        chmod($this->root . '/public-assets/css', 0755);
        chmod($this->root . '/public-assets/css/app.css', 0644);

        self::assertSame(FileModeHeal::OK, $this->heal());
        self::assertSame(0755, $this->mode('public-assets'));
        self::assertSame(0755, $this->mode('public-assets/fonts'), 'the missed subtree must be healed');
        self::assertSame(0644, $this->mode('public-assets/fonts/nunito.woff2'));
    }

    public function test_it_sets_the_witness_bit_last(): void
    {
        // Before healing the witness must be unset, or the probe would short-circuit
        // and this whole suite would prove nothing.
        self::assertNotSame(0755, $this->mode('public-assets'));
        self::assertSame(FileModeHeal::OK, $this->heal());
        self::assertSame(0755, $this->mode('public-assets'));
    }

    public function test_it_is_unaffected_by_a_restrictive_umask(): void
    {
        umask(0077);
        self::assertSame(FileModeHeal::OK, $this->heal());
        self::assertSame(0755, $this->mode('public-assets'));
        self::assertSame(0644, $this->mode('public-assets/css/app.css'));
    }

    public function test_it_skips_while_an_ops_operation_owns_the_system(): void
    {
        mkdir($this->root . '/storage/ops.lock', 0777, true);

        self::assertSame(FileModeHeal::SKIPPED, $this->heal());
        self::assertNotSame(0755, $this->mode('public-assets'), 'no walk may happen during an ops operation');
    }

    public function test_it_skips_symlinks_instead_of_following_them(): void
    {
        $outside = sys_get_temp_dir() . '/fc-heal-outside-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'secret');
        chmod($outside, 0600);
        symlink($outside, $this->root . '/app/link.php');

        try {
            self::assertSame(FileModeHeal::OK, $this->heal());
            self::assertSame(0600, fileperms($outside) & 0777, 'a symlink target outside the tree must never be chmodded');
        } finally {
            @unlink($outside);
        }
    }

    /**
     * A symlinked public-assets must be refused outright. Following it would let
     * the walk recurse into whatever it points at — including config/ and
     * storage/ — and chmod their 0640/0600 secrets to 0644.
     */
    public function test_it_refuses_a_symlinked_public_assets_instead_of_following_it(): void
    {
        $decoy = $this->root . '/decoy';
        mkdir($decoy . '/nested', 0700, true);
        file_put_contents($decoy . '/nested/secret.txt', 'x');
        chmod($decoy . '/nested/secret.txt', 0600);

        exec('rm -rf ' . escapeshellarg($this->root . '/public-assets'));
        symlink($decoy, $this->root . '/public-assets');

        self::assertSame(FileModeHeal::FAILED, $this->heal());
        self::assertSame(0600, fileperms($decoy . '/nested/secret.txt') & 0777, 'nothing outside the tree may be chmodded');
        self::assertSame(0700, fileperms($decoy . '/nested') & 0777);
    }

    /**
     * A missing allowlist entry is not a failure — a release may legitimately
     * retire one (offline.html is in the current manifest's removed[]).
     */
    public function test_a_missing_allowlist_entry_is_not_a_failure(): void
    {
        unlink($this->root . '/offline.html');

        self::assertSame(FileModeHeal::OK, $this->heal());
        self::assertSame(0755, $this->mode('public-assets'));
    }
}
