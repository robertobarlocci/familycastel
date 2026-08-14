# 2026-08-11 — #15: quick-action titles stay legible on a phone

## Goal

Issue #15 reports that the quick actions (`Schnellaktionen`) on the parent → child screen
render **without their titles** on a smartphone, leaving only the coin/XP delta, so a parent
cannot tell what a button awards.

The symptom does not reproduce on v0.1.4 (`.claude/cache/reproduction.md`,
status `BASELINE-CONFIRMED`): at 390×844 and 320×700 every `.template-btn-title` renders,
is on screen and is unclipped; no rule at any breakpoint hides it; the delivered `app.css`
and the released v0.1.4 ZIP are byte-identical to the dev tree; and every tag v0.1.0…v0.1.4
ships the title rule. The operator was asked (2026-08-11) and decided: **harden the two
properties the screen currently leaves to chance and pin them with tests**, rather than fix
an unobserved rendering by guesswork.

The two unguarded properties, both found by measuring the baseline:

1. **The title's colour is not under application control.** `.template-btn`
   (`public-assets/css/app.css:228-235`) sets `background: var(--card)` but never sets
   `color`. A `<button>` does not inherit `color` — the UA supplies `ButtonText`. So the
   button paints an app-chosen background against a UA-chosen foreground. Its sibling
   `.template-btn-delta` (`:240`) *does* set an explicit colour, which is exactly the
   asymmetry the report describes: the delta survives a foreign colour environment, the
   title does not.
2. **A long title is clipped silently.** `.template-btn*` sets no `overflow-wrap`, so an
   unbroken title widens its grid track; `html { overflow-x: clip }` (`:19`) then hides the
   overflow instead of scrolling it. The existing overflow assertion
   (`e2e/specs/parent.spec.ts:113-127`) reads `documentElement.scrollWidth`, which `clip`
   has already normalised — so it is structurally blind to this.

Neither property has a test. No PHPUnit test renders a real parent view (coverage `<source>`
is `app/Domain` + `app/Core` only), and no e2e test asserts anything about the quick-action
titles.

## Surgical scope

Limited to exactly the operator's request: make the quick-action title's colour and wrapping
explicit on the parent → child screen, and pin "the title is visible, legible and unclipped
at phone widths" with tests. Two CSS declarations, the version bump INV-006 requires for a
CSS change to reach a browser, and the tests.

**Out of scope** (tempting, deliberately excluded):

- Redesigning `.template-grid` / the quick-action card layout, or adding a phone-specific
  block for it between 381px and 860px. The baseline lays out correctly; only colour and
  wrapping are unguaranteed.
- `grid-template-columns: repeat(auto-fill, minmax(min(11rem, 100%), 1fr))`. A real
  robustness improvement, but the measured baseline does not overflow at any tested width,
  so it is a change to layout the operator did not ask for. Not filed as an issue — no
  defect was observed.
- Giving every other `<button>` in `app.css` an explicit colour (`.btn-primary`, `.star-btn`,
  `.topbar-menu-toggle`, …). Same class of latent gap, different screens, not in #15's scope.
  Filed as a follow-up issue instead (see *Risks*).
- Changing the `860px` / `380px` breakpoints. `e2e/specs/assets.spec.ts:31,47` hard-code the
  string `@media (max-width: 860px)`; moving it would break an unrelated guard.
