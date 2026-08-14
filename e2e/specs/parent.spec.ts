import { test, expect } from '@playwright/test';
import { parentLogin, parentNavigate } from './helpers';

test.describe('parent flows', () => {
  test.beforeEach(async ({ page }) => {
    await parentLogin(page);
  });

  test('dashboard shows both demo children with coins and level', async ({ page }) => {
    await expect(page.getByRole('link', { name: /Emma/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /Noah/ })).toBeVisible();
    await expect(page.locator('body')).toContainText('Level');
  });

  test('quick award adds coins through the ledger', async ({ page }) => {
    await page.getByRole('link', { name: /Emma/ }).first().click();
    await expect(page).toHaveURL(/\/parent\/child\/\d+/);

    const balanceText = await page.locator('body').textContent();
    const before = Number((balanceText?.match(/🪙\s*(\d+)/) ?? [])[1] ?? NaN);
    expect(Number.isNaN(before)).toBe(false);

    // Use the first quick-award template button on the child screen.
    const award = page.getByRole('button', { name: /\+\s*\d+/ }).first();
    await expect(award).toBeVisible();
    const amount = Number(((await award.textContent())?.match(/\+\s*(\d+)/) ?? [])[1] ?? 0);
    await award.click();

    await expect(page.locator('body')).toContainText(`🪙 ${before + amount}`);
  });

  test('approvals center lists pending decisions', async ({ page }) => {
    await parentNavigate(page, /Genehmigungen/);
    await expect(page).toHaveURL(/approvals/);
    await expect(
      page.getByRole('heading', { name: /Genehmigungen|Entscheidungen/ })
    ).toBeVisible();
  });

  test('sidequest admin page lists quests and the create form works', async ({ page }) => {
    await parentNavigate(page, 'Sidequests');
    await expect(page).toHaveURL(/quests/);
    // The create form lives in a collapsed <details> panel.
    await page.getByText('＋ Sidequest erstellen').click();
    const unique = `E2E-Quest ${Date.now()}`;
    const form = page.locator('details').first();
    await form.getByRole('textbox').first().fill(unique);
    await form.getByRole('spinbutton').first().fill('7');
    await form.getByRole('button', { name: /erstellen|anlegen|speichern/i }).first().click();
    await expect(page.locator('body')).toContainText(unique);

    // Leave the demo tidy: archive the quest this test created.
    const card = page.locator('.list-card', { hasText: unique }).first();
    await card.getByRole('button', { name: 'Archivieren' }).click();
    await expect(page.locator('body')).not.toContainText(unique);
  });

  test('child screen shows ledger history entries', async ({ page }) => {
    await page.getByRole('link', { name: /Emma/ }).first().click();
    // The demo seeds 33 ledger entries — the history list itself must render,
    // not merely the coin glyph in the header.
    const rows = page.locator('.history-list .history-row');
    expect(await rows.count()).toBeGreaterThan(3);
    await expect(rows.first().locator('.history-delta')).toBeVisible();
  });

  test('system status page reports checks', async ({ page }) => {
    await page.goto('/parent/settings/status');
    await expect(page.locator('body')).toContainText(/PHP/);
  });

  test('updates page renders without errors', async ({ page }) => {
    await page.goto('/parent/settings/updates');
    await expect(page.locator('body')).toContainText(/Version/);
  });

  test('backups page renders and offers creation', async ({ page }) => {
    await page.goto('/parent/settings/backups');
    await expect(page.getByRole('button', { name: /Backup/ }).first()).toBeVisible();
  });
});

test.describe('mobile parent experience', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'phone-specific navigation contract');
    await parentLogin(page);
  });

  test('hamburger menu is accessible, keyboard friendly, and links to updates', async ({ page }) => {
    const toggle = page.locator('.topbar-menu-toggle');
    const menu = page.locator('#parent-menu');

    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAccessibleName(/Menü öffnen|Open menu/);
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toBeHidden();

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(toggle).toHaveAccessibleName(/Menü schliessen|Close menu/);
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('link', { name: /Updates/ })).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(toggle).toBeFocused();

    await toggle.click();
    await menu.getByRole('link', { name: /Updates/ }).click();
    await expect(page).toHaveURL(/\/parent\/settings\/updates/);
  });

  test('dashboard, child screen, and approvals never overflow the phone viewport', async ({ page }) => {
    const expectNoHorizontalOverflow = async (): Promise<void> => {
      const dimensions = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
      }));
      expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth);
    };

    await expectNoHorizontalOverflow();
    await page.getByRole('link', { name: /Emma/ }).first().click();
    await expectNoHorizontalOverflow();
    await page.goto('/parent/approvals');
    await expectNoHorizontalOverflow();
  });
});

