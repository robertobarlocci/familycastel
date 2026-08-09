<section class="install-card">
    <?php require __DIR__ . '/_progress.php'; ?>
    <h2><?= e(t('install.step_check')) ?></h2>
    <p class="lead"><?= e(t('install.check_lead')) ?></p>
    <ul class="check-list">
        <?php foreach ($checks as $check): ?>
            <li class="check-<?= e($check['level']) ?>">
                <span class="check-icon"><?= $check['level'] === 'ok' ? '✅' : ($check['level'] === 'warn' ? '🟡' : '⛔') ?></span>
                <span class="check-label"><?= e($check['label']) ?></span>
                <span class="check-detail"><?= e($check['detail']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="<?= e(url('/install')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="step" value="check">
        <div class="install-actions">
            <a class="btn-secondary" href="<?= e(url('/install')) ?>"><?= e(t('install.check_recheck')) ?></a>
            <button type="submit" class="btn-primary"><?= e(t('install.continue')) ?></button>
        </div>
    </form>
</section>