- Touching `sw.js` / `VERSIONED_PATHS`. No new asset file is added, so the INV-006 pairing is
  unchanged. (Issue #10 covers the precache gaps; it stays open.)
- Any change to `views/parent/child.php`. The markup already emits the title span; nothing
  about it needs to change, and touching it would re-arm
  `tests/Unit/ServiceWorkerShellTest.php:247-276`.

## What ships

### 1. An explicit foreground colour for the quick-action button

`public-assets/css/app.css`, in the `.template-btn` block:

```css
.template-btn {
    width: 100%; font: inherit; cursor: pointer; text-align: left;
    /* A <button> does not inherit `color` — the UA supplies ButtonText — so a
       button that sets its own background is painting an app colour against a
       system colour. .template-btn-delta already sets one; the title relied on
       the UA, which is the one part of this screen we do not control. */
    color: var(--ink);
    display: flex; flex-direction: column; gap: .2rem;
    ...
}
```

`--ink` is `#2c2a3a` (`:6`) against `--card` `#fffdf8` (`:9`) — a contrast ratio of ~13.7:1,
comfortably above WCAG AA's 4.5:1. Visually this is a near-no-op in a normal browser
(`ButtonText` is `rgb(0,0,0)` there, measured); its whole purpose is that the value is now
the app's, not the environment's.

### 2. Wrapping that cannot clip

```css
.template-btn-title { font-weight: 800; overflow-wrap: anywhere; }
```

`overflow-wrap: anywhere` is already the idiom in this stylesheet for exactly this problem
(`.install-summary dd:258`, `.check-detail:261`, `.release-notes:262`). It breaks an
unbreakable word rather than letting it widen the grid track — and, unlike `break-word`, it
also affects min-content sizing, which is what stops the track from growing in the first
place.

### 3. Version bump — without it the fix reaches nobody (INV-006)

`VERSION`: `0.1.4` → `0.1.5`, plus the matching `## [0.1.5]` section in `CHANGELOG.md`.

This is not bookkeeping. No `Cache-Control` ships (INV-003: mod_headers is not guaranteed on
the target hosting), so browsers cache `app.css` heuristically, and `sw.js` serves
`/public-assets/` cache-first. `Assets::url()` appends `?v=<VERSION>`; leaving `VERSION` at
`0.1.4` leaves the URL byte-identical, so a returning phone keeps the old stylesheet
indefinitely. This repo has already shipped that exact failure once (LESSONS 2026-08-10, "A
shipped fix the browser never downloads is not a shipped fix"). `release.yml:36-45` enforces
tag == VERSION == CHANGELOG.

### 4. Tests (see *Tests* for the numbered list)

New mobile-gated block in `e2e/specs/parent.spec.ts` plus a desktop non-regression mirror.

## Teilaufgaben

1. **RED — write the tests first.** Add the `quick actions stay legible` tests to
   `e2e/specs/parent.spec.ts` (T1–T4 below) against the unchanged stylesheet. Run
   `npx playwright test specs/parent.spec.ts` and confirm the expected RED set exactly:
   **T2, T3 and T4 fail**, T1 passes.
   - T2 fails because `.template-btn`'s computed colour comes from the UA, not `var(--ink)`.
   - T3 fails because the injected unbroken title overflows the button box.
   - **T4 fails too**, and must: it repeats T2's colour assertion on the `desktop` project,
     and the missing `color` declaration is viewport-independent. A T4 that passed before
     the fix would mean its colour assertion is not actually asserting anything.
   - T1 passes on purpose — it is the baseline the reproduction measured and must never
     regress. It is the one test here whose job is to stay green.
2. **GREEN — the two declarations.** Add `color: var(--ink)` to `.template-btn` and
   `overflow-wrap: anywhere` to `.template-btn-title` in `public-assets/css/app.css`. Re-run
   the spec on both projects; all four tests green.
3. **Delivery.** Bump `VERSION` to `0.1.5` and add the `## [0.1.5]` entry to `CHANGELOG.md`.
   Verify the served stylesheet URL now carries `?v=0.1.5` and that the delivered bytes
   contain the two new declarations (the check `assets.spec.ts:22-32` performs for the
   media query).
4. **Verify.** `php -l` on nothing changed (no PHP touched); `vendor/bin/phpunit` full suite
   green (regression guard — `ServiceWorkerShellTest` and `AssetUrlTest` in particular);
   `npx playwright test specs/parent.spec.ts specs/assets.spec.ts specs/templates.spec.ts`
   green on `desktop` + `mobile`.
5. **Docs — outside the repo.** Update the Coding Vault pages at
   `~/Coding-Vault/Coding/familycastle/`: `CHANGELOG.md`, `ARCHITECTURE.md` (the "an
   app-styled control owns its foreground" rule) and `LESSONS.md` (the
   not-reproducible-by-measurement entry). Mandated by workflow §11 and hook-enforced at
   commit time. These files live **outside this repository**, so they are deliberately
   absent from *Scope* and *Files changed*, which list repo files only — they add nothing to
   the PR diff.

## Scope

**In:** `public-assets/css/app.css` (2 declarations), `VERSION`, `CHANGELOG.md`,
`e2e/specs/parent.spec.ts` (4 tests).

**Out:** everything listed under *Surgical scope*.

## Edge cases

- **A favourite template.** The title span carries the `⭐ ` prefix
  (`views/parent/child.php:27`). T1 asserts on the button's own title text, so the marker
  travels with it; the demo family's first two templates are favourites, so the case is
  covered by real data rather than by construction.
- **A negative template.** `.template-btn.negative` overrides only `border-color` and
  `box-shadow` (`:237`), never `color`, so the new declaration applies unchanged. T1 iterates
  every button on the screen, which includes the negative ones when present.
- **A very long unbroken title.** `title` is `VARCHAR(190)` and `TemplateService::validated()`
  rejects `> 190` chars, so 190 characters with no space is the true worst case. T3 injects
  a 190-char unbroken string rather than a plausible one.
- **An empty template list.** `views/parent/child.php:17` wraps the whole section in
  `if (!empty($templates))`. T1 asserts `count > 0` first, so an empty seed fails loudly
  instead of passing vacuously — the "test passed for the wrong reason" trap this repo has
  hit before (LESSONS 2026-08-09, 2026-07-27).
- **The 380px breakpoint.** Below it the grid is one column; above it, tracks are ≥11rem.
  T3 runs in the `mobile` project (390px), i.e. on the multi-column side, which is the side
  where a widened track can actually clip.
- **The cookie notice.** It is `position: fixed` at the bottom and `.parent-main` reserves
  `13rem` for it (`:156`). T1 asserts titles are within the viewport horizontally and have a
  non-zero box, not that they are unobscured vertically — vertical overlap is already pinned
  by `persistent-login.spec.ts:204-220` and is not this issue's subject.
- **A stale service worker on a phone.** Handled by the version bump, not by CSS:
  `caches.match()` compares the full URL, so a request for `?v=0.1.5` cannot be answered from
  the `fc-shell-0.1.4` cache and falls through to the network.

## Threat model

**None — no new attack surface.** The change adds two static CSS declarations, increments a
version file and adds test code. It introduces no input handling, no query, no route, no
authentication or authorisation decision, and no new data flow.

The one input-shaped element in the area is the template `title`, which is attacker-adjacent
only in the sense that a parent types it. It is already escaped at the sink with `e()`
(`views/parent/child.php:27`) and length-bounded to 190 chars by
`TemplateService::validated()`; this change does not touch either, and `overflow-wrap` is a
rendering hint with no parsing behaviour. `VERSION` is rendered into an HTML attribute via
`Assets::setVersion()`, which strips everything outside `[A-Za-z0-9._-]` and is pinned by
`AssetUrlTest::testVersionIsSanitisedToAnAllowlist`; `0.1.5` passes through unchanged. Blast
radius if the CSS were wrong: a cosmetic regression on one parent screen, caught by T1–T4.

## Tests

All four live in `e2e/specs/parent.spec.ts` — the file CI already runs
(`.github/workflows/ci.yml:137` lists specs explicitly, so a new file would silently not
run). T1–T3 are gated with `test.skip(testInfo.project.name !== 'mobile', …)` following the
existing idiom (`assets.spec.ts:56`); T4 is the desktop mirror.

1. **T1 — every quick-action button shows its title (mobile, 390×844).** After
   `parentLogin` → Emma's child screen: `.template-btn` count > 0; for each button, its
   `.template-btn-title` has non-empty trimmed text, a bounding box with `width > 0` and
   `height > 0`, and sits within `[0, viewportWidth]` horizontally. This is the property #15
   reports as broken and the one the reproduction measured — it must never regress.
2. **T2 — the title's colour is the app's, not the environment's (mobile).** The computed
   `color` of `.template-btn` equals the computed `color` of an element that resolves
   `var(--ink)` (read from `document.body`, which sets `color: var(--ink)` at `app.css:27`),
   i.e. the button no longer falls back to `ButtonText`. Additionally the computed contrast
   ratio between the title's colour and the button's background is ≥ 4.5:1 (WCAG AA). Fails
   before Teilaufgabe 2.
3. **T3 — a maximum-length unbroken title wraps instead of clipping (mobile).** Inject a
   190-character unbroken string into the first `.template-btn-title` via `textContent`,
   then assert `button.scrollWidth <= button.clientWidth + 1`. Hermetic: it exercises the
   real CSS against the real worst case without writing to the database. Fails before
   Teilaufgabe 2 (`overflow-wrap: normal` lets the word widen the track).
4. **T4 — desktop non-regression.** On the `desktop` project (1280×800), the same
   title-visible and colour assertions as T1/T2, so the fix cannot be a mobile-only patch
   that breaks the wide layout. **Expected RED before Teilaufgabe 2**, like T2: the missing
   `color` declaration is viewport-independent, so T4's colour assertion must fail on
   desktop too. Its title-visible half passes throughout — the colour half is what makes it
   a real test rather than a duplicate of T1.

Existing suites re-run as regression guards: `specs/assets.spec.ts` (the `?v=` token and the
`@media (max-width: 860px)` string both survive), `specs/templates.spec.ts`, and the full
PHPUnit suite (`ServiceWorkerShellTest` pins that no new asset appeared).

## Files changed

| File | Change |
|---|---|
| `public-assets/css/app.css` | `+ color: var(--ink)` on `.template-btn`; `+ overflow-wrap: anywhere` on `.template-btn-title` (+ the explanatory comment) |
| `VERSION` | `0.1.4` → `0.1.5` — INV-006: without it the CSS never reaches a returning browser |
| `CHANGELOG.md` | new `## [0.1.5]` section (required by `release.yml:36-45`) |
| `e2e/specs/parent.spec.ts` | + `quick actions stay legible` describe block: T1–T4 |

No PHP, no view, no route, no migration, no `sw.js` change.

## Risks

- **Low — the colour could differ from the UA's on some platform.** That is the point, but it
  means the rendering changes where `ButtonText` was not `#000`. `--ink` `#2c2a3a` is the
  colour every other text surface on the page already uses, so the direction of the change is
  toward consistency. T2 pins the value; T4 pins that desktop is unaffected.
- **Low — `overflow-wrap: anywhere` affects min-content sizing**, so a grid track containing a
  very long word now shrinks where it previously grew. Since the track already had a `11rem`
  floor and the button is `width: 100%`, the visible effect is confined to wrapping. T3 pins
  the outcome; T1 pins that ordinary titles still lay out.
- **Low — forgetting the version bump.** Then the whole change is invisible in the field. This
  is the failure this repo already shipped once; Teilaufgabe 3 makes it a step, and
  `release.yml` fails the release if `VERSION`, the tag and the CHANGELOG disagree.
- **Follow-up, not a risk to this diff:** the same "app background, UA foreground" gap exists
  on other controls (`.btn-primary`, `.star-btn`, `.topbar-menu-toggle`). Out of scope here;
  filed as a separate GitHub issue and declared in the commit body as a drive-by.

## Invariants and lessons this plan is bound by

**INV-006 (HARD) — emitted CSS/JS carries the version token, and the SW shell matches it:
preserved, and it is the reason Teilaufgabe 3 exists.** This change edits bytes under
`public-assets/css/`, which is precisely the case the invariant governs. It is preserved in
all three of its clauses: (a) the stylesheet keeps being emitted through `asset()` from
`views/layouts/parent.php:8` — no `<link>` is added, moved or hand-written, so every CSS URL
still carries `?v=`; (b) `VERSION` is bumped `0.1.4` → `0.1.5`, so the token actually changes
and a returning browser's heuristically-cached copy and the `fc-shell-0.1.4` precache entry
are both bypassed (`caches.match()` compares the full URL including the query, so the request
for `?v=0.1.5` misses and falls through to the network — a client stuck on the old worker
self-heals); (c) **no new asset file is created**, so `sw.js`'s `VERSIONED_PATHS` /
`STATIC_SHELL` split is untouched and the bidirectional pairing pinned by
`tests/Unit/ServiceWorkerShellTest.php` still holds in both directions. Fonts and icons keep
going through `url()`.

**Avoids LESSONS 2026-08-10 "A shipped fix the browser never downloads is not a shipped
fix".** That entry is the direct ancestor of this one: an operator reported a mobile UI as
missing, and the cause was delivery (a stale `app.css`), not markup — rebuilding the feature
would not have fixed that phone. What we do instead, in order: (1) **verified what the
browser actually received before touching any code** — `GET app.css?v=0.1.4` returned HTTP
200 / 17 505 bytes, `diff` against disk empty, and the released ZIP byte-identical to the dev
tree, which is what rules delivery out here and is recorded in `reproduction.md`;
(2) distinguished the entry's two failure modes explicitly — *not served* (404 from file
modes) vs *served but stale* (200 with an old body) — by reading both the status code and the
body length; (3) treated the version bump as part of the fix rather than as release
bookkeeping, because a CSS-only diff would reproduce the lesson exactly.

**Avoids LESSONS 2026-08-10 "extract(EXTR_SKIP) makes a shared helper's local variables part
of its public API".** That bug lives on this very screen's neighbour: `View::renderFile()`
silently dropped a caller's `template` key, and `views/parent/child.php:20` loops
`foreach ($templates as $template)`. This plan **does not touch `views/parent/child.php` at
all** and adds no controller data key, so no new name can collide; the loop variable stays
view-local, where `extract()` cannot reach it. `tests/Unit/ViewTest.php` continues to pin the
helper's reserved-name guard, and the full PHPUnit suite runs in Teilaufgabe 4.

**LESSONS 2026-08-10 "File modes are program state" — does not apply.** It governs code that
*creates* filesystem entries during an update (`update.php`'s staging/swap). This change edits
the contents of two existing tracked files and creates no filesystem entry in the live tree,
so no mode is inherited from an ambient umask. The release ZIP stores no unix modes and this
diff does not change which paths are in it, so *update state ≡ install state* is unaffected.

## Prior issues considered

`.claude/cache/prior-issues-considered.md` — 8 issues + 7 PRs scored, 5 read in full.
Load-bearing takeaways: **#11/#2** (a CSS change without a `VERSION` bump is a fix nobody
downloads — INV-006), **#12/#14** (this screen loops over a view-local `$template`; keep it
view-local, never pass a `template` data key), **#4f22048** (the cookie notice already
reserves space at the bottom of this screen — do not reintroduce an overlap).

## Acceptance checklist

- [ ] T1–T4 written first and T2/T3 observed RED for the right reason
- [ ] `.template-btn` declares `color: var(--ink)`; `.template-btn-title` declares
      `overflow-wrap: anywhere`
- [ ] `VERSION` = `0.1.5`, `CHANGELOG.md` has a `## [0.1.5]` section, and the served
      stylesheet URL carries `?v=0.1.5` with the new declarations in the delivered bytes
- [ ] `vendor/bin/phpunit` fully green (no PHP changed; regression guard)
- [ ] `npx playwright test specs/parent.spec.ts specs/assets.spec.ts specs/templates.spec.ts`
      green on `desktop` **and** `mobile`
- [ ] `views/parent/child.php`, `sw.js` and every PHP file untouched
- [ ] Vault `CHANGELOG.md`, `ARCHITECTURE.md`, `LESSONS.md` updated
- [ ] Drive-by follow-up issue filed for the other UA-foreground controls
