import { Page, expect } from '@playwright/test';

export const PARENT_USER = process.env.FC_PARENT_USER ?? 'demo-parent';
export const PARENT_PASSWORD = process.env.FC_PARENT_PASSWORD ?? 'Schloss-Demo-2026';
export const EMMA_PIN = '1234';

export async function parentLogin(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByRole('textbox', { name: /Benutzername/ }).fill(PARENT_USER);
  await page.getByRole('textbox', { name: /Passwort/ }).fill(PARENT_PASSWORD);
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await expect(page).toHaveURL(/\/parent/);
}

export async function kidLoginEmma(page: Page): Promise<void> {
  await page.goto('/kid/login');
  await page.getByRole('button', { name: /Emma/ }).click();
  const pinPanel = page.locator('.kid-pin.open');
  await pinPanel.locator('input[name="pin"]').fill(EMMA_PIN);
  await pinPanel.getByRole('button', { name: "Los geht's!" }).click();
  await expect(page).toHaveURL(/\/kid(\/|$)/, { timeout: 10_000 });
}

export async function logout(page: Page): Promise<void> {
  // Logout is a CSRF-protected POST — always use the layout's form button.
  await openParentMenu(page);
  await page.getByRole('button', { name: 'Abmelden' }).first().click();
}

export async function openParentMenu(page: Page): Promise<void> {
  const toggle = page.locator('.topbar-menu-toggle');
  if (await toggle.isVisible() && await toggle.getAttribute('aria-expanded') !== 'true') {
    await toggle.click();
  }
}

export async function parentNavigate(page: Page, name: string | RegExp): Promise<void> {
  await openParentMenu(page);
  await page.getByRole('link', { name }).click();
}
