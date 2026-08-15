import { expect, test } from '@playwright/test';
import { kidLoginEmma, parentLogin } from './helpers';

/**
 * Asset delivery and the mobile parent navbar.
 *
 * The bug these guard: a phone kept rendering the parent portal with the
 * DESKTOP navbar (all nine links wrapped over three rows, overlapping the
 * brand) long after the mobile CSS had shipped, because the browser reused a
 * cached app.css from an earlier release — the @media (max-width: 860px) block
 * never reached the CSS parser at all. Nothing about the markup was wrong, so
 * only a test that looks at the DELIVERED stylesheet can catch it.
 */

const MOBILE_ONLY = 'mobile-viewport behaviour';

test.describe('asset cache-busting', () => {
  test.beforeEach(async ({ page }) => {
    await parentLogin(page);
  });

  test('the dashboard stylesheet carries a version token and is served', async ({ page }) => {
    const href = await page.locator('link[rel="stylesheet"][href*="app.css"]').getAttribute('href');

    expect(href).toMatch(/\/public-assets\/css\/app\.css\?v=/);

    const response = await page.request.get(href!);
    expect(response.status()).toBe(200);

    // The delivered bytes must be the CURRENT stylesheet, not merely *a* 200.
    expect(await response.text()).toContain('@media (max-width: 860px)');
  });

  test('the hamburger script is versioned too', async ({ page }) => {
    const src = await page.locator('script[src*="navigation.js"]').getAttribute('src');
    expect(src).toMatch(/\/public-assets\/js\/navigation\.js\?v=/);
  });

  test('the mobile media query actually reaches the CSS parser', async ({ page }) => {
    const conditions = await page.evaluate(() => {
      const sheet = [...document.styleSheets].find((s) => s.href?.includes('app.css'));
      return [...sheet!.cssRules]
        .filter((r) => r.type === CSSRule.MEDIA_RULE)
        .map((r) => (r as CSSMediaRule).conditionText);
    });

    expect(conditions).toContain('(max-width: 860px)');
  });
});

test.describe('mobile parent navbar', () => {
  test.beforeEach(async ({ page }) => {
    await parentLogin(page);
  });

  test('shows only the brand and a hamburger, with the menu closed', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', MOBILE_ONLY);

    await expect(page.locator('.topbar-menu-toggle')).toBeVisible();
    await expect(page.locator('#parent-menu')).toBeHidden();
    await expect(page.locator('.topbar-brand')).toBeVisible();

    // "Nothing else in the navbar": no navigation entry is reachable while closed.
    await expect(page.locator('.topbar-nav a').first()).toBeHidden();
    await expect(page.getByRole('button', { name: 'Abmelden' })).toBeHidden();
  });

  test('the hamburger holds every entry and the logout', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', MOBILE_ONLY);

    await page.locator('.topbar-menu-toggle').click();

    const menu = page.locator('#parent-menu');
    await expect(menu).toBeVisible();
    await expect(menu.locator('.topbar-nav a')).toHaveCount(10);

    for (const label of [
      'Übersicht', 'Genehmigungen', 'Sidequests', 'Belohnungen', 'Meilensteine',
      'Kinder', 'Vorlagen', 'Minuspunkte', 'System', 'Updates',
    ]) {
      await expect(menu.getByRole('link', { name: new RegExp(label) })).toBeVisible();
    }

    await expect(menu.getByRole('button', { name: 'Abmelden' })).toBeVisible();
  });

  test('the desktop navbar is unchanged', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'desktop-viewport non-regression guard');

    await expect(page.locator('.topbar-menu-toggle')).toBeHidden();
    await expect(page.locator('.topbar-nav a').first()).toBeVisible();
  });
});

/**
 * The lifecycle test. Structural assertions about sw.js cannot observe this
 * failure class: only a real worker, a real cache and a real reload can show
 * that a client stuck on the previous shell still receives the new stylesheet.
 */
