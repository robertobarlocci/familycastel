<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * The guard that decides whether a GET really represents "the child looked at
 * this page", and may therefore consume their celebration or clear their
 * Journal badge.
 *
 * It is a guard against ACCIDENTS — a prefetch, a prerender, an <img> pointed
 * at the page — and never an authorisation control; authorisation is
 * $requireChild + Auth::childId(). Every header it reads is client-controlled,
 * so it can only ever cause the app to do LESS.
 */
final class RequestNavigationTest extends TestCase
{
    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $this->saved[(string) $key] = (string) $_SERVER[$key];
                unset($_SERVER[$key]);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }
        foreach ($this->saved as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    /** @param array<string, string> $headers */
    private function with(array $headers): bool
    {
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }
        foreach ($headers as $key => $value) {
            $_SERVER[$key] = $value;
        }

        return Request::isUserNavigation();
    }

    private const REAL_NAVIGATION = [
        'HTTP_SEC_FETCH_DEST' => 'document',
        'HTTP_SEC_FETCH_MODE' => 'navigate',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
    ];

    // ------------------------------------------------ speculative loads

    public function testEverySpeculativeHeaderAndTokenIsRejected(): void
    {
        $cases = [
            ['HTTP_SEC_PURPOSE' => 'prefetch'],
            ['HTTP_SEC_PURPOSE' => 'prefetch;prerender'],
            ['HTTP_SEC_PURPOSE' => 'prerender;anonymous-client-ip'],
            ['HTTP_PURPOSE' => 'prefetch'],
            ['HTTP_X_PURPOSE' => 'preview'],
            ['HTTP_X_PURPOSE' => 'prefetch'],
            ['HTTP_X_MOZ' => 'prefetch'],
            ['HTTP_SEC_PURPOSE' => 'PREFETCH'],   // case must not matter
        ];

        foreach ($cases as $headers) {
            self::assertFalse(
                $this->with($headers + self::REAL_NAVIGATION),
                'speculative load must not consume state: ' . json_encode($headers)
            );
        }
    }

    // ------------------------------------------------------- subresources

    public function testSubresourceRequestsAreRejected(): void
    {
        self::assertFalse(
            $this->with(['HTTP_SEC_FETCH_DEST' => 'image', 'HTTP_SEC_FETCH_MODE' => 'no-cors']),
            'a cross-site <img src> pointed at the Journal is not a page view'
        );
        self::assertFalse($this->with(['HTTP_SEC_FETCH_DEST' => 'script', 'HTTP_SEC_FETCH_MODE' => 'no-cors']));
        self::assertFalse($this->with(['HTTP_SEC_FETCH_MODE' => 'cors']), 'fetch()');
        self::assertFalse($this->with(['HTTP_SEC_FETCH_MODE' => 'no-cors']));
        self::assertFalse($this->with(['HTTP_SEC_FETCH_MODE' => 'websocket']));
    }

    public function testAnEmbeddedDocumentIsRejected(): void
    {
        foreach (['iframe', 'frame', 'embed', 'object', 'fencedframe'] as $dest) {
            self::assertFalse(
                $this->with(['HTTP_SEC_FETCH_DEST' => $dest, 'HTTP_SEC_FETCH_MODE' => 'navigate']),
                "a navigation into a {$dest} is not somebody reading the page"
            );
        }
    }

    /**
     * The regression this pins, found by MEASURING the real browser rather than
     * reasoning about the spec: Chromium answers an ordinary top-level
     * navigation with `Sec-Fetch-Mode: navigate` and `Sec-Fetch-Dest: empty`.
     * An earlier version of this guard required dest === 'document', which
     * silently disabled the entire feature — every celebration stayed queued
     * and the Journal badge never cleared, on a code path where nothing looked
     * wrong. Sec-Fetch-Mode is the reliable signal; Sec-Fetch-Dest only rules
     * out embedding.
     */
    public function testANavigationWithAnEmptyDestinationStillCounts(): void
    {
        self::assertTrue(
            $this->with([
                'HTTP_SEC_FETCH_DEST' => 'empty',
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ]),
            'Chromium really does send dest=empty on a navigation — requiring "document" breaks the feature'
        );
    }

    // ------------------------------------------------ cross-site navigation

    public function testAnAutomaticCrossSiteNavigationIsRejected(): void
    {
        self::assertFalse(
            $this->with([
                'HTTP_SEC_FETCH_DEST' => 'document',
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
            ]),
            'no Sec-Fetch-User means no person caused it — a redirect or a script '
            . 'setting location on load, riding a SameSite=Lax cookie'
        );
    }

    /**
     * ...but a child who genuinely TAPS a link to their own Journal from
     * somewhere else has looked at it, and should not be punished for arriving
     * by the side door. `Sec-Fetch-User: ?1` is exactly that distinction.
     */
    public function testAUserActivatedCrossSiteNavigationIsAccepted(): void
    {
        self::assertTrue(
            $this->with([
                'HTTP_SEC_FETCH_DEST' => 'document',
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
                'HTTP_SEC_FETCH_USER' => '?1',
            ])
        );
    }

    public function testSecFetchUserDoesNotRescueASubresourceOrAPrefetch(): void
    {
        self::assertFalse(
            $this->with(['HTTP_SEC_FETCH_MODE' => 'no-cors', 'HTTP_SEC_FETCH_USER' => '?1']),
            'user activation must not override the navigation test'
        );
        self::assertFalse(
            $this->with([
                'HTTP_SEC_PURPOSE' => 'prefetch',
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_USER' => '?1',
            ]),
            'nor the speculative-load test'
        );
    }

    // ------------------------------------------------------ the real thing

    public function testARealNavigationPasses(): void
    {
        foreach (['same-origin', 'same-site', 'none'] as $site) {
            self::assertTrue(
                $this->with([
                    'HTTP_SEC_FETCH_DEST' => 'document',
                    'HTTP_SEC_FETCH_MODE' => 'navigate',
                    'HTTP_SEC_FETCH_SITE' => $site,
                ]),
                "an in-app tap / typed URL / bookmark must pass (site={$site})"
            );
        }
    }

    /**
     * Documented residual risk: a browser that sends no Fetch metadata at all
     * (pre-2023 Safari, old webviews) gets the permissive path. Failing closed
     * would mean the feature silently never works there, which is worse than
     * one badge clearing a visit early — the Journal still lists everything
     * (INV-001). Asserted so the choice is deliberate rather than incidental.
     */
    public function testAHeaderlessLegacyBrowserFailsOpen(): void
    {
        self::assertTrue($this->with([]), 'no Fetch metadata → behave as the app already does on /kid');
    }

    public function testTheGuardNeverThrowsOnOddInput(): void
    {
        // Every value here is client-controlled, so it must be impossible for a
        // forged header to do anything worse than skip the clear.
        self::assertIsBool($this->with(['HTTP_SEC_FETCH_MODE' => str_repeat('x', 4096)]));
        self::assertIsBool($this->with(['HTTP_SEC_PURPOSE' => "\x00\xff"]));
        self::assertIsBool($this->with(['HTTP_SEC_FETCH_SITE' => 'CROSS-SITE']));
        self::assertFalse(
            $this->with(['HTTP_SEC_FETCH_SITE' => 'CROSS-SITE', 'HTTP_SEC_FETCH_MODE' => 'navigate']),
            'the cross-site check is case-insensitive'
        );
    }
}
