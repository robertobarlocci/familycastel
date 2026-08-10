import { expect, test } from '@playwright/test';
import { kidLoginEmma, parentLogin, logout, PARENT_PASSWORD } from './helpers';

/**
 * "Stay signed in" — the behavioural proof.
 *
 * The claim is that closing the browser does not sign the family out. A browser
 * discards session cookies on close and keeps persistent ones, so the honest
 * test is to build a NEW context carrying only `fc_remember` and assert it
 * lands authenticated. Asserting that a Set-Cookie header was sent would prove
 * nothing about what the browser does with it.
 */

const REMEMBER = 'fc_remember';

async function rememberCookie(context: import('@playwright/test').BrowserContext) {
  const all = await context.cookies();
  return all.find((c) => c.name === REMEMBER);
}

test.describe('persistent login', () => {
  test('a parent stays signed in after the browser is closed', async ({ page, browser, baseURL }) => {
    await parentLogin(page);

    const cookie = await rememberCookie(page.context());
    expect(cookie, 'login must issue a remember cookie').toBeTruthy();
    expect(cookie!.httpOnly, 'the cookie must be unreadable from JavaScript').toBe(true);
    // Persistent, not a session cookie: expiry is what survives a browser close.
    expect(cookie!.expires).toBeGreaterThan(Date.now() / 1000 + 300 * 86400);

    // A brand-new browser context = a browser that was closed and reopened.
    // Only the persistent cookie survives; the session cookie is gone.
    const fresh = await browser.newContext({ baseURL });
    await fresh.addCookies([{ ...cookie!, name: REMEMBER }]);
    const restored = await fresh.newPage();
    await restored.goto('/parent');

    await expect(restored).toHaveURL(/\/parent/);
    await expect(restored.getByRole('heading', { name: /Hallo/ })).toBeVisible();

    await fresh.close();
  });

  test('a child stays signed in after the browser is closed', async ({ page, browser, baseURL }) => {
    await kidLoginEmma(page);

    const cookie = await rememberCookie(page.context());
    expect(cookie).toBeTruthy();

    const fresh = await browser.newContext({ baseURL });
    await fresh.addCookies([{ ...cookie!, name: REMEMBER }]);
    const restored = await fresh.newPage();
    await restored.goto('/kid');

    await expect(restored).toHaveURL(/\/kid(\/|$)/);
    await expect(restored.locator('body')).toContainText(/Level/);

    await fresh.close();
  });

  /**
   * Opening the wrong door is a mistake, not a security event. A signed-in
   * parent tapping a /kid link hits the child guard; that guard must not throw
   * away the parent's 400-day login on the way to redirecting them.
   */
  test('following a link into the other area does not destroy the login', async ({ page }) => {
    await parentLogin(page);
    const before = await rememberCookie(page.context());

    await page.goto('/kid');
    await expect(page).toHaveURL(/\/kid\/login/);

    const after = await rememberCookie(page.context());
    expect(after, 'the parent token must survive a wrong-area visit').toBeTruthy();
    expect(after!.value).toBe(before!.value);

    // And it still works.
    await page.goto('/parent');
    await expect(page.getByRole('heading', { name: /Hallo/ })).toBeVisible();
  });

  test('the remember cookie is never visible to JavaScript', async ({ page }) => {
    await parentLogin(page);
    const visible = await page.evaluate(() => document.cookie);
    expect(visible).not.toContain(REMEMBER);
  });

  test('signing out makes the device forget, and the old cookie is dead', async ({ page, browser, baseURL }) => {
    await parentLogin(page);
    const cookie = await rememberCookie(page.context());
    expect(cookie).toBeTruthy();

    await logout(page);
    await expect(page).toHaveURL(/\/login/);
    expect(await rememberCookie(page.context()), 'logout must clear the cookie').toBeFalsy();

    // Replaying the value a thief could have copied must not work either:
    // logout revokes the token server-side, not just in this browser.
    const fresh = await browser.newContext({ baseURL });
    await fresh.addCookies([{ ...cookie!, name: REMEMBER }]);
    const replay = await fresh.newPage();
    await replay.goto('/parent');
    await expect(replay).toHaveURL(/\/login/);

    await fresh.close();
  });
});

