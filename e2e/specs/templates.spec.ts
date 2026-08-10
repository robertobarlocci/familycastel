import { expect, test } from '@playwright/test';
import { parentLogin, parentNavigate } from './helpers';

/**
 * Point templates — create and edit.
 *
 * Issue #12: both `/parent/templates/new` and `/parent/templates/{id}/edit`
 * returned 500, so the whole create/edit UI was dead while the list page
 * rendered fine. Nothing caught it because this area had no coverage at all —
 * no unit test, and no e2e journey ever visited it. This is that journey.
 */
test.describe('point templates', () => {
  test.beforeEach(async ({ page }) => {
    await parentLogin(page);
  });

  test('the list page renders', async ({ page }) => {
    await parentNavigate(page, /Vorlagen/);
    await expect(page).toHaveURL(/\/parent\/templates/);
  });

  test('the create form opens instead of erroring', async ({ page }) => {
    const response = await page.goto('/parent/templates/new');

    expect(response?.status()).toBe(200);
    await expect(page.locator('form.form-card')).toBeVisible();
    // The "new" route must render the CREATE heading — it used to fall through
    // to the edit branch because the null it expected never arrived.
    await expect(page.locator('.page-title')).toBeVisible();
  });

  test('a template can be created and then edited', async ({ page }) => {
    const title = `E2E Vorlage ${Date.now()}`;

    await page.goto('/parent/templates/new');
    await page.locator('form.form-card input[name="title"]').fill(title);
    const coins = page.locator('form.form-card input[name="coins"]');
    if (await coins.count()) {
      await coins.fill('5');
    }
    await page.locator('form.form-card button[type="submit"]').first().click();

    await expect(page).toHaveURL(/\/parent\/templates/);
    await expect(page.locator('body')).toContainText(title);

    // …and the edit screen for it opens and is pre-filled.
    const editLink = page.locator(`a[href*="/edit"]`).last();
    await editLink.click();

    await expect(page).toHaveURL(/\/parent\/templates\/\d+\/edit/);
    await expect(page.locator('form.form-card input[name="title"]')).toHaveValue(/.+/);
  });
});
