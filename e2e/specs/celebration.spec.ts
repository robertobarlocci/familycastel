import { test, expect, Page } from '@playwright/test';
import { parentLogin, kidLoginEmma } from './helpers';

/**
 * Feedback for the child: confetti for anything positive, a dark raining cloud
 * for anything negative, and a "something new" mark on the Journal.
 *
 * Note on fixtures: the ledger is permanent by design (INV-001), so every award
 * or penalty these tests record stays in the demo family forever and is
 * rendered by every other screen that lists transactions. Titles are therefore
 * short, realistic and breakable — never a long unbroken string, which is how
 * an earlier spec planted a permanent failure in a file it did not touch
 * (LESSONS 2026-08-14).
 */

/** Award Coins through the "Eigene Aktion" form on the parent's child screen. */
async function customAward(page: Page, title: string, coins: number, xp = 0): Promise<void> {
  await page.goto('/parent/child/1');
  const form = page.locator('form[action*="/custom"]');
  await form.locator('#title').fill(title);
  await form.locator('#coins').fill(String(coins));
  await form.locator('#xp').fill(String(xp));
  await form.getByRole('button', { name: 'Eintragen' }).click();
  await expect(page.locator('.flash-success')).toBeVisible();
}

/** Record a Minuspunkt (no photo — the photo path is penalties.spec.ts's job). */
async function penalty(page: Page, reason: string, coins: number): Promise<void> {
  await page.goto('/parent/penalties');
  await page.selectOption('#penalty-child', { label: 'Emma' });
  await page.fill('#penalty-reason', reason);
  await page.fill('#penalty-coins', String(coins));
  await page.getByRole('button', { name: 'Minuspunkt eintragen' }).click();
  await expect(page.locator('.flash-success')).toBeVisible();
}

/** Land on the child's castle with a clean slate: nothing pending. */
async function drainAsEmma(page: Page): Promise<void> {
  await kidLoginEmma(page);
  await page.goto('/kid');            // consumes any queued celebration
  await page.goto('/kid/journal');    // clears the badge
}

test.describe('the child sees that something happened', () => {
  test('an "Eigene Aktion" award produces confetti — not only Sidequest approvals', async ({ page }) => {
    await parentLogin(page);
    await drainAsEmma(page);

    await parentLogin(page);
    await customAward(page, `Tisch gedeckt ${Date.now()}`, 3, 3);

    // kidLoginEmma() lands ON /kid, and that render is the one that consumes
    // the queue — navigating there again would look at a second, empty render.
    await kidLoginEmma(page);

    await expect(page.locator('[data-celebrate-events]')).toHaveCount(1);

    // The achievement marker must stay reserved for achievements: it also
    // drives the fanfare in sounds.js, and this change adds no sound.
    await expect(page.locator('[data-celebrate]')).toHaveCount(0);

    // Present in the DOM is not the same as rendered: canvas-confetti creates
    // its own canvas only when it actually fires.
    await expect.poll(
      async () => page.evaluate(() => document.querySelectorAll('canvas').length),
      { timeout: 5000 },
    ).toBeGreaterThan(0);
  });

  test('a Minuspunkt produces a dark raining cloud that ends by itself', async ({ page }) => {
    await parentLogin(page);
    await drainAsEmma(page);

    await parentLogin(page);
    await penalty(page, `Zaehne nicht geputzt ${Date.now()}`, 1);

    await kidLoginEmma(page);   // landing on /kid is what consumes the queue

    const rain = page.locator('.rain-fx');
    await expect(rain).toHaveCount(1);

    // It is decoration: it must never be able to intercept a tap.
    await expect(rain).toHaveCSS('pointer-events', 'none');

    // And it must clear itself — CSS ends the scene invisible, celebrate.js
    // removes the node at 3s. Either alone would be a single point of failure.
    await expect(rain).toHaveCount(0, { timeout: 6000 });
  });

  test('neither effect replays on a reload', async ({ page }) => {
    await parentLogin(page);
    await drainAsEmma(page);

    await parentLogin(page);
    await customAward(page, `Muell rausgebracht ${Date.now()}`, 2, 2);

    await kidLoginEmma(page);
    await expect(page.locator('[data-celebrate-events]')).toHaveCount(1);

    await page.reload();
    await expect(page.locator('[data-celebrate-events]')).toHaveCount(0);
    await expect(page.locator('.rain-fx')).toHaveCount(0);
  });
});

