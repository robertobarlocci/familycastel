import path from 'node:path';
import { test, expect, Page } from '@playwright/test';
import { parentLogin, parentNavigate, kidLoginEmma } from './helpers';

const FIXTURE = path.join(__dirname, '..', 'fixtures', 'penalty.png');

/** Record a penalty through the real form, optionally attaching the fixture photo. */
async function recordPenalty(
  page: Page,
  opts: { child: string; reason: string; coins: number; comment?: string; photo?: boolean },
): Promise<void> {
  await page.goto('/parent/penalties');
  await page.selectOption('#penalty-child', { label: opts.child });
  await page.fill('#penalty-reason', opts.reason);
  await page.fill('#penalty-coins', String(opts.coins));
  if (opts.comment) {
    await page.fill('#penalty-comment', opts.comment);
  }
  if (opts.photo) {
    await page.setInputFiles('#penalty-photo', FIXTURE);
  }
  await page.getByRole('button', { name: 'Minuspunkt eintragen' }).click();
  await expect(page.locator('.flash-success')).toBeVisible();
}

test.describe('minus points (parent side)', () => {
  test.beforeEach(async ({ page }) => {
    await parentLogin(page);
  });

  test('the navbar entry opens the page — on desktop and through the burger menu', async ({ page }) => {
    // parentNavigate() opens the burger first when it is visible, so this
    // covers the mobile project's hamburger and the desktop navbar in one go.
    await parentNavigate(page, /Minuspunkte/);

    await expect(page).toHaveURL(/\/parent\/penalties$/);
    await expect(page.getByRole('heading', { name: /Minuspunkte/ })).toBeVisible();
    await expect(
      page.locator('.topbar-nav a[aria-current="page"]', { hasText: 'Minuspunkte' }),
    ).toHaveCount(1);
  });

  test('a penalty without a photo deducts exactly the coins entered', async ({ page }) => {
    await page.goto('/parent/child/1');
    const before = Number((((await page.locator('body').textContent()) ?? '').match(/🪙\s*(\d+)/) ?? [])[1]);
    expect(Number.isNaN(before)).toBe(false);

    await recordPenalty(page, { child: 'Emma', reason: 'E2E ohne Foto', coins: 3 });

    await page.goto('/parent/child/1');
    const after = Number((((await page.locator('body').textContent()) ?? '').match(/🪙\s*(\d+)/) ?? [])[1]);
    expect(after).toBe(before - 3);
  });

  test('a penalty with a photo shows a thumbnail that really loads', async ({ page }) => {
    const reason = `E2E mit Foto ${Date.now()}`;
    await recordPenalty(page, { child: 'Emma', reason, coins: 1, comment: 'E2E Kommentar', photo: true });

    const card = page.locator('.penalty-card', { hasText: reason }).first();
    await expect(card).toBeVisible();

    const thumb = card.locator('img.penalty-thumb');
    await expect(thumb).toBeVisible();

    // Visible is not the same as loaded: a broken image is still "visible".
    await expect
      .poll(async () => thumb.evaluate((img: HTMLImageElement) => img.naturalWidth))
      .toBeGreaterThan(0);

    const src = await thumb.getAttribute('src');
    const response = await page.request.get(src!);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toMatch(/^image\//);
    expect(response.headers()['cache-control']).toContain('private');
  });

  test('the photo is not reachable directly over HTTP', async ({ page }) => {
    await recordPenalty(page, { child: 'Emma', reason: `E2E Schutz ${Date.now()}`, coins: 1, photo: true });

    // storage/ is denied by a per-directory guard, so the bytes are only ever
    // available through the guarded PHP route.
    const direct = await page.request.get('/storage/uploads/penalties/', { maxRedirects: 0 });
    expect(direct.status()).toBe(403);
  });

  test('an oversized coin amount is refused by the server, not just the form', async ({ page }) => {
    await page.goto('/parent/penalties');

    // Bypass the browser's max="999" exactly as a forged POST would.
    const status = await page.evaluate(async () => {
      const form = document.querySelector('form.penalty-form') as HTMLFormElement;
      const data = new FormData(form);
      data.set('coins', '100000');
      data.set('reason', 'E2E Grenzwert');
      const response = await fetch(form.action, { method: 'POST', body: data, redirect: 'follow' });
      return response.status;
    });
    expect(status).toBe(200);

    await page.goto('/parent/penalties');
    await expect(page.locator('.penalty-card', { hasText: 'E2E Grenzwert' })).toHaveCount(0);
  });

  test('the page never overflows the phone viewport', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'phone-width layout guard');

    // A long, realistic reason — deliberately NOT one unbroken 160-character
    // word. Ledger history is permanent (INV-001), so anything this test writes
    // stays in the demo database forever and is rendered by every other screen
    // that lists transactions. An unbroken string here would plant a permanent
    // failure in an unrelated spec rather than testing this page.
    // The unbroken-word case is tracked separately (`.history-title` has no
    // overflow-wrap; see the follow-up issue).
    await recordPenalty(page, {
      child: 'Emma',
      reason: `E2E ${'sehr langer Grund mit vielen Woertern '.repeat(4)}`.slice(0, 185),
      coins: 1,
      photo: true,
    });

    const dimensions = await page.evaluate(() => ({
      clientWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
    }));
    expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth);
  });
});

test.describe('minus points (child side)', () => {
  test('the child sees the penalty and its photo in the journal', async ({ page }) => {
    const reason = `E2E Kindsicht ${Date.now()}`;

    await parentLogin(page);
    await recordPenalty(page, { child: 'Emma', reason, coins: 1, photo: true });

    await page.goto('/logout-check-not-needed').catch(() => undefined);
    await kidLoginEmma(page);
    await page.goto('/kid/journal?filter=deducted');

    const entry = page.locator('.journal-entry', { hasText: reason }).first();
    await expect(entry).toBeVisible();

    const photo = entry.locator('img.journal-photo');
    await expect(photo).toBeVisible();
    await expect
      .poll(async () => photo.evaluate((img: HTMLImageElement) => img.naturalWidth))
      .toBeGreaterThan(0);
  });

  test('a child cannot fetch a sibling photo', async ({ page }) => {
    // Give Noah a penalty with a photo, then ask for it as Emma.
    await parentLogin(page);
    const reason = `E2E Noah ${Date.now()}`;
    await recordPenalty(page, { child: 'Noah', reason, coins: 1, photo: true });

    const noahSrc = await page
      .locator('.penalty-card', { hasText: reason })
      .first()
      .locator('img.penalty-thumb')
      .getAttribute('src');
    expect(noahSrc).toBeTruthy();

    const transactionId = noahSrc!.split('/').pop()!;

    await kidLoginEmma(page);
    const response = await page.request.get(`/kid/journal/photo/${transactionId}`, { maxRedirects: 0 });

    // A redirect, not the bytes — and the same answer as for an id that does
    // not exist, so nothing is disclosed either way.
    expect(response.status()).toBe(302);
    expect(response.headers()['content-type'] ?? '').not.toMatch(/^image\//);
  });
});
