import { test, expect } from '@playwright/test';
import { kidLoginEmma } from './helpers';

test.describe('child experience (RULE #1: it must feel like a game)', () => {
  test.beforeEach(async ({ page }) => {
    await kidLoginEmma(page);
  });

  test('home is a themed world with level and coins, not a form', async ({ page }) => {
    const body = page.locator('body');
    await expect(body).toContainText(/Level\s*\d+/);
    await expect(body).toContainText(/🪙|Coins/);
    // The world scene is an inline SVG — business software has no kingdoms.
    await expect(page.locator('svg').first()).toBeVisible();
    // No raw admin tables on the child home.
    expect(await page.locator('table').count()).toBe(0);
  });

  test('sidequest board renders quests', async ({ page }) => {
    await page.getByRole('link', { name: /Sidequests|Quests/ }).first().click();
    await expect(page).toHaveURL(/quests/);
    await expect(page.locator('body')).toContainText(/🪙/);
  });

  test('rewards page shows the catalog with claimable entries', async ({ page }) => {
    await page.getByRole('link', { name: /Belohnungen|Schatz/ }).first().click();
    // A real catalog renders reward cards with a claim control — an empty
    // page would still show the coin balance, so assert the cards themselves.
    await expect(page.locator('.list-card, .reward-card, article').first()).toBeVisible();
    await expect(page.getByRole('button').filter({ hasText: /🪙|Wünschen|Einlösen|Kaufen/i }).first()).toBeVisible();
  });

  test('journal shows the child history', async ({ page }) => {
    await page.getByRole('link', { name: /Journal|Tagebuch/ }).first().click();
    await expect(page.locator('body')).toContainText(/✨|🪙/);
  });

  test('child never sees parent navigation', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'Genehmigungen' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Vorlagen' })).toHaveCount(0);
  });

  test('child cannot open parent routes', async ({ page }) => {
    const response = await page.goto('/parent');
    expect(response?.url()).toMatch(/login/);
  });
});

test.describe('mobile child experience', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'phone-specific game UI contract');
    await kidLoginEmma(page);
  });

  test('bottom navigation fits, marks the active screen, and keeps large touch targets', async ({ page }) => {
    const items = page.locator('.kid-nav-item');
    await expect(items).toHaveCount(5);
    await expect(items.first()).toHaveAttribute('aria-current', 'page');

    const boxes = await items.evaluateAll((links) => links.map((link) => {
      const box = link.getBoundingClientRect();
      return { left: box.left, right: box.right, width: box.width, height: box.height };
    }));
    const viewportWidth = await page.evaluate(() => document.documentElement.clientWidth);

    for (const box of boxes) {
      expect(box.left).toBeGreaterThanOrEqual(0);
      expect(box.right).toBeLessThanOrEqual(viewportWidth);
      expect(box.width).toBeGreaterThanOrEqual(44);
      expect(box.height).toBeGreaterThanOrEqual(48);
    }
  });

  test('home, rewards, journal, and settings never overflow the phone viewport', async ({ page }) => {
    const expectNoHorizontalOverflow = async (): Promise<void> => {
      const dimensions = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        offenders: Array.from(document.querySelectorAll<HTMLElement>('body *'))
          .map((element) => ({
            selector: `${element.tagName.toLowerCase()}.${element.className}`,
            left: element.getBoundingClientRect().left,
            right: element.getBoundingClientRect().right,
          }))
          .filter((box) => box.left < -0.5 || box.right > document.documentElement.clientWidth + 0.5)
          .slice(0, 8),
      }));
      expect(dimensions.scrollWidth, JSON.stringify(dimensions.offenders)).toBeLessThanOrEqual(dimensions.clientWidth);
    };

    await expectNoHorizontalOverflow();
    for (const path of ['/kid/rewards', '/kid/journal', '/kid/settings']) {
      await page.goto(path);
      await expectNoHorizontalOverflow();
    }
  });
});