test.describe('the Journal says there is something new', () => {
  test('the badge appears, names itself, and clears when the Journal is opened', async ({ page }) => {
    await parentLogin(page);
    await drainAsEmma(page);

    await parentLogin(page);
    await customAward(page, `Katze gefuettert ${Date.now()}`, 2, 0);

    await kidLoginEmma(page);
    await page.goto('/kid/sidequests');   // any kid page carries the dock

    const journalLink = page.locator('.kid-nav a[href$="/kid/journal"]');
    await expect(journalLink.locator('.kid-nav-badge')).toHaveCount(1);
    await expect(journalLink).toHaveAttribute('aria-label', /Journal/);

    // Reading the Journal is what clears it.
    await journalLink.click();
    await expect(page).toHaveURL(/\/kid\/journal$/);
    await expect(page.locator('.kid-nav a[href$="/kid/journal"] .kid-nav-badge')).toHaveCount(0);

    await page.goto('/kid/rewards');
    await expect(page.locator('.kid-nav a[href$="/kid/journal"] .kid-nav-badge')).toHaveCount(0);
  });

  test('a filtered Journal view does not clear the badge', async ({ page }) => {
    await parentLogin(page);
    await drainAsEmma(page);

    await parentLogin(page);
    await customAward(page, `Blumen gegossen ${Date.now()}`, 2, 0);

    await kidLoginEmma(page);

    // The award is not a deduction, so this filter cannot show it — claiming
    // the child has seen it would hide exactly what the badge points at.
    await page.goto('/kid/journal?filter=deducted');
    await expect(page.locator('.kid-nav a[href$="/kid/journal"] .kid-nav-badge')).toHaveCount(1);

    await page.goto('/kid/journal');
    await expect(page.locator('.kid-nav a[href$="/kid/journal"] .kid-nav-badge')).toHaveCount(0);
  });
});

test.describe('the badge fits the phone dock', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'phone-width layout guard');
  });

  test('the dock still fits, keeps its touch targets, and does not clip the badge', async ({ page }) => {
    await parentLogin(page);
    await drainAsEmma(page);

    await parentLogin(page);
    // Enough events that the badge renders two digits, which is the widest it
    // gets before the "99+" sentinel.
    for (let i = 0; i < 10; i++) {
      await customAward(page, `Kleine Hilfe ${Date.now()}-${i}`, 1, 0);
    }

    await kidLoginEmma(page);
    await page.goto('/kid/rewards');

    const items = page.locator('.kid-nav-item');
    await expect(items).toHaveCount(5, 'the badge must not add a sixth dock entry');

    const badge = page.locator('.kid-nav-badge');
    await expect(badge).toHaveCount(1);

    const geometry = await page.evaluate(() => {
      const link = document.querySelector('.kid-nav a[href$="/kid/journal"]') as HTMLElement;
      const mark = link.querySelector('.kid-nav-badge') as HTMLElement;
      const l = link.getBoundingClientRect();
      const b = mark.getBoundingClientRect();
      const label = link.querySelector('span:last-child') as HTMLElement;
      return {
        contained: b.left >= l.left - 0.5 && b.right <= l.right + 0.5
          && b.top >= l.top - 0.5 && b.bottom <= l.bottom + 0.5,
        badgeVisible: b.width > 0 && b.height > 0,
        // The badge is the FIRST child so it cannot steal the label's
        // `span:last-child` rule; if it ever did, the label stops eliding.
        labelElides: getComputedStyle(label).textOverflow === 'ellipsis',
        boxes: Array.from(document.querySelectorAll('.kid-nav-item')).map((el) => {
          const r = el.getBoundingClientRect();
          return { left: r.left, right: r.right, width: r.width, height: r.height };
        }),
        viewportWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
      };
    });

    expect(geometry.badgeVisible).toBe(true);
    expect(geometry.contained, 'overflow:hidden on .kid-nav-item must not clip the badge').toBe(true);
    expect(geometry.labelElides).toBe(true);
    expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewportWidth);

    for (const box of geometry.boxes) {
      expect(box.left).toBeGreaterThanOrEqual(0);
      expect(box.right).toBeLessThanOrEqual(geometry.viewportWidth);
      expect(box.width).toBeGreaterThanOrEqual(44);
      expect(box.height).toBeGreaterThanOrEqual(48);
    }
  });
});

test.describe('the shipped assets really carry the change', () => {
  /**
   * "A shipped fix the browser never downloads is not a shipped fix"
   * (LESSONS 2026-08-10). Both files are versioned by INV-006, but the cheap
   * check is to read what the server actually returns rather than trust disk.
   */
  test('celebrate.js and kid.css are served with the new behaviour', async ({ page }) => {
    await kidLoginEmma(page);
    await page.goto('/kid');

    const sources = await page.evaluate(() =>
      Array.from(document.scripts).map((s) => s.getAttribute('src') ?? ''));
    const celebrateUrl = sources.find((s) => s.includes('celebrate.js'));
    expect(celebrateUrl, 'the kid home must load celebrate.js').toBeTruthy();
    expect(celebrateUrl).toContain('?v=');

    const js = await page.request.get(celebrateUrl!);
    expect(js.status()).toBe(200);
    const body = await js.text();
    expect(body, 'the achievement path must keep working').toContain('[data-celebrate]');
    expect(body, 'the transaction path must be live').toContain('[data-celebrate-events]');

    const css = await page.request.get(
      (await page.evaluate(() =>
        (document.querySelector('link[href*="kid.css"]') as HTMLLinkElement).getAttribute('href')))!,
    );
    expect(css.status()).toBe(200);
    const cssBody = await css.text();
    expect(cssBody).toContain('.rain-fx');
    expect(cssBody).toContain('.kid-nav-badge');
  });
});
