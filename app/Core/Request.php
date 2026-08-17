<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Facts about the current request that are not routing.
 *
 * Today: was this GET a page the child DELIBERATELY opened?
 *
 * Two kid pages consume state on a GET — /kid takes the celebration queue and
 * /kid/journal clears the "something new" mark. That is the right place for
 * both: "the child has looked at this" is genuinely observed by the page being
 * rendered, and a POST would mean the Journal nav entry could no longer be a
 * plain link. RFC 9110 §9.2.1 explicitly contemplates a safe method carrying
 * side effects the user did not request, provided they cannot be held
 * accountable for them.
 *
 * This guard is what covers the requests the child never made: a browser
 * prefetch or prerender, a cross-site <img>/fetch() aimed at the page, and a
 * cross-site top-level navigation (which carries a SameSite=Lax cookie and so
 * would otherwise look exactly like a real visit).
 *
 * It is NOT an authorisation control and must never be used as one — every
 * header it reads is client-controlled. It can only ever make the app do LESS.
 * Authorisation stays with $requireChild + Auth::childId(): forging these
 * headers needs the child's session first, and with that session an attacker
 * could simply open the page.
 */
final class Request
{
    /** Speculative-load tokens. 'preview' is Safari's on X-Purpose. */
    private const SPECULATIVE = ['prefetch', 'prerender', 'preview'];

    /** Sec-Purpose is current; the other three are what browsers still send. */
    private const PURPOSE_HEADERS = ['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'];

    /**
     * Destinations that mean "this page is EMBEDDED in another one", so nobody
     * necessarily looked at it.
     *
     * Note what is NOT here: 'empty'. Requiring Sec-Fetch-Dest to be exactly
     * 'document' was the first version of this guard and it was wrong —
     * measured, not guessed: Chromium answers a top-level navigation with
     * `Sec-Fetch-Mode: navigate` and `Sec-Fetch-Dest: empty` in ordinary cases,
     * so the strict form silently disabled the whole feature. `Sec-Fetch-Mode`
     * is the reliable signal for "this is a navigation" and it already excludes
     * the entire subresource family on its own: an <img> sends
     * `mode: no-cors`, a fetch() sends `cors`/`no-cors`, a <script src> sends
     * `no-cors`. This list only adds the embedded-document cases.
     */
    private const EMBEDDED_DESTINATIONS = ['iframe', 'frame', 'embed', 'object', 'fencedframe'];

    /**
     * Did a person deliberately open this page?
     *
     * Fails OPEN when no Fetch metadata is present (pre-2023 Safari, old
     * webviews): failing closed would mean the feature silently never works on
     * those browsers, which is a worse outcome than a badge clearing one visit
     * early — the Journal still lists every entry either way (INV-001).
     *
     * @param array<string, mixed>|null $server defaults to $_SERVER; injectable for tests
     */
    public static function isUserNavigation(?array $server = null): bool
    {
        $server ??= $_SERVER;

        foreach (self::PURPOSE_HEADERS as $header) {
            $value = strtolower((string) ($server[$header] ?? ''));
            foreach (self::SPECULATIVE as $token) {
                if (str_contains($value, $token)) {
                    return false;
                }
            }
        }

        // The primary test: was this a navigation at all? This one header
        // excludes every subresource load — <img>, fetch(), <script src> — in
        // one go, because none of them can carry mode=navigate.
        $mode = strtolower((string) ($server['HTTP_SEC_FETCH_MODE'] ?? ''));
        if ($mode !== '' && $mode !== 'navigate') {
            return false;
        }

        // ...and a navigation into a frame is not somebody reading the page.
        $dest = strtolower((string) ($server['HTTP_SEC_FETCH_DEST'] ?? ''));
        if (in_array($dest, self::EMBEDDED_DESTINATIONS, true)) {
            return false;
        }

        // A top-level navigation started by ANOTHER SITE reaches us
        // authenticated, because the session cookie is SameSite=Lax — so on its
        // own it looks exactly like a real visit. same-origin / same-site / none
        // (typed URL, bookmark, PWA launch) always pass.
        //
        // Cross-site is allowed only when the browser says a PERSON caused it:
        // `Sec-Fetch-User: ?1` is set for user-activated navigations and absent
        // for automatic ones (a redirect, a script setting location on load).
        // That is the distinction that actually matters here — a child tapping a
        // link to their own Journal from somewhere else really has looked at it,
        // while a page silently bouncing them there has not.
        if (strtolower((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site'
            && trim((string) ($server['HTTP_SEC_FETCH_USER'] ?? '')) !== '?1'
        ) {
            return false;
        }

        return true;
    }
}
