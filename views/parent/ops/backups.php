<h2 class="page-title">💾 <?= e(t('ops.backups_title')) ?></h2>

<form method="post" action="<?= e(url('/parent/settings/backups/create')) ?>" class="create-panel">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <button type="submit" class="btn-primary btn-inline">＋ <?= e(t('ops.backup_create')) ?></button>
</form>

<?php if (empty($backups)): ?>
    <section class="empty-state"><p>💾 <?= e(t('ops.backups_empty')) ?></p></section>
<?php else: ?>
    <section class="list-cards">
        <?php foreach ($backups as $backup): ?>
            <div class="list-card">
                <span class="list-card-emoji" aria-hidden="true"><?= $backup['kind'] === 'emergency' ? '🚨' : ($backup['kind'] === 'pre_update' ? '🔄' : '💾') ?></span>
                <div class="list-card-body">
                    <strong><?= e(substr($backup['created_at'], 0, 16)) ?> · <?= e(t('ops.kind_' . $backup['kind'])) ?></strong>
                    <span class="muted">v<?= e($backup['app_version']) ?> · <?= e(number_format($backup['size_bytes'] / 1024, 0)) ?> KB</span>
                </div>
                <div class="list-card-actions">
                    <a class="btn-secondary btn-inline" href="<?= e(url('/parent/settings/backups/' . eurl($backup['id']) . '/download')) ?>">⬇️ <?= e(t('ops.download')) ?></a>
                    <details class="approval-edit">
                        <summary><?= e(t('ops.restore')) ?> / <?= e(t('ops.delete')) ?></summary>
                        <form method="post" action="<?= e(url('/parent/settings/backups/' . eurl($backup['id']) . '/restore')) ?>" class="approval-edit-form">
                            <?= \FamilyCastel\Core\Csrf::field() ?>
                            <label><?= e(t('ops.restore_confirm_label')) ?>
                                <input name="confirm" type="text" autocomplete="off" placeholder="RESTORE">
                            </label>
                            <button type="submit" class="btn-ghost-danger"><?= e(t('ops.restore')) ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/parent/settings/backups/' . eurl($backup['id']) . '/delete')) ?>" class="approval-edit-form">
                            <?= \FamilyCastel\Core\Csrf::field() ?>
                            <label><?= e(t('ops.delete_confirm_label')) ?>
                                <input name="confirm" type="text" autocomplete="off" placeholder="DELETE">
                            </label>
                            <button type="submit" class="btn-ghost-danger"><?= e(t('ops.delete')) ?></button>
                        </form>
                    </details>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
    <p class="muted"><?= e(t('ops.restore_warning')) ?></p>
<?php endif; ?>
