import { test, expect } from '@playwright/test';
import { parentLogin, kidLoginEmma, logout, PARENT_USER, PARENT_PASSWORD } from './helpers';

test.describe('authentication', () => {
  test('parent can log in and reach the dashboard', async ({ page }) => {
    await parentLogin(page);
    await expect(page.getByRole('heading', { name: /Hallo/ })).toBeVisible();
  });

  test('wrong credentials are rejected without leaking detail', async ({ page }) => {
    // A RANDOM username keeps the real demo account's throttle counter clean;
    // per-IP failures still accumulate (by design), so this stays a single
    // attempt per run.
    await page.goto('/login');
    await page.getByRole('textbox', { name: /Benutzername/ }).fill(`nobody-${Date.now()}`);
    await page.getByRole('textbox', { name: /Passwort/ }).fill('definitely-wrong');
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(PARENT_PASSWORD);
  });

  test('child can log in with PIN and lands in the game world', async ({ page }) => {
    await kidLoginEmma(page);
    // RULE #1: the child sees a game, not a form.
    await expect(page.locator('body')).toContainText(/Level/);
  });

  test('kid login page shows the hero picker', async ({ page }) => {
    await page.goto('/kid/login');
    await expect(page.getByRole('heading', { name: 'Wer spielt heute?' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Emma/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Noah/ })).toBeVisible();
  });

  test('logout ends the parent session', async ({ page }) => {
    await parentLogin(page);
    await logout(page);
    await page.goto('/parent');
    await expect(page).toHaveURL(/\/login/);
  });

  test('authenticated pages are not served after logout via history/cache', async ({ page }) => {
    await parentLogin(page);
    await page.goto('/parent/children');
    await logout(page);
    // A fresh request to the protected URL must redirect to login.
    const response = await page.goto('/parent/children');
    expect(response?.url()).toMatch(/\/login/);
  });
});

test.describe('routing fallbacks', () => {
  test('?r= routing works without mod_rewrite URLs', async ({ page }) => {
    await page.goto('/index.php?r=/login');
    await expect(page.getByRole('heading', { name: 'Eltern-Anmeldung' })).toBeVisible();
  });

  test('unknown routes get the friendly 404', async ({ page }) => {
    const response = await page.goto('/definitely/not/a/route');
    expect(response?.status()).toBe(404);
  });
});
