<section class="install-card">
    <?php require __DIR__ . '/_progress.php'; ?>
    <h2><?= e(t('install.welcome_title')) ?></h2>
    <p class="lead"><?= e(t('install.welcome_lead')) ?></p>
    <ul class="install-features">
        <li>🪙 <?= e(t('install.welcome_feature_coins')) ?></li>
        <li>🗡️ <?= e(t('install.welcome_feature_sidequests')) ?></li>
        <li>🏆 <?= e(t('install.welcome_feature_milestones')) ?></li>
        <li>🔒 <?= e(t('install.welcome_feature_private')) ?></li>
    </ul>
    <form method="post" action="<?= e(url('/install')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="step" value="welcome">
        <button type="submit" class="btn-primary"><?= e(t('install.welcome_start')) ?></button>
    </form>
</section>
