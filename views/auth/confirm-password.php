<section class="install-card">
    <?php if (!empty($error)): ?>
        <div class="install-error" role="alert">⚠️ <?= e($error) ?></div>
    <?php endif; ?>
    <h2><?= e(t('auth.confirm_title')) ?></h2>
    <p class="lead"><?= e(t('auth.confirm_lead')) ?></p>
    <?php /* No username field: the account comes from the session, and the
             verified id is checked back against it. */ ?>
    <form method="post" action="<?= e(url('/parent/confirm-password')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <div class="field">
            <label for="password"><?= e(t('auth.password')) ?></label>
            <input id="password" name="password" type="password" required
                   autocomplete="current-password" autofocus>
        </div>
        <button type="submit" class="btn-primary"><?= e(t('auth.confirm_button')) ?></button>
    </form>
    <p class="auth-switch"><a href="<?= e(url('/parent')) ?>"><?= e(t('common.cancel')) ?></a></p>
</section>