/**
 * A restored session is convenient, not privileged: the operations that can
 * wipe or hand over the whole installation still want the password (INV-007).
 */
test.describe('step-up for sensitive operations', () => {
  // /diagnostics answers with a file download, so it is probed at the request
  // level: page.goto() would abort with "Download is starting" and tell us
  // nothing about authorisation. maxRedirects:0 makes the gate observable —
  // 302 means blocked, 200 means allowed.
  const probeDiagnostics = (page: import('@playwright/test').Page) =>
    page.request.get('/parent/settings/diagnostics', { maxRedirects: 0 });

  test('a restored session must confirm the password for diagnostics', async ({ page, browser, baseURL }) => {
    await parentLogin(page);
    const cookie = await rememberCookie(page.context());

    const fresh = await browser.newContext({ baseURL });
    await fresh.addCookies([{ ...cookie!, name: REMEMBER }]);
    const restored = await fresh.newPage();

    // Everyday use is unaffected…
    await restored.goto('/parent/settings/backups');
    await expect(restored).toHaveURL(/\/parent\/settings\/backups/);

    // …but a disclosing operation is not.
    const blocked = await probeDiagnostics(restored);
    expect(blocked.status()).toBe(302);
    expect(blocked.headers()['location']).toContain('confirm-password');

    await fresh.close();
  });

  test('confirming the password unlocks the operation', async ({ page, browser, baseURL }) => {
    await parentLogin(page);
    const cookie = await rememberCookie(page.context());

    const fresh = await browser.newContext({ baseURL });
    await fresh.addCookies([{ ...cookie!, name: REMEMBER }]);
    const restored = await fresh.newPage();

    expect((await probeDiagnostics(restored)).status()).toBe(302);

    await restored.goto('/parent/confirm-password');
    await restored.getByRole('textbox', { name: /Passwort/ }).fill(PARENT_PASSWORD);

    // Confirming sends the parent back to what they originally asked for, and
    // that target answers with a file — so the download IS the proof the
    // operation was authorised. (The page URL stays put: a download does not
    // navigate, which is why asserting on the URL here would be wrong.)
    const [download] = await Promise.all([
      restored.waitForEvent('download'),
      restored.getByRole('button', { name: 'Bestätigen' }).click(),
    ]);
    expect(download.suggestedFilename()).toBeTruthy();

    // And the gate stays open for the rest of the window.
    expect((await probeDiagnostics(restored)).status()).toBe(200);

    await fresh.close();
  });

  test('a password-login session is already confirmed', async ({ page }) => {
    await parentLogin(page);
    expect((await probeDiagnostics(page)).status()).toBe(200);
  });
});

test.describe('cookie notice', () => {
  /**
   * The kid app's bottom dock is fixed. A bottom-anchored notice covered it and
   * ate every tap on Journal/Quests/Rewards — a real regression that kid.spec.ts
   * caught. Assert the two never overlap rather than trusting a CSS offset.
   */
  test('never covers the child navigation dock', async ({ page }) => {
    await kidLoginEmma(page);

    const notice = page.locator('.cookie-notice');
    await expect(notice).toBeVisible();

    const noticeBox = await notice.boundingBox();
    const dockBox = await page.locator('.kid-nav').boundingBox();
    expect(noticeBox && dockBox).toBeTruthy();
    // No vertical overlap at all.
    expect(noticeBox!.y + noticeBox!.height).toBeLessThanOrEqual(dockBox!.y + 1);

    // And the dock is genuinely clickable with the notice on screen.
    await page.getByRole('link', { name: /Journal|Tagebuch/ }).first().click();
    await expect(page).toHaveURL(/\/kid\/journal/);
  });

  test('appears once and stays dismissed', async ({ page }) => {
    await page.goto('/login');
    const notice = page.locator('.cookie-notice');
    await expect(notice).toBeVisible();

    await notice.getByRole('button').click();

    await expect(page.locator('.cookie-notice')).toHaveCount(0);
    await page.goto('/login');
    await expect(page.locator('.cookie-notice')).toHaveCount(0);
  });
});
