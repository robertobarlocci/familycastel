<section class="install-card install-done">
    <?php if (!empty($warning)): ?>
        <div class="install-error" role="alert">⚠️ <?= e($warning) ?></div>
    <?php endif; ?>
    <div class="done-burst" aria-hidden="true">🎉</div>
    <h2><?= e(t('install.done_title')) ?></h2>
    <p class="lead"><?= e(t('install.done_lead', ['family' => $family['name'] ?? ''])) ?></p>
    <a class="btn-primary btn-big" href="<?= e(url('/')) ?>"><?= e(t('install.done_enter')) ?></a>
</section>
