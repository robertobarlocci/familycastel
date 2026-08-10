<div class="page-head">
    <h2 class="page-title"><?= e(t('children.title')) ?></h2>
    <a class="btn-primary btn-inline" href="<?= e(url('/parent/children/new')) ?>">＋ <?= e(t('children.add')) ?></a>
</div>

<?php if (empty($children)): ?>
    <section class="empty-state"><p>🧒 <?= e(t('parent.no_children')) ?></p></section>
<?php else: ?>
    <section class="list-cards">
        <?php foreach ($children as $child): ?>
            <div class="list-card <?= $child['archived_at'] !== null ? 'archived' : '' ?>">
                <span class="list-card-emoji" aria-hidden="true"><?= $child['theme'] === 'football' ? '⚽' : '🛡️' ?></span>
                <div class="list-card-body">
                    <strong><?= e($child['name']) ?></strong>
                    <span class="muted">
                        🪙 <?= e((string) $child['coin_balance']) ?> · ✨ <?= e((string) $child['xp_total']) ?> XP ·
                        <?= e(t('kid.level', ['level' => (string) $child['level']])) ?>
                        <?= $child['archived_at'] !== null ? ' · ' . e(t('children.archived_badge')) : '' ?>
                    </span>
                </div>
                <div class="list-card-actions">
                    <?php if ($child['archived_at'] === null): ?>
                        <a class="btn-secondary btn-inline" href="<?= e(url('/parent/children/' . eurl((string) $child['id']) . '/edit')) ?>"><?= e(t('common.edit')) ?></a>
                        <a class="btn-secondary btn-inline" href="<?= e(url('/parent/children/' . eurl((string) $child['id']) . '/qr')) ?>">🗝️ QR</a>
                        <form method="post" action="<?= e(url('/parent/children/' . eurl((string) $child['id']) . '/archive')) ?>"
                              data-confirm="<?= eattr(t('children.archive_confirm', ['name' => $child['name']])) ?>">
                            <?= \FamilyCastel\Core\Csrf::field() ?>
                            <button type="submit" class="btn-ghost-danger"><?= e(t('children.archive')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/parent/children/' . eurl((string) $child['id']) . '/unarchive')) ?>">
                            <?= \FamilyCastel\Core\Csrf::field() ?>
                            <button type="submit" class="btn-secondary btn-inline"><?= e(t('children.unarchive')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<script src="<?= e(url('/public-assets/js/confirm.js')) ?>"></script>
