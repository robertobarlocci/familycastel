<?php $parent = $parent ?? ['name' => '', 'username' => '']; ?>
<section class="install-card">
    <?php require __DIR__ . '/_progress.php'; ?>
    <h2><?= e(t('install.step_parent')) ?></h2>
    <p class="lead"><?= e(t('install.parent_lead')) ?></p>
    <form method="post" action="<?= e(url('/install')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="step" value="parent">
        <div class="field">
            <label for="parent_name"><?= e(t('install.parent_name')) ?></label>
            <input id="parent_name" name="parent_name" type="text" required maxlength="100"
                   value="<?= eattr($parent['name']) ?>">
        </div>
        <div class="field">
            <label for="parent_username"><?= e(t('install.parent_username')) ?></label>
            <input id="parent_username" name="parent_username" type="text" required maxlength="190"
                   autocomplete="username" value="<?= eattr($parent['username']) ?>">
        </div>
        <div class="field">
            <label for="parent_password"><?= e(t('install.parent_password')) ?></label>
            <input id="parent_password" name="parent_password" type="password" required
                   minlength="10" autocomplete="new-password">
            <small><?= e(t('install.parent_password_hint')) ?></small>
        </div>
        <div class="field">
            <label for="parent_password_confirm"><?= e(t('install.parent_password_confirm')) ?></label>
            <input id="parent_password_confirm" name="parent_password_confirm" type="password" required
                   minlength="10" autocomplete="new-password">
        </div>
        <label class="checkbox">
            <input type="checkbox" name="demo_data" value="1">
            🏰 <?= e(t('install.demo_label')) ?>
        </label>
        <button type="submit" class="btn-primary"><?= e(t('install.continue')) ?></button>
    </form>
</section>
