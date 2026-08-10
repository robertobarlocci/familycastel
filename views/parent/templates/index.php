<div class="page-head">
    <h2 class="page-title"><?= e(t('templates.title')) ?></h2>
    <a class="btn-primary btn-inline" href="<?= e(url('/parent/templates/new')) ?>">＋ <?= e(t('templates.add')) ?></a>
</div>

<?php if (empty($templates)): ?>
    <section class="empty-state"><p>📋 <?= e(t('templates.empty')) ?></p></section>
<?php else: ?>
    <section class="list-cards">
        <?php foreach ($templates as $template): ?>
            <div class="list-card">
                <form method="post" action="<?= e(url('/parent/templates/' . eurl((string) $template['id']) . '/favorite')) ?>">
                    <?= \FamilyCastel\Core\Csrf::field() ?>
                    <button type="submit" class="star-btn" aria-label="<?= eattr(t('templates.favorite_toggle')) ?>">
                        <?= $template['is_favorite'] ? '⭐' : '☆' ?>
                    </button>
                </form>
                <div class="list-card-body">
                    <strong><?= e($template['title']) ?></strong>
                    <span class="muted">
                        <?= (int) $template['coins_delta'] >= 0 ? '+' : '' ?><?= e((string) $template['coins_delta']) ?> 🪙
                        <?php if ((int) $template['xp_delta'] > 0): ?> · +<?= e((string) $template['xp_delta']) ?> ✨<?php endif; ?>
                        · <?= e($template['scope'] === 'all' ? t('templates.scope_all') : t('templates.scope_selected')) ?>
                    </span>
                </div>
                <div class="list-card-actions">
                    <a class="btn-secondary btn-inline" href="<?= e(url('/parent/templates/' . eurl((string) $template['id']) . '/edit')) ?>"><?= e(t('common.edit')) ?></a>
                    <form method="post" action="<?= e(url('/parent/templates/' . eurl((string) $template['id']) . '/archive')) ?>">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <button type="submit" class="btn-ghost-danger"><?= e(t('common.archive')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