/**
 * Issue #15 — the quick actions (Schnellaktionen) on the parent → child screen were
 * reported as rendering without their titles on a phone, leaving only the coin/XP delta.
 * The symptom did not reproduce on v0.1.4, but measuring the baseline exposed two
 * properties that were only true by luck and that nothing asserted:
 *
 *   1. `.template-btn` sets its own background but used to set no `color`. A <button> does
 *      NOT inherit `color` — the UA supplies ButtonText — so the app was painting its own
 *      background against a system foreground. Its sibling `.template-btn-delta` has always
 *      set an explicit colour, which is exactly the asymmetry the report describes.
 *   2. Nothing set `overflow-wrap`, so an unbroken title widened its grid track, and
 *      `html { overflow-x: clip }` then hid the overflow instead of scrolling it — which is
 *      why the viewport-overflow test above is structurally blind to it.
 *
 * These tests pin both, plus the baseline itself (the titles render at all).
 */
const CONTRAST_AA = 4.5;

/**
 * WCAG relative-luminance contrast ratio between two OPAQUE `rgb(r, g, b)` strings.
 *
 * Throws on anything else. A translucent foreground composites against whatever is
 * behind it, so a ratio computed from its nominal channels would be fiction — and a
 * fictional ratio that happens to clear 4.5 is a test passing for the wrong reason.
 * Chromium serialises opaque colours as `rgb(r, g, b)` and translucent ones as
 * `rgba(r, g, b, a)`, so the shape is a reliable discriminator; `color(...)` (wide
 * gamut) is rejected for the same reason rather than parsed as sRGB.
 */
