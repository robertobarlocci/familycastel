<?php $db = $db ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'password' => '', 'prefix' => '']; ?>
<section class="install-card">
    <?php require __DIR__ . '/_progress.php'; ?>
    <h2><?= e(t('install.step_database')) ?></h2>
    <p class="lead"><?= e(t('install.database_lead')) ?></p>
    <form method="post" action="<?= e(url('/install')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="step" value="database">
        <div class="field-row">
            <div class="field grow">
                <label for="db_host"><?= e(t('install.db_host')) ?></label>
                <input id="db_host" name="db_host" type="text" required value="<?= eattr($db['host']) ?>">
            </div>
            <div class="field">
                <label for="db_port"><?= e(t('install.db_port')) ?></label>
                <input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= eattr((string) $db['port']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="db_name"><?= e(t('install.db_name')) ?></label>
            <input id="db_name" name="db_name" type="text" required value="<?= eattr($db['name']) ?>">
        </div>
        <div class="field">
            <label for="db_user"><?= e(t('install.db_user')) ?></label>
            <input id="db_user" name="db_user" type="text" required value="<?= eattr($db['user']) ?>">
        </div>
        <div class="field">
            <label for="db_password"><?= e(t('install.db_password')) ?></label>
            <input id="db_password" name="db_password" type="password" autocomplete="new-password">
        </div>
        <div class="field">
            <label for="db_prefix"><?= e(t('install.db_prefix')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
            <input id="db_prefix" name="db_prefix" type="text" value="<?= eattr($db['prefix']) ?>">
        </div>
        <button type="submit" class="btn-primary"><?= e(t('install.database_test')) ?></button>
    </form>
</section>
