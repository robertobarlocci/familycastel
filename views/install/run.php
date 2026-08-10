<section class="install-card">
    <?php require __DIR__ . '/_progress.php'; ?>
    <h2><?= e(t('install.step_run')) ?></h2>
    <p class="lead"><?= e(t('install.run_lead')) ?></p>
    <dl class="install-summary">
        <dt><?= e(t('install.step_family')) ?></dt>
        <dd><?= e($family['name'] ?? '') ?> · <?= e(strtoupper((string) ($family['locale'] ?? ''))) ?> · <?= e($family['timezone'] ?? '') ?></dd>
        <dt><?= e(t('install.step_parent')) ?></dt>
        <dd><?= e($parent['name'] ?? '') ?> (<?= e($parent['username'] ?? '') ?>)</dd>
        <dt><?= e(t('install.step_database')) ?></dt>
        <dd><?= e(($db['name'] ?? '') . ' @ ' . ($db['host'] ?? '')) ?></dd>
    </dl>
    <form method="post" action="<?= e(url('/install')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="step" value="run">
        <button type="submit" class="btn-primary btn-big">🏰 <?= e(t('install.run_button')) ?></button>
    </form>
</section>
