<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The service-worker shell must agree with what the pages actually request.
 *
 * `caches.match()` matches on the FULL url including the query, so a precached
 * 'public-assets/css/app.css' can never answer a request for
 * 'public-assets/css/app.css?v=0.1.3'. The two sides therefore have to be
 * versioned in lockstep, and this test is what stops them drifting apart —
 * drift is silent: every lookup simply misses and the PWA quietly stops being
 * offline-capable while all the normal tests stay green.
 *
 * The split rule: an asset is versioned exactly when EVERY requester of it can
 * carry the token. CSS/JS come from PHP-rendered tags, and offline.html is
 * requested only by the worker itself, so those are versioned. Fonts are
 * requested from inside fonts.css and icons from the webmanifest, so those
 * cannot be.
 */
final class ServiceWorkerShellTest extends TestCase
{
    /**
     * Emitted with a token but deliberately NOT precached — the shell has never
     * held them. Leaving them out costs nothing (a miss just goes to the
     * network); adding them would widen `cache.addAll`, which is atomic, so one
     * bad entry would break service-worker installation outright. Tracked as a
     * separate issue rather than folded into a cache-busting fix.
     *
     * Listing them here is the point: an asset may be absent from the shell only
     * as a recorded decision, never by accident.
     */
    private const NOT_PRECACHED = [
        'public-assets/js/navigation.js',
        'public-assets/js/qr-render.js',
        'public-assets/vendor/qrcode.js',
    ];

    private string $sw;

    protected function setUp(): void
    {
        $this->sw = (string) file_get_contents(FC_ROOT . '/sw.js');
    }

    /** @return list<string> */
    private function jsArray(string $constName): array
    {
        $pattern = '/const\s+' . preg_quote($constName, '/') . '\s*=\s*\[(.*?)\];/s';
        self::assertSame(1, preg_match($pattern, $this->sw, $m), "sw.js has no {$constName} array");

        preg_match_all("/'([^']+)'/", $m[1], $entries);

        return $entries[1];
    }

    public function testCacheNameIsDerivedFromTheTokenAndNotAHardcodedV1(): void
    {
        self::assertStringNotContainsString(
            "'fc-shell-v1:'",
            $this->sw,
            'the shell cache name must follow the app version, not a literal that nobody ever bumps'
        );
        self::assertMatchesRegularExpression(
            "/const CACHE = 'fc-shell-' \+ \(VERSION === '' \? 'unversioned' : VERSION\) \+ ':' \+ SCOPE_KEY;/",
            $this->sw
        );
    }

    /**
     * A worker loaded from a legacy registration (bare /sw.js, no query) must
     * NOT invent a token: caching under a key no page requests is worse than
     * not caching at all.
     */
    public function testAnAbsentTokenYieldsTheUnversionedShell(): void
    {
        self::assertStringContainsString(
            "const RAW = new URL(self.location.href).searchParams.get('v') || '';",
            $this->sw
        );
        self::assertStringContainsString(
            "const SHELL = (VERSION === '' ? [OFFLINE_URL] : VERSIONED_PATHS.map(versioned))",
            $this->sw
        );
    }

    public function testEveryVersionedPathExistsOnDiskAndIncludesTheOfflinePage(): void
    {
        $versioned = $this->jsArray('VERSIONED_PATHS');

        self::assertContains('offline.html', $versioned, 'offline.html ships in the release and can change');
        self::assertNotEmpty($versioned);

        foreach ($versioned as $path) {
            // cache.addAll() is atomic: one missing entry fails SW install entirely.
            self::assertFileExists(FC_ROOT . '/' . $path);
            self::assertStringNotContainsString('?', $path, 'the token is applied by versioned(), not written inline');
        }
    }

    public function testStaticShellHoldsOnlyFontsAndIconsAndTheyAllExist(): void
    {
        $static = $this->jsArray('STATIC_SHELL');

        self::assertNotEmpty($static);

        foreach ($static as $path) {
            self::assertFileExists(FC_ROOT . '/' . $path);
            self::assertMatchesRegularExpression(
                '#^public-assets/(fonts|icons)/#',
                $path,
                'only assets whose requester cannot carry the token belong here'
            );
        }
    }

    public function testTheOfflineFallbackUsesThePrecachedConstant(): void
    {
        self::assertStringContainsString("const OFFLINE_URL = versioned('offline.html');", $this->sw);
        self::assertStringContainsString('caches.match(OFFLINE_URL)', $this->sw);
        self::assertStringNotContainsString(
            "caches.match('offline.html')",
            $this->sw,
            'the fallback must look up the URL that was actually precached'
        );
    }

    /**
     * The reverse direction: nothing may sit in the versioned shell unless a view
     * really emits it with a token. A stray entry there is precached under a URL
     * nobody requests — dead weight that also hides a rename, since the forward
     * check would still pass.
     */
    public function testTheVersionedShellHoldsNothingTheViewsDoNotEmit(): void
    {
        [$emittedVersioned] = $this->assetsEmittedByViews();

        foreach ($this->jsArray('VERSIONED_PATHS') as $path) {
            // offline.html is requested by the worker itself, never by a view.
            if ($path === 'offline.html') {
                continue;
            }

            self::assertContains(
                $path,
                $emittedVersioned,
                "{$path} is precached WITH a token but no view emits it with asset() — nothing would ever request it"
            );
        }
    }