test.describe('service worker shell migration', () => {
  test('a stale fc-shell-v1 cache cannot mask the current stylesheet', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', MOBILE_ONLY);

    // A kid page is the only one that registers the worker.
    await kidLoginEmma(page);
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15_000 });

    const scope = new URL(baseURL ?? 'http://localhost:8090').pathname;

    // Seed exactly the pre-fix state: the old cache name, holding the old
    // UNVERSIONED url, with a stylesheet that lacks the mobile media query.
    await page.evaluate(async (scopeKey) => {
      const cache = await caches.open('fc-shell-v1:' + scopeKey);
      await cache.put(
        new Request(scopeKey + 'public-assets/css/app.css'),
        new Response('.topbar-nav{--stale-sentinel:1}', { headers: { 'Content-Type': 'text/css' } }),
      );
    }, scope);

    // Prove the seed is really in the worker's serving path, so that a pass
    // below means "the token bypassed a LIVE stale cache" and not "the cache
    // was never consulted anyway". Requested WITHOUT a token — i.e. exactly what
    // the pre-fix markup emitted — the stale bytes still come back.
    const stale = await page.evaluate(async (scopeKey) => {
      const res = await fetch(scopeKey + 'public-assets/css/app.css');
      return res.text();
    }, scope);
    expect(stale).toContain('--stale-sentinel');

    await parentLogin(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();

    // The versioned request misses the stale entry and goes to the network.
    const conditions = await page.evaluate(() => {
      const sheet = [...document.styleSheets].find((s) => s.href?.includes('app.css'));
      return [...sheet!.cssRules]
        .filter((r) => r.type === CSSRule.MEDIA_RULE)
        .map((r) => (r as CSSMediaRule).conditionText);
    });
    expect(conditions).toContain('(max-width: 860px)');

    const sentinel = await page.evaluate(
      () => getComputedStyle(document.querySelector('.topbar-nav')!).getPropertyValue('--stale-sentinel').trim(),
    );
    expect(sentinel).toBe('');

    // …and the hamburger layout is what the user actually sees.
    await expect(page.locator('.topbar-menu-toggle')).toBeVisible();
    await expect(page.locator('#parent-menu')).toBeHidden();
  });

  /**
   * Codex's impl review round 1 argued the activate cleanup could not delete a
   * legacy `fc-shell-v1:<scope>` cache, leaving it free to answer requests
   * (caches.match searches EVERY cache in the origin). The old name was
   * `'fc-shell-v1:' + SCOPE_KEY`, so the `:<scope>` suffix test does match it —
   * but "I read the predicate" is not evidence. This proves it.
   */
  test('activating the versioned worker deletes the legacy fc-shell-v1 cache', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', MOBILE_ONLY);

    const scope = new URL(baseURL ?? 'http://localhost:8090').pathname;

    // Start from a page that does NOT register the worker, so the seeding
    // happens before any install/activate cycle we care about.
    await page.goto('/login');
    await page.evaluate(async (scopeKey) => {
      for (const reg of await navigator.serviceWorker.getRegistrations()) {
        await reg.unregister();
      }
      for (const key of await caches.keys()) {
        await caches.delete(key);
      }
      const legacy = await caches.open('fc-shell-v1:' + scopeKey);
      await legacy.put(
        new Request(scopeKey + 'public-assets/css/app.css'),
        new Response('.stale{}', { headers: { 'Content-Type': 'text/css' } }),
      );
    }, scope);

    expect(await page.evaluate(() => caches.keys())).toContain(`fc-shell-v1:${scope}`);

    // Now let a kid page install and activate the versioned worker.
    await kidLoginEmma(page);
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15_000 });

    await expect
      .poll(async () => page.evaluate(() => caches.keys()), { timeout: 15_000 })
      .not.toContain(`fc-shell-v1:${scope}`);
  });

  test('the shell cache is keyed to the app version', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', MOBILE_ONLY);

    await kidLoginEmma(page);
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15_000 });

    const version = await page.evaluate(() => {
      const sw = document.body.dataset.sw ?? '';
      return new URL(sw, location.href).searchParams.get('v');
    });
    expect(version).toBeTruthy();

    const scope = new URL(baseURL ?? 'http://localhost:8090').pathname;
    await expect
      .poll(async () => page.evaluate(() => caches.keys()), { timeout: 15_000 })
      .toContain(`fc-shell-${version}:${scope}`);

    // The versioned worker's own precache entries carry the same token.
    const cached = await page.evaluate(async (key) => {
      const cache = await caches.open(key);
      return (await cache.keys()).map((r) => r.url);
    }, `fc-shell-${version}:${scope}`);

    expect(cached.some((u) => u.includes(`public-assets/css/app.css?v=${version}`))).toBe(true);
    expect(cached.some((u) => u.includes(`offline.html?v=${version}`))).toBe(true);
    // Fonts stay unversioned: fonts.css requests them without a token.
    expect(cached.some((u) => u.endsWith('public-assets/fonts/nunito-400.woff2'))).toBe(true);
  });
});
