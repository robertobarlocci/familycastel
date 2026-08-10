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
