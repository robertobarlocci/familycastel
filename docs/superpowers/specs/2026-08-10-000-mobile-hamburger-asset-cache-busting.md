# Mobile parent portal: make the shipped hamburger menu actually reach phones

> Spec 2026-08-10 · issue `000` (no GitHub issue — direct operator report)
> Branch `fix/000-mobile-hamburger` · base `e256fc4`

## Goal

The operator reports: *"when opening the app on smartphone, it looks messy. Please do a
hamburger menu instead of the messy navbar. Everything is inside the hamburger menu in the
parent portal. Nothing else in the navbar."*

The requested end state — on a phone the parent navbar shows only the brand and a hamburger
button, with every navigation entry **and** Logout inside the panel — **is already implemented
and shipped** (`views/layouts/parent.php:22-57`, `public-assets/js/navigation.js`,
`public-assets/css/app.css:257-283`, released in v0.1.1). Verified working in
`.claude/cache/reproduction.md` (`repro-mobile-02-freshcss.png`, `repro-mobile-03-menuopen.png`).

The reason the operator does not see it is a **static-asset delivery defect**: their browser
still holds the pre-v0.1.1 stylesheet. Reproduced: the page's own
`fetch('/public-assets/css/app.css')` returns a **9 947-byte** body while the file on disk is
**15 610 bytes**; the CSSOM contains 100 rules ending at `.topbar-nav { flex-wrap: wrap; }`
(`app.css:255`) and **neither** `@media (max-width: 860px)` nor `@media (max-width: 380px)`.
The desktop nav rules therefore apply at 390 px and produce exactly the reported mess.

So the goal in our terms: **a released CSS/JS change must reach a returning user's browser.**
Two independent mechanisms currently prevent that:

1. **No cache-busting token.** All 21 asset tags emit a bare path
   (`e(url('/public-assets/css/app.css'))`). No `.htaccess` in the tree sets `Cache-Control`
   or `Expires`, so Apache serves assets with Last-Modified/ETag only and browsers apply
   *heuristic* freshness — a cached copy can be reused for days without revalidating.
2. **A service-worker shell frozen at `v1`.** `sw.js:11` is
   `const CACHE = 'fc-shell-v1:' + SCOPE_KEY;` — a literal `v1` that has never changed
   (`sw.js` has exactly one commit in its history, `d66b795`), while `sw.js:66-76` serves
   everything matching `/public-assets/` **cache-first**. `pwa.js:12` registers with no
   `scope` option, so the scope is the app root and the kid PWA's worker also controls
   `/parent/*`. Once a family has opened the kid app on that phone, `app.css` is pinned
   forever.

This change fixes delivery. It does **not** redesign the navbar, because the navbar is not
what is broken.

## Surgical scope

Limited to exactly the operator's request — *make the mobile parent portal show the hamburger
menu instead of the messy navbar* — by fixing the one defect that prevents the already-shipped
hamburger from rendering: stale static-asset delivery. Nothing else changes.

### Why the fix is application-wide, stated explicitly (Codex plan round 1, MEDIUM)

The reported symptom is parent-only, but **the defect is in a shared mechanism**, and a
parent-only fix is not a smaller version of this change — it is an incorrect one:

- `public-assets/css/app.css` is the *same file* loaded by `views/layouts/parent.php:8`,
  `views/layouts/kid.php:12` and `views/layouts/auth.php:8`. Versioning it in the parent
  layout only would emit **two different URLs for one file**, doubling every cache entry and
  guaranteeing the kid app and the login page keep serving the stale copy that caused this
  report. The login page is on the parent's own path to the dashboard.
- The service-worker shell must pair with what pages actually request (`caches.match()` is
  query-sensitive). A partially-versioned HTML surface makes that pairing impossible to state,
  let alone test.

So this change is deliberately re-scoped, in the open, as **"fix static-asset delivery for the
CSS/JS the app emits"** — the smallest *correct* unit containing the reported defect. It adds
no feature, changes no layout, and touches no behaviour other than the URL a stylesheet or
script is fetched from. Every asset tag it edits keeps pointing at the same file.

### Explicitly OUT of scope (tempting, deliberately excluded)

- **Rewriting or restyling the navbar / hamburger.** It is correct; changing it would not fix
  the reported symptom and would risk the v0.1.1 mobile work.
- **The desktop (>860 px) navbar.** The report is explicitly about a smartphone; desktop shows
  a horizontal bar with no hamburger and is not "messy". Verified unchanged at 1280×800.
