<h2 class="page-title">🔄 <?= e(t('ops.updates_title')) ?></h2>

<?php if (!empty($manualPending)): ?>
    <section class="flash flash-error">
        <?= e(t('ops.manual_pending', ['code' => $current, 'db' => $recordedVersion])) ?>
        <form method="post" action="<?= e(url('/parent/settings/updates/start-manual')) ?>">
            <?= \FamilyCastel\Core\Csrf::field() ?>
            <button type="submit" class="btn-primary btn-inline">🔧 <?= e(t('ops.manual_start')) ?></button>
        </form>
    </section>
<?php endif; ?>

<section class="form-card">
    <p><strong><?= e(t('ops.current_version')) ?>:</strong> v<?= e($current) ?></p>
    <?php if ($latest !== null): ?>
        <p><strong><?= e(t('ops.latest_version')) ?>:</strong> v<?= e($latest['version']) ?>
            <span class="muted">(<?= e(t('ops.checked_at', ['time' => substr($latest['checked_at'], 0, 16)])) ?>)</span></p>
        <?php if ($updateAvailable): ?>
            <?php if (!empty($latest['notes'])): ?>
                <details><summary><?= e(t('ops.release_notes')) ?></summary>
                    <pre class="release-notes"><?= e(mb_substr($latest['notes'], 0, 4000)) ?></pre>
                </details>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/parent/settings/updates/start')) ?>"
                  data-confirm="<?= eattr(t('ops.update_confirm', ['version' => $latest['version']])) ?>">
                <?= \FamilyCastel\Core\Csrf::field() ?>
                <button type="submit" class="btn-primary">⬆️ <?= e(t('ops.update_now', ['version' => $latest['version']])) ?></button>
            </form>
        <?php else: ?>
            <p class="muted">✅ <?= e(t('ops.up_to_date')) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted"><?= e(t('ops.no_release_info')) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/parent/settings/updates/check')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <button type="submit" class="btn-secondary"><?= e(t('ops.check_now')) ?></button>
    </form>
</section>

<?php if ($journal !== null && ($journal['status'] ?? '') === 'rolled_back'): ?>
    <section class="flash flash-error"><?= e(t('ops.last_update_rolled_back', ['error' => (string) ($journal['error'] ?? '')])) ?></section>
<?php endif; ?>

<?php if (!empty($history)): ?>
    <h3 class="section-title"><?= e(t('ops.update_history')) ?></h3>
    <section class="list-cards">
        <?php foreach ($history as $entry): ?>
            <div class="list-card">
                <div class="list-card-body">
                    <strong>v<?= e($entry['from_version']) ?> → v<?= e($entry['to_version']) ?></strong>
                    <span class="muted"><?= e($entry['status']) ?> · <?= e(substr((string) $entry['finished_at'], 0, 16)) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<script src="<?= e(asset('/public-assets/js/confirm.js')) ?>"></script>