function contrastRatio(colorA: string, colorB: string): number {
  const channels = (value: string): number[] => {
    const opaque = value.match(/^rgb\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*\)$/);
    if (!opaque) {
      throw new Error(`contrastRatio() needs an opaque rgb(...) colour, got: ${value}`);
    }
    return opaque.slice(1, 4).map(Number);
  };
  const luminance = (rgb: number[]): number => {
    const [r, g, b] = rgb.map((channel) => {
      const s = channel / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const a = luminance(channels(colorA));
  const b = luminance(channels(colorB));
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/**
 * Reads the quick-action colours, plus two probes that make the comparison meaningful:
 *
 *  - `inkColor` comes from an element whose only style is `color: var(--ink)`, so it is
 *    the resolved custom property — not `document.body`'s colour, which would merely
 *    happen to equal it.
 *  - `uaButtonColor` comes from a bare <button> with no author colour, i.e. exactly what
 *    `.template-btn` used to render. Asserting the button differs from THIS is what stops
 *    the test passing on a platform where ButtonText coincides with the app ink.
 */
async function readQuickActionColours(page: import('@playwright/test').Page) {
  return page.evaluate(() => {
    const button = document.querySelector('.template-btn') as HTMLElement;
    const title = button.querySelector('.template-btn-title') as HTMLElement;

    const inkProbe = document.createElement('span');
    inkProbe.style.color = 'var(--ink)';
    const uaProbe = document.createElement('button');
    document.body.append(inkProbe, uaProbe);

    const result = {
      inkColor: getComputedStyle(inkProbe).color,
      uaButtonColor: getComputedStyle(uaProbe).color,
      buttonColor: getComputedStyle(button).color,
      titleColor: getComputedStyle(title).color,
      buttonBackground: getComputedStyle(button).backgroundColor,
    };

    inkProbe.remove();
    uaProbe.remove();
    return result;
  });
}

/** The colour contract, asserted identically on both viewports. */
function expectAppInkAndLegibleContrast(colours: {
  inkColor: string;
  uaButtonColor: string;
  buttonColor: string;
  titleColor: string;
  buttonBackground: string;
}): void {
  // Guard the guard: if these ever coincide, the assertion below proves nothing.
  expect(
    colours.inkColor,
    'the app ink and the UA ButtonText coincide, so this test cannot tell them apart'
  ).not.toBe(colours.uaButtonColor);

  expect(colours.buttonColor).toBe(colours.inkColor);
  expect(colours.buttonColor).not.toBe(colours.uaButtonColor);
  expect(colours.titleColor).toBe(colours.inkColor);
  expect(contrastRatio(colours.titleColor, colours.buttonBackground)).toBeGreaterThanOrEqual(
    CONTRAST_AA
  );
}

test.describe('quick actions stay legible', () => {
  test.beforeEach(async ({ page }) => {
    await parentLogin(page);
    await page.getByRole('link', { name: /Emma/ }).first().click();
    await expect(page).toHaveURL(/\/parent\/child\/\d+/);
  });

  // T1 — the property issue #15 reports as broken, and the one the reproduction measured.
  test('every quick-action button shows its title', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'mobile-viewport behaviour');

    const buttons = page.locator('.template-btn');
    const count = await buttons.count();
    // Guard against passing vacuously on an empty seed: no buttons, no assertion.
    expect(count).toBeGreaterThan(0);

    const viewport = page.viewportSize();
    expect(viewport).not.toBeNull();

    for (let index = 0; index < count; index += 1) {
      const title = buttons.nth(index).locator('.template-btn-title');
      await expect(title).toBeVisible();
      expect(((await title.textContent()) ?? '').trim()).not.toBe('');

      const box = await title.boundingBox();
      expect(box, `title ${index} has no layout box`).not.toBeNull();
      expect(box!.width).toBeGreaterThan(0);
      expect(box!.height).toBeGreaterThan(0);
      expect(box!.x).toBeGreaterThanOrEqual(0);
      expect(box!.x + box!.width).toBeLessThanOrEqual(viewport!.width);
    }
  });

  // T2 — the title's colour must be the app's, not whatever the environment supplies.
  test('the title colour is the app ink, not the UA ButtonText', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'mobile-viewport behaviour');

    expectAppInkAndLegibleContrast(await readQuickActionColours(page));
  });

  // T3 — a title of the maximum storable length, unbroken, must wrap rather than clip.
  // `title` is VARCHAR(190) and TemplateService::validated() rejects anything longer, so
  // 190 characters with no space is the real worst case. Injected into the DOM rather than
  // seeded, so the test exercises the CSS without writing to the database.
  test('a maximum-length unbroken title wraps instead of clipping', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'mobile-viewport behaviour');

    const measured = await page.evaluate(() => {
      const button = document.querySelector('.template-btn') as HTMLElement;
      const title = button.querySelector('.template-btn-title') as HTMLElement;
      const singleLineHeight = title.getBoundingClientRect().height;

      title.textContent = 'W'.repeat(190);

      return {
        singleLineHeight,
        wrappedHeight: title.getBoundingClientRect().height,
        scrollWidth: button.scrollWidth,
        clientWidth: button.clientWidth,
        overflow: getComputedStyle(button).overflow,
      };
    });

    // Not overflowing is necessary but NOT sufficient: `overflow: hidden` would also
    // satisfy it while clipping the title, which is the very failure being guarded.
    // So assert both — the box does not overflow, AND the text actually reflowed onto
    // more lines rather than being cut off.
    expect(measured.overflow).toBe('visible');
    expect(measured.wrappedHeight).toBeGreaterThan(measured.singleLineHeight);
    // +1 absorbs sub-pixel rounding only.
    expect(measured.scrollWidth).toBeLessThanOrEqual(measured.clientWidth + 1);
  });

  // T4 — desktop non-regression. Expected RED before the fix too: the missing `color`
  // declaration is viewport-independent, so this must fail on desktop as well.
  test('titles render and keep the app ink on desktop', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'desktop-viewport non-regression guard');

    const buttons = page.locator('.template-btn');
    expect(await buttons.count()).toBeGreaterThan(0);
    await expect(buttons.first().locator('.template-btn-title')).toBeVisible();

    expectAppInkAndLegibleContrast(await readQuickActionColours(page));
  });
});