- **Removing the brand link from the navbar row.** "Nothing else in the navbar" is read as
  *no navigation entries*; the brand is the app title, and removing it leaves a bare bar with a
  floating button. All nine nav entries plus Logout are already inside the panel.
- **Adding `Cache-Control` / `Expires` headers via `mod_headers` / `mod_expires`.** Neither
  module is guaranteed on cyon.ch-class hosting (INV-003), and an unguarded directive is a hard
  500 there — the same trap as the bare `RewriteEngine On` in LESSONS 2026-08-10. A URL token
  needs no module and works on every host.
- **Precaching `navigation.js` / `qr-render.js` / `qrcode.js`** (absent from `sw.js`'s SHELL).
  Real gap, but a separate behaviour change with its own failure mode (`cache.addAll` is
  atomic). → file a GitHub issue.
- **Versioning the manifest icon `src` values** (`app/routes.php:111-113`). Icons are
  content-stable and are also referenced unversioned from the webmanifest. → out of scope.
- **Issue #4** (production install stuck in `?r=` mode). Different root cause; this change is
  proven correct in *both* modes instead of depending on either.
- **A general asset pipeline / hashed filenames / build step.** Forbidden by INV-003.

## Invariants

| Id | Verdict |
|---|---|
| **INV-003** — production runs on plain shared hosting | **Preserved, and it drives the design.** The token is computed in PHP at request time from the existing `FC_VERSION` constant — no build step, no Node, no Composer runtime, no Apache module. `sw.js` stays a static file served by Apache and learns the version from its own registration query, because `.htaccess:22` denies `VERSION` over HTTP so a client cannot fetch it. Subdirectory installs and the `?r=` fallback are both covered: `BasePath::prefix()` returns `$base . $path` verbatim for `/public-assets/` in *both* modes (`isDirectFile()` prefix-matches before any `?r=` rewriting), and `strtok($path,'?')` already lets `/sw.js?v=…` stay a direct file. Both are pinned by unit tests. |
| **INV-004** — product is spelled "Family Castel" | Preserved; no user-facing string changes. |
| **INV-001** — history is never deleted | Not touched. No ledger, journal, sidequest or history code is in the diff (matched on generic Area words only). |
| **INV-002** — Coins/XP flow only through the ledger | Not touched. No domain/service code in the diff. |
| **INV-005** — every `ops.lock` mutation goes through the shared flock mutex | Not touched. No `update.php` / `OpsController` / lock code in the diff. |

## Prior issues considered

`.claude/cache/prior-issues-considered.md` — 5 open issues and 4 closed PRs read in full.
Decisive findings: PR #2 shipped the hamburger (so this is a delivery bug, not a missing
feature); PR #8/#9 fixed a *different* "site is unstyled" cause (file modes → 404), and the
response body here is a 200 with an old body, so this is not a recurrence; commit `d66b795`
is the only commit that ever touched `sw.js`, confirming `v1` has never been bumped.

## Reproduction

`.claude/cache/reproduction.md` — status **REPRODUCED**, with screenshots, the CSSOM read and
the byte-count comparison.

## What ships

### 1. `app/Core/Assets.php` (new) — the version token and the asset URL builder

A tiny static holder, mirroring `BasePath`'s style, so the token is injectable in tests
(`FC_VERSION` is **not** defined by `tests/bootstrap.php`, so reading the constant directly
inside the helper would be untestable).

```php
final class Assets
{
    private const FALLBACK = '0';

    private static string $version = self::FALLBACK;

    /** Validate at the boundary: VERSION is a file on disk and may be anything. */
    public static function setVersion(string $version): void
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $version) ?? '';
        self::$version = $clean === '' ? self::FALLBACK : $clean;
    }

    public static function version(): string
    {
        return self::$version;
    }

    /**
     * Cache-busting URL for a CSS/JS asset Apache serves directly. The token
     * changes with every release, so a browser (or a service worker, which
     * matches on the FULL url including the query) can never reuse the previous
     * release's bytes.
     */
    public static function url(string $path): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        return BasePath::prefix($path . $separator . 'v=' . self::$version);
    }
}
```

`index.php` initialises it next to the other core statics (after `FC_VERSION` is defined at
`index.php:12`, alongside the `BasePath::set(...)` block at `index.php:31`):

```php
Assets::setVersion(FC_VERSION);
```

`app/Core/helpers.php` gains one helper next to `url()`, guarded by `function_exists` like
its neighbours:

