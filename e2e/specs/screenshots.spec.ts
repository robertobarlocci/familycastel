import { test, expect, Page } from '@playwright/test';
import { parentLogin, kidLoginEmma } from './helpers';

/**
 * Generates docs/screenshots/* from the polished demo data (plan §14).
 * Runs in both projects: mobile (390×844) and desktop (1280×800); files are
 * suffixed with the project name.
 */
const OUT = '../docs/screenshots';

async function shoot(page: Page, name: string, project: string): Promise<void> {
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: `${OUT}/${name}-${project}.png`, fullPage: false });
}

test.describe('screenshots', () => {
  test('kid home fantasy world (Emma)', async ({ page }, testInfo) => {
    await kidLoginEmma(page);
    await shoot(page, '01-kid-home-fantasy', testInfo.project.name);
  });

  test('kid sidequest board', async ({ page }, testInfo) => {
    await kidLoginEmma(page);
    await page.getByRole('link', { name: /Sidequests|Quests/ }).first().click();
    await shoot(page, '02-kid-sidequests', testInfo.project.name);
  });

  test('kid rewards', async ({ page }, testInfo) => {
    await kidLoginEmma(page);
    await page.getByRole('link', { name: /Belohnungen|Schatz/ }).first().click();
    await shoot(page, '03-kid-rewards', testInfo.project.name);
  });

  test('kid journal', async ({ page }, testInfo) => {
    await kidLoginEmma(page);
    await page.getByRole('link', { name: /Journal|Tagebuch/ }).first().click();
    await shoot(page, '04-kid-journal', testInfo.project.name);
  });

  test('kid login hero picker', async ({ page }, testInfo) => {
    await page.goto('/kid/login');
    await expect(page.getByRole('heading', { name: 'Wer spielt heute?' })).toBeVisible();
    await shoot(page, '05-kid-login', testInfo.project.name);
  });

  test('parent dashboard', async ({ page }, testInfo) => {
    await parentLogin(page);
    await shoot(page, '06-parent-dashboard', testInfo.project.name);
  });

  test('parent child screen with quick award', async ({ page }, testInfo) => {
    await parentLogin(page);
    await page.getByRole('link', { name: /Emma/ }).first().click();
    await shoot(page, '07-parent-child-screen', testInfo.project.name);
  });

  test('parent approvals', async ({ page }, testInfo) => {
    await parentLogin(page);
    await page.getByRole('link', { name: /Genehmigungen/ }).click();
    await shoot(page, '08-parent-approvals', testInfo.project.name);
  });

  test('installer welcome (public entry)', async ({ page }, testInfo) => {
    // On an installed system /install redirects — capture the login instead,
    // which is the real first-visit page.
    await page.goto('/login');
    await shoot(page, '09-parent-login', testInfo.project.name);
  });

  test('parent system status', async ({ page }, testInfo) => {
    await parentLogin(page);
    await page.goto('/parent/settings/status');
    await shoot(page, '10-parent-system-status', testInfo.project.name);
  });
});
