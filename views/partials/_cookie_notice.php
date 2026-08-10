<?php
/**
 * One-time informational cookie notice.
 *
 * Deliberately NOT an accept/decline gate: the only cookies this app sets are
 * its own login cookies, so there is no consent to collect and offering a
 * "decline" that would break signing in would be a false choice.
 *
 * A plain CSRF-protected form, not JavaScript: the CSP forbids inline script,
 * and this keeps working with JS disabled.
 *
 * Not included from layouts/install.php — that layout does not load app.css, so
 * it would render unstyled, and there is no login cookie during installation.
 */
if (($_COOKIE['fc_cookie_notice'] ?? '') === '1') {
    return;
}
$noticeReturn = \FamilyCastel\Core\Router::resolvePath(
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    \FamilyCastel\Core\BasePath::get(),
    $_GET
);
?>
<aside class="cookie-notice" role="note" aria-label="<?= eattr(t('cookie.title')) ?>">
    <div class="cookie-notice-text">
        <strong><?= e(t('cookie.title')) ?></strong>
        <span><?= e(t('cookie.body')) ?></span>
    </div>
    <form method="post" action="<?= e(url('/cookie-notice')) ?>" class="cookie-notice-actions">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="return" value="<?= eattr($noticeReturn) ?>">
        <button type="submit" class="btn-primary"><?= e(t('cookie.accept')) ?></button>
    </form>
</aside>
