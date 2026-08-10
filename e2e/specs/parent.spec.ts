import { test, expect } from '@playwright/test';
import { parentLogin } from './helpers';

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
    await page.getByRole('link', { name: /Genehmigungen/ }).click();
    await expect(page).toHaveURL(/approvals/);
    await expect(
      page.getByRole('heading', { name: /Genehmigungen|Entscheidungen/ })
    ).toBeVisible();
  });

  test('sidequest admin page lists quests and the create form works', async ({ page }) => {
    await page.getByRole('link', { name: 'Sidequests' }).click();
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