    /**
     * STATIC_SHELL's reverse direction. These are precached precisely because
     * something we do NOT generate requests them, so "a view emits it" is the
     * wrong test — the requester is fonts.css or the webmanifest. Each entry
     * must still have one, or it is dead weight inside an atomic cache.addAll().
     */
    public function testEveryStaticShellEntryHasARealRequester(): void
    {
        $fontsCss = (string) file_get_contents(FC_ROOT . '/public-assets/css/fonts.css');
        $manifest = (string) file_get_contents(FC_ROOT . '/app/routes.php');
        [, $emittedPlain] = $this->assetsEmittedByViews();

        foreach ($this->jsArray('STATIC_SHELL') as $path) {
            $basename = basename($path);

            // A mention in a comment is not a requester: fonts must appear in a
            // real src: url(...) declaration, icons in a real manifest entry.
            $inFontFace = preg_match('#url\([\'"][^\'"]*' . preg_quote($basename, '#') . '[\'"]\)#', $fontsCss) === 1;
            $inManifest = preg_match('#[\'"]' . preg_quote($path, '#') . '[\'"]\s*,#', $manifest) === 1;

            $requested = $inFontFace || $inManifest || in_array($path, $emittedPlain, true);

            self::assertTrue(
                $requested,
                "{$path} is precached but nothing requests it — not fonts.css, not the webmanifest, not a view"
            );
        }
    }

    /** The escape hatch must not rot: every exemption is real and still emitted. */
    public function testTheNotPrecachedExemptionsAreAllRealAndStillUsed(): void
    {
        [$emittedVersioned] = $this->assetsEmittedByViews();

        foreach (self::NOT_PRECACHED as $path) {
            self::assertFileExists(FC_ROOT . '/' . $path);
            self::assertContains(
                $path,
                $emittedVersioned,
                "{$path} is exempted from the shell but no view emits it — drop it from NOT_PRECACHED"
            );
        }
    }

    /**
     * Both directions of the HTML <-> shell agreement. This is the assertion
     * that actually prevents the drift; everything above only checks shape.
     */
    public function testEveryAssetTheViewsEmitIsPairedCorrectlyWithTheShell(): void
    {
        $versioned = $this->jsArray('VERSIONED_PATHS');
        $static = $this->jsArray('STATIC_SHELL');

        [$emittedVersioned, $emittedPlain] = $this->assetsEmittedByViews();

        self::assertNotEmpty($emittedVersioned, 'no versioned asset tags found — the scan is broken');

        foreach ($emittedVersioned as $path) {
            if (in_array($path, self::NOT_PRECACHED, true)) {
                self::assertNotContains(
                    $path,
                    $versioned,
                    "{$path} is listed as deliberately not precached — remove it from that list, not from the shell"
                );

                continue;
            }

            self::assertContains(
                $path,
                $versioned,
                "views emit {$path} with asset() (so WITH a token) — it must be precached with the same token"
            );
        }

        foreach ($emittedPlain as $path) {
            self::assertNotContains(
                $path,
                $versioned,
                "views emit {$path} with url() (so WITHOUT a token) — a versioned precache entry could never answer it"
            );
            self::assertContains($path, $static, "{$path} is emitted unversioned, so it belongs in STATIC_SHELL");
        }
    }

    /**
     * The pairing is only as strong as the scan that feeds it, so the scan
     * refuses to run against anything it cannot read statically: a concatenated
     * path, a bare Assets::url() call, or a hand-written /public-assets/ literal
     * would each be invisible and would silently weaken every assertion above.
     */
    private function assertScannable(string $source, string $path): void
    {
        $calls = preg_match_all('#\basset\(#', $source);
        $literal = preg_match_all('#\basset\(\s*[\'"][^\'"]+[\'"]\s*\)#', $source);
        self::assertSame(
            $calls,
            $literal,
            $path . ' builds an asset() path dynamically — static analysis cannot pair it with the SW shell'
        );

        // `Assets :: url(` is legal PHP, so match the separator loosely.
        self::assertDoesNotMatchRegularExpression(
            '#Assets\s*::\s*url\s*\(#',
            $source,
            $path . ' calls Assets::url() directly — views must go through asset() so the scan sees it'
        );

        // Every /public-assets/ mention must sit inside an asset() or url() call.
        // Deliberately lexical, so a path mentioned in a COMMENT also fails: the
        // rule is "no bare /public-assets/ text in a view", which is easy to obey
        // and leaves no gap for a real tag to hide in.
        $mentions = preg_match_all('#/public-assets/#', $source);
        $wrapped = preg_match_all('#(?<![\w>-])(?:asset|url)\(\s*[\'"]/public-assets/#', $source);
        self::assertSame(
            $mentions,
            $wrapped,
            $path . ' mentions /public-assets/ outside an asset()/url() call (including in comments) — '
                . 'a hardcoded path would never be versioned or paired with the SW shell'
        );
    }

    /**
     * @return array{0: list<string>, 1: list<string>} versioned, then plain —
     *         both as repo-relative paths without a leading slash.
     */
    private function assetsEmittedByViews(): array
    {
        $versioned = [];
        $plain = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(FC_ROOT . '/views', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());

            // Both quote styles, so a double-quoted call cannot slip past the pairing.
            preg_match_all('#asset\(\s*[\'"](/public-assets/[^\'"]+)[\'"]\s*\)#', $source, $a);
            foreach ($a[1] as $path) {
                $versioned[ltrim($path, '/')] = true;
            }

            preg_match_all('#\burl\(\s*[\'"](/public-assets/[^\'"]+)[\'"]\s*\)#', $source, $u);
            foreach ($u[1] as $path) {
                $plain[ltrim($path, '/')] = true;
            }

            $this->assertScannable($source, $file->getPathname());
        }

        return [array_keys($versioned), array_keys($plain)];
    }
}