```php
/** Versioned URL for a CSS/JS asset — busts browser and service-worker caches on release. */
function asset(string $path): string
{
    return \FamilyCastel\Core\Assets::url($path);
}
```

`url()` is **unchanged**; every existing `url()` assertion in `tests/Unit/UrlHelperTest.php`
stays green.

### 2. Call sites — CSS and JS only

Exactly the tags whose bytes change between releases switch `url(` → `asset(`:

| File | Lines | Assets |
|---|---|---|
| `views/layouts/parent.php` | 7, 8, 66 | `css/fonts.css`, `css/app.css`, `js/navigation.js` |
| `views/layouts/kid.php` | 11, 12, 13, 45, 46, 47 | `css/fonts.css`, `css/app.css`, `css/kid.css`, `js/progress.js`, `js/sounds.js`, `js/pwa.js` |
| `views/layouts/kid.php` | 15 | `data-sw` → `eattr(asset('/sw.js'))` |
| `views/layouts/auth.php` | 7, 8 | `css/install.css`, `css/app.css` |
| `views/layouts/install.php` | 7 | `css/install.css` |
| `views/kid/home.php` | 86, 87 | `vendor/confetti.js`, `js/celebrate.js` |
| `views/kid/login.php` | 36 | `js/kid-login.js` |
| `views/parent/milestones.php` | 73, 74 | `js/confirm.js`, `js/progress.js` |
| `views/parent/child.php` | 80 | `js/confirm.js` |
| `views/parent/children/index.php` | 41 | `js/confirm.js` |
| `views/parent/children/qr.php` | 10, 11, 23 | `vendor/qrcode.js`, `js/qr-render.js`, `js/confirm.js` |
| `views/parent/ops/updates.php` | 58 | `js/confirm.js` |

**Deliberately NOT versioned** (`url()` stays):
`views/layouts/kid.php:9,10` (`icons/icon.svg`, `icons/icon-192.png`) and
`app/routes.php:92,111-113`. Reason, and this is a correctness constraint rather than taste:
the fonts are requested by the browser from **inside** `fonts.css`
(`public-assets/css/fonts.css:9,17,25,33,41` → `url('../fonts/…woff2')`) and the icons from
**inside** the webmanifest — neither request can carry a PHP-generated token. Because
`caches.match()` is query-sensitive, versioning them in HTML would make every service-worker
precache entry for them unreachable and silently break offline fonts/icons. Font and icon
files are content-stable (a new font ships under a new filename), so they need no token.

The rule that decides the split, and the one test 14 enforces: **an asset is versioned exactly
when every requester of it can carry the token.** CSS and JS are requested only by
PHP-rendered tags (or by `sw.js`, which computes the same token), so they are versioned;
fonts and icons have requesters we do not generate, so they are not. `offline.html` is
versioned because its *only* requester is `sw.js` itself (see §3).

Escaping is unchanged: `e()` / `eattr()` render `?v=0.1.3` verbatim (no `&` is produced, and
`e()` would escape one correctly anyway).

### 3. `sw.js` — a genuinely versioned shell

`sw.js:69`'s comment already claims *"cache-first for the versioned shell"*. Make it true.
The worker reads the token from its own registration URL (`views/layouts/kid.php:15` now
emits `sw.js?v=<version>`), which is the only channel available: `sw.js` is a static file
with no PHP route, and `.htaccess:22` denies `VERSION` over HTTP.

