<section class="install-card">
    <?php if (!empty($error)): ?>
        <div class="install-error" role="alert">⚠️ <?= e($error) ?></div>
    <?php endif; ?>
    <h2><?= e(t('auth.login_title')) ?></h2>
    <p class="lead"><?= e(t('auth.login_lead')) ?></p>
    <form method="post" action="<?= e(url('/login')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <div class="field">
            <label for="username"><?= e(t('auth.username')) ?></label>
            <input id="username" name="username" type="text" required autocomplete="username"
                   value="<?= eattr($username ?? '') ?>" autofocus>
        </div>
        <div class="field">
            <label for="password"><?= e(t('auth.password')) ?></label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-primary"><?= e(t('auth.login_button')) ?></button>
    </form>
    <p class="auth-switch"><a href="<?= e(url('/kid/login')) ?>">🧒 <?= e(t('auth.switch_to_kid')) ?></a></p>
</section>