```js
const SCOPE_KEY = new URL(self.registration.scope).pathname;

// The token comes from THIS worker's own script URL, which the layout emits as
// sw.js?v=<version>. A legacy registration (or the browser's periodic update
// check on an old registration) re-fetches the bare /sw.js with NO query — that
// worker must NOT invent a token, because a made-up one would precache URLs no
// page ever requests. It runs unversioned instead: static shell only, and every
// CSS/JS request falls through to the network, which is correct-but-not-offline
// until a page re-registers the versioned URL.
const RAW = new URL(self.location.href).searchParams.get('v') || '';
const VERSION = RAW.replace(/[^A-Za-z0-9._-]/g, '');
const CACHE = 'fc-shell-' + (VERSION === '' ? 'unversioned' : VERSION) + ':' + SCOPE_KEY;

// Assets whose bytes change per release. PHP requests these WITH the token, and
// caches.match() matches on the full URL (query included), so the precached URL
// must carry the identical token or every lookup misses. offline.html belongs
// here too: it ships in the release and can change, and it is requested only by
// THIS worker, so both sides use the same constant and cannot drift.
const VERSIONED_PATHS = [
    'offline.html',
    'public-assets/css/fonts.css',
    'public-assets/css/app.css',
    'public-assets/css/kid.css',
    'public-assets/css/install.css',
    'public-assets/js/progress.js',
    'public-assets/js/sounds.js',
    'public-assets/js/celebrate.js',
    'public-assets/js/confirm.js',
    'public-assets/js/kid-login.js',
    'public-assets/js/pwa.js',
    'public-assets/vendor/confetti.js',
];

function versioned(path) {
    return VERSION === '' ? path : path + '?v=' + VERSION;
}

const OFFLINE_URL = versioned('offline.html');

// Requested WITHOUT a token by something that cannot carry one: the fonts come
// from inside fonts.css (url('../fonts/…')) and the icons from the webmanifest.
const STATIC_SHELL = [
    'public-assets/icons/icon.svg',
    'public-assets/icons/icon-192.png',
    'public-assets/icons/icon-512.png',
    'public-assets/fonts/fredoka-600.woff2',
    'public-assets/fonts/fredoka-700.woff2',
    'public-assets/fonts/nunito-400.woff2',
    'public-assets/fonts/nunito-700.woff2',
    'public-assets/fonts/nunito-800.woff2',
];

// Unversioned worker: precache only what it can serve correctly. Versioned
// CSS/JS is deliberately omitted rather than cached under a wrong key.
const SHELL = (VERSION === '' ? [OFFLINE_URL] : VERSIONED_PATHS.map(versioned))
    .concat(STATIC_SHELL);
```

The single line in `fetch` that names the fallback changes with it —
`caches.match('offline.html')` (`sw.js:82`) becomes `caches.match(OFFLINE_URL)` — so the
precached URL and the lookup are the same expression and cannot drift.

`install`, `activate` and the rest of `fetch` are otherwise untouched. The existing cleanup
predicate (`sw.js:49`) is already `key.indexOf('fc-shell-') === 0 && key.endsWith(':' +
SCOPE_KEY) && key !== CACHE`, so the old `fc-shell-v1:<scope>` cache — and any
`fc-shell-unversioned:` cache — is deleted on activation of the next versioned worker without
further change. `skipWaiting()` + `clients.claim()` are already in place, so the new worker
takes over on first load.

**The legacy-registration path, spelled out** (Codex plan round 1, MEDIUM). An install that
predates this change has a registration whose script URL is `/sw.js`, with no query. Two
things can happen, and both are safe:

| Trigger | Result |
|---|---|
| The browser's periodic update check re-fetches `/sw.js` | New code, `VERSION === ''` → cache `fc-shell-unversioned:<scope>`, static shell + `offline.html` only. Every versioned CSS/JS request misses and goes to the network — the user gets **current** assets. Offline shell coverage for CSS/JS is suspended, not wrong. |
| Any kid page loads (`pwa.js` runs on every kid page) | `register('/sw.js?v=0.1.3')` changes the registration's script URL; the browser installs the versioned worker, which precaches the exact URLs the pages request and `activate` deletes the old caches. |

The plan deliberately **does not** adopt Codex's alternative of registering the worker from
parent pages as well. Technical reason, not convenience: that would turn the parent portal
into a service-worker client for the first time, putting authenticated parent navigations
under `sw.js`'s fetch handler *by our own action* — a behaviour change well outside a
cache-busting fix, and one that would need its own offline/auth review. The migration above
already delivers correct (fresh) assets to every legacy client immediately, without it. The
transition is covered by the browser test in Teilaufgabe 3, which seeds a legacy
`fc-shell-v1` cache and asserts the served CSS is the new one.

**Why this is a required counterpart and not scope creep** (LESSONS 2026-08-10: *"every
deliberate exclusion needs a companion answer"*). Once §2 lands, pages request
`app.css?v=0.1.3`; a stale `fc-shell-v1` cache holds `app.css` with no query, so
`caches.match()` **misses** and the request goes to the network. That is what fixes the
reported symptom even for an already-frozen worker — but it also means the shell would
precache a set of URLs the app never requests again, i.e. the offline capability of the PWA
would silently become dead weight. Versioning the shell restores it.

### 4. `pwa.js` — no change

It registers `document.body.dataset.sw` verbatim (`public-assets/js/pwa.js:10-12`), so it
picks up the token automatically. A changed script URL makes the browser fetch and install a
new worker, which is exactly the desired trigger.

## Teilaufgaben

Executed one after another. **Each Teilaufgabe is independently shippable: it contains its
own RED→GREEN cycle and ends with the whole suite green and the application in a consistent
state.** Tests are still written before the implementation *inside* each step — the RED phase
is a step's first action, never a step of its own, so no numbered step ever ends red or leaves
HTML and the service worker disagreeing (Codex plan round 1, MEDIUM).

1. **The token and the asset URL builder.** Write `tests/Unit/AssetUrlTest.php` and the new
   cases for `tests/Unit/UrlHelperTest.php` (tests 1-10) → observe RED → add
   `app/Core/Assets.php`, the `asset()` helper in `app/Core/helpers.php`, and the
   `Assets::setVersion(FC_VERSION)` wiring in `index.php` → GREEN.
   *Shippable:* new code is not yet called by any view, so the rendered output is
   byte-identical to today. Pure addition, zero behaviour change.

2. **Versioned delivery: call sites and the service worker, together.** Write
   `tests/Unit/ServiceWorkerShellTest.php` (tests 11-14) → observe RED → switch the 21 CSS/JS
   tags in the 11 view files to `asset()`, and land the `sw.js` rewrite (version-keyed cache
   name, versioned/static shell split, `OFFLINE_URL`, unversioned-worker path) → GREEN.
   *Shippable, and deliberately one step:* HTML and the shell must change together, because a
   step that shipped versioned HTML against the old shell — or the new shell against
   unversioned HTML — would leave every precache lookup missing. Test 14 is what proves the
   two sides agree, so it can only pass once both have landed.

3. **Behavioural service-worker coverage.** Write `e2e/specs/assets.spec.ts` (tests 15-22)
   → observe RED where it should be → register the spec in `.github/workflows/ci.yml:137`
   (the e2e job names spec files explicitly, so a new file otherwise never runs in CI) →
   GREEN. This is the step that exercises the *lifecycle* rather than the file's structure:
   it seeds a legacy `fc-shell-v1:<scope>` cache holding a doctored `app.css`, reloads, and
   asserts the page renders from the **current** stylesheet — i.e. it observes the failure
   class the structural tests cannot (LESSONS 2026-08-10: *"a gate that structurally cannot
   observe a failure class is not coverage"*).
   *Shippable:* test-only.

4. **Release gate.** Extend the `release.yml` clean-install gate (`:224-233`) to assert the
   scraped dashboard stylesheet href **contains `?v=`**, so a regression that drops the token
   fails the release instead of shipping silently. The existing scraper regex already
   tolerates a query. *Shippable:* CI-only.

5. **Version + changelog.** `VERSION` → `0.1.3`; root `CHANGELOG.md` gains a `## [0.1.3]`
   entry in the established end-user voice, saying plainly that installing it restores the
   phone layout. *Shippable:* this is the release commit.

6. **Docs + vault.** `docs/DEVELOPMENT.md` note on the asset-token rule and the dev
   hard-reload caveat; vault `ARCHITECTURE.md`, `FILE_MAP.md`, `CHANGELOG.md`, `LESSONS.md`,
   and a new **INV-006** recording the standing constraint that PHP-emitted CSS/JS URLs and
   the service-worker shell must carry the same token. *Shippable:* docs-only.

## Scope

**In:** `app/Core/Assets.php`, `app/Core/helpers.php`, `index.php`, `sw.js`, the 11 view
files, tests, the CI/release gates, `VERSION`, `CHANGELOG.md`, docs/vault.

**Out:** everything in the "Explicitly OUT of scope" list above.

## Edge cases

1. **Subdirectory install** (`/familycastle/`) — `prefix()` returns `$base . $path` with the
   query verbatim. Test-pinned.
2. **`?r=` fallback mode** (the production install's actual mode, issue #4) —
   `isDirectFile()` prefix-matches `/public-assets/` on the string *including* the query, so
   the `?r=` branch is never reached; `/sw.js?v=…` matches via `strtok($path,'?')`.
   Test-pinned for both.
3. **Corrupt/empty `VERSION`.** `FC_VERSION` already falls back to `'0.0.0'`
   (`index.php:12`); `Assets::setVersion()` additionally strips anything outside
   `[A-Za-z0-9._-]` and falls back to `'0'`, so no user-controlled or malformed value can
   inject a second `?`, an `&`, a space or a quote into an attribute.
4. **Rollback to an older release.** The token goes *backwards* (0.1.3 → 0.1.2). That is
   still a *change*, so caches still miss, and the SW cache name changes too; the activate
   cleanup deletes the newer cache. No stale-forward state.
5. **A client on the old `fc-shell-v1` worker that never opens a kid page.** Never
   re-registers, but every versioned request misses its cache and hits the network — the
   symptom is fixed without requiring the worker to update. This is the case that makes the
   operator's phone recover.
6. **`cache.addAll` atomicity.** One 404 fails the whole install. Versioned URLs are plain
   query strings on real files, and Teilaufgabe 3 asserts every shell path exists on disk.
7. **Font/icon requests from inside CSS and the manifest** — deliberately unversioned on both
   sides, so precache lookups still hit (see §2).
7a. **A legacy `/sw.js` registration with no `?v=`.** The worker runs unversioned: cache
    `fc-shell-unversioned:<scope>`, static shell + `offline.html` only, all CSS/JS to the
    network. It never precaches under a token no page uses. Covered by test 21.
7b. **`offline.html` changing between releases.** It is in the swap set, so it is versioned
    together with the CSS/JS, and the fetch handler's fallback uses the same `OFFLINE_URL`
    constant that was precached (test 15) — so a released change to the offline page is not
    masked by an old precache, and the fallback can never point at a URL that was not cached.
8. **`SystemCheck`'s loopback probe** fetches `/public-assets/<nonce>.txt` with no query and
   bypasses `url()` entirely (`app/Install/SystemCheck.php:133,190`) — unaffected.
9. **The release clean-install gate** scrapes `href="[^"]*\.css[^"]*"` and un-escapes
   `&amp;` (`release.yml:230`) — already query-tolerant.
10. **`FC_VERSION` undefined under PHPUnit.** Avoided by design: `Assets` takes the version
    via `setVersion()`; only `index.php` reads the constant.
11. **Dev workflow.** Within one version, editing `app.css` does not change the token; the
    dev stack is hard-reloaded (documented in `docs/DEVELOPMENT.md`). Deliberate: the token
    must be a *single global value* both PHP and the static `sw.js` can compute, which rules
    out per-file mtimes.

## Threat model

**Auth surface:** none — no route, controller, session or permission is touched.
**Untrusted input:** one new input path, the `VERSION` file's content flowing into an HTML
attribute and into a service-worker cache key. It is repo-controlled, not user-supplied, but
is validated at the boundary anyway (`setVersion()` allowlist `[A-Za-z0-9._-]`, non-empty
fallback), so attribute-breaking characters cannot reach `e()`/`eattr()` output even if the
file were corrupted. In `sw.js` the same allowlist is re-applied to the `v` query parameter,
which **is** attacker-influenceable (anyone can load `sw.js?v=…`) — it is used only to build
a cache-key string and to suffix precache URLs, never `eval`'d, and the sanitiser prevents a
crafted value from escaping into a different path or scope. A hostile `v` can at worst make
one visitor populate a differently-named cache in their own browser.
**Data sensitivity:** none; only public static assets are involved. Authenticated HTML and
JSON remain uncached (`sw.js:78-86` unchanged).
**Blast radius if wrong:** a missing/incorrect token means assets keep being served stale —
i.e. today's behaviour, no worse. A malformed URL would 404 a stylesheet; caught by the unit
tests, the E2E spec and the release clean-install gate before publish.

## Lessons followed (vault matches on the files this change touches)

- **Follows 2026-08-10 "A fix that lives in the file applied LAST does not fix the next
  update".** The question that lesson demands — *which build of this code runs during the
  update that ships the fix?* — is asked and answered here. Every file this change touches
  (`index.php`, `app/`, `views/`, `public-assets/`, `sw.js`) is inside `fc_swap_entries()`
  (`update.php:323`), and none of them is the *executor*: `update.php` is untouched by this
  diff. So the old executor performs the swap and the very next request is served by the new
  `index.php`, which calls `Assets::setVersion()` and emits versioned URLs — the fix is live
  immediately, not one update later. There is no self-updating component here that could
  carry its own fix. **Consequence made explicit for the operator:** their phone recovers
  when v0.1.3 is *installed*; no browser-side action, cache clear or app reinstall is needed,
  because the new HTML requests URLs the stale caches simply do not contain (edge case 5).
- **Follows 2026-08-10 "Fallback modes are features: they need detection, generation AND a
  gate that runs them".** All three parts are covered rather than assumed: the app already
  *detects* the host's rewrite capability, this change *generates* correct URLs in `?r=` mode
  (proven by tests 7-8, not by reasoning about `isDirectFile()`), and the release
  clean-install gate already runs a genuinely different leg — subdirectory + **no** rewrite —
  which Teilaufgabe 7 extends to assert the token. That lesson's round-2 addendum ("a URL
  generator with a router-fallback mode must distinguish ROUTES from REAL FILES") is the
  reason `asset()` is deliberately built on the existing `BasePath::prefix()`/`isDirectFile()`
  path instead of concatenating a query itself — real files keep their plain path, and adding
  a query must not change that classification. Its second addendum ("curl-based smoke tests
  lie: a real gate must navigate NATURALLY") is why tests 15-19 drive a real browser through
  a real login and read the page's **own** stylesheet href, rather than curling a known URL.
- **Follows 2026-08-10 "File modes are program state".** No new mode-bearing code path is
  introduced, but the lesson's corollary applies to the one genuinely new file:
  `app/Core/Assets.php` lands inside `app/`, which `fc_chmod_tree()` normalises to 0755/0644
  before the swap, and `FileModeHeal` covers `sw.js` explicitly (`FileModeHeal.php:53`). So
  the new file inherits the existing, already-tested guarantee rather than needing its own.
  Checked, not assumed: `tests/e2e-updater.sh:195-227` walks `app/` asserting every file is
  0644, so a new file under `app/` is covered by an existing gate.
- **Follows 2026-08-10 "Concurrency fixes that 'look atomic' need a second adversarial
  pass".** The delayed-actor question is played out for the worker handover rather than
  hand-waved: an old `fc-shell-v1` worker that is *never* replaced (a parent who never opens
  a kid page) is the adversarial case, and it is handled by construction — versioned requests
  miss its query-insensitive-looking but actually query-sensitive cache and fall through to
  the network (edge case 5). Correctness therefore does not depend on the worker updating at
  all; the worker change only restores offline precaching.
- **Does not apply: the `ops.lock` / flock lessons (rounds 2-6) and INV-005.** They govern
  `update.php` and `OpsController` lock protocols; this diff contains no lock, no `ops.lock`
  access and no `update.php` change.

## Tests

1. `Assets::version()` defaults to `'0'` before any `setVersion()` call.
2. `Assets::setVersion('0.1.3')` → `version() === '0.1.3'`.
3. `setVersion('')` and `setVersion('!!!')` → `'0'` (fallback, never an empty token).
4. `setVersion("0.1.3\n \"x")` → `'0.1.3x'` — no quote, space or newline survives.
5. `asset('/public-assets/css/app.css')` at domain root → `/public-assets/css/app.css?v=0.1.3`.
6. Same in `/family` → `/family/public-assets/css/app.css?v=0.1.3`.
7. Same in `?r=` mode → `/family/public-assets/css/app.css?v=0.1.3` (never routed).
8. `asset('/sw.js')` in `?r=` mode → `/family/sw.js?v=0.1.3` (stays a direct file).
9. `asset('/public-assets/x.css?a=1')` → `…?a=1&v=0.1.3` (joins with `&`, one `?` only).
10. `url()` is unchanged: every existing assertion in `UrlHelperTest` still passes,
    including `url('/public-assets/css/app.css') === '/public-assets/css/app.css'`.
11. `sw.js` contains no literal `fc-shell-v1`; its cache name is built from the parsed token,
    and the unversioned branch resolves to `fc-shell-unversioned:`.
12. Every `VERSIONED_PATHS` entry names a file that exists on disk (`cache.addAll` is atomic,
    so one typo would kill SW installation entirely), and `offline.html` is among them.
13. Every `STATIC_SHELL` entry names an existing file and is limited to fonts and icons —
    the assets that are requested from inside `fonts.css` and the webmanifest.
14. **HTML ↔ shell agreement:** every `/public-assets/` CSS/JS path emitted with `asset()`
    across all view files appears in `VERSIONED_PATHS`, and every icon path emitted with
    `url()` appears in `STATIC_SHELL`. Parses the views and `sw.js` and compares the two
    sets, so the pairing cannot silently drift in either direction.
15. `caches.match(OFFLINE_URL)` is used in the fetch handler — the fallback lookup uses the
    same constant that was precached (no literal `'offline.html'` left in `fetch`).
16. E2E (mobile): the parent dashboard's stylesheet href matches
    `/public-assets/css/app\.css\?v=`.
17. E2E (mobile): that href returns HTTP 200.
18. E2E (mobile): the hamburger toggle is visible and `#parent-menu` is hidden on load.
19. E2E (mobile): clicking the toggle reveals all nine nav links **and** the Logout button.
20. E2E (mobile): with the menu closed, no nav link is visible in the navbar row
    (the "nothing else in the navbar" requirement, asserted rather than assumed).
21. **E2E (mobile) — the legacy-worker lifecycle test.** Open a kid page so `pwa.js`
    registers the worker and wait for it to control the page; then, via the page's Cache
    Storage API, seed a `fc-shell-v1:<scope>` cache whose entry for the *unversioned*
    `public-assets/css/app.css` holds a doctored stylesheet (a sentinel rule that the real
    file does not contain); reload the parent dashboard at 390×844 and assert (a) the
    sentinel is **absent** from the computed styles, (b) `@media (max-width: 860px)` is
    present in the CSSOM — i.e. the hamburger layout applies — and (c) after the versioned
    worker activates, `caches.keys()` no longer contains `fc-shell-v1:<scope>` and does
    contain `fc-shell-<version>:<scope>`. This is the behavioural counterpart to tests
    11-15 and the direct regression guard for the reported bug.
22. E2E (desktop): the horizontal nav is still visible and the toggle is hidden — the
    explicit non-regression guard for the out-of-scope desktop layout.

## Files changed

| File | Change |
|---|---|
| `app/Core/Assets.php` | **new** — version holder + versioned asset URL builder |
| `app/Core/helpers.php` | **new** `asset()` helper beside `url()` |
| `index.php` | `Assets::setVersion(FC_VERSION);` in the bootstrap block |
| `sw.js` | version-keyed cache name; shell split into versioned / static |
| `views/layouts/parent.php` | 3 tags → `asset()` |
| `views/layouts/kid.php` | 6 tags + `data-sw` → `asset()`; icons stay on `url()` |
| `views/layouts/auth.php` | 2 tags → `asset()` |
| `views/layouts/install.php` | 1 tag → `asset()` |
| `views/kid/home.php`, `views/kid/login.php` | 3 tags → `asset()` |
| `views/parent/milestones.php`, `child.php`, `children/index.php`, `children/qr.php`, `ops/updates.php` | 8 tags → `asset()` |
| `tests/Unit/AssetUrlTest.php` | **new** — tests 1-4, 9 |
| `tests/Unit/UrlHelperTest.php` | tests 5-8, 10 appended |
| `tests/Unit/ServiceWorkerShellTest.php` | **new** — tests 11-15 |
| `e2e/specs/assets.spec.ts` | **new** — tests 16-22, incl. the legacy-worker lifecycle test |
| `.github/workflows/ci.yml` | register the new spec in the explicit e2e list (`:137`) |
| `.github/workflows/release.yml` | clean-install gate asserts the token is present (`:230`) |
| `VERSION` | `0.1.2` → `0.1.3` |
| `CHANGELOG.md` | `## [0.1.3]` entry |
| `docs/DEVELOPMENT.md` | asset-token rule + dev hard-reload note |

## Risks

| Risk | Mitigation |
|---|---|
| A versioned URL 404s in `?r=` mode or a subdirectory (would break **all** styling — the exact production failure of PR #8) | Tests 5-8 pin both modes and both base paths; the release clean-install gate already runs a subdirectory + no-rewrite leg and now also fetches the dashboard's own scraped href |
| HTML and SW shell drift apart, so precache lookups silently always miss (offline dies quietly) | Tests 12-14 pin the split; the misleading "versioned shell" comment that allowed this drift is corrected |
| `cache.addAll` fails atomically on a typo, killing SW install | Test 12/13 assert every shell path exists on disk |
| Token leaks into font/icon URLs and breaks their precache | Explicit not-versioned list in §2 + test 13 |
| A client keeps an old worker and never updates | By design: versioned requests miss the old cache and go to network (edge case 5) |
| Dev confusion when CSS edits are not busted within a version | Documented in `docs/DEVELOPMENT.md`; deliberate trade-off (edge case 11) |

## Acceptance checklist

- [ ] All 20 tests written first and observed RED, then GREEN
- [ ] Full PHPUnit suite green on MariaDB (never SQLite); `php -l` clean
- [ ] Playwright green on both projects, incl. the desktop non-regression guard
- [ ] `tests/e2e-updater.sh` still green (`sw.js` is in the swap set)
- [ ] `scripts/build-release.php` output still contains `sw.js` + `public-assets/`
- [ ] Codex plan review at CRITICAL=0 HIGH=0 MEDIUM=0, then a clean impl review
- [ ] Mobile parent portal verified in the browser at 390×844 with a **cold** cache
- [ ] Vault updated: ARCHITECTURE, FILE_MAP, CHANGELOG, LESSONS, INV-006
