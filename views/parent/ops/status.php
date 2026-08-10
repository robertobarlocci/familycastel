<h2 class="page-title">🩺 <?= e(t('ops.status_title')) ?></h2>

<section class="form-card">
    <dl class="install-summary">
        <dt>Family Castel</dt><dd>v<?= e($appVersion) ?></dd>
        <dt>PHP</dt><dd><?= e($phpVersion) ?></dd>
        <dt><?= e(t('ops.database')) ?></dt><dd><?= e($dbVersion) ?></dd>
        <dt><?= e(t('ops.schema')) ?></dt>
        <dd><?= e($schemaVersion) ?><?php if ($pendingMigrations > 0): ?> · ⚠️ <?= e(t('ops.pending_migrations', ['count' => (string) $pendingMigrations])) ?><?php endif; ?></dd>
        <dt>HTTPS</dt><dd><?= $https ? '✅' : '🟡 ' . e(t('ops.https_off')) ?></dd>
        <dt><?= e(t('ops.write_gate')) ?></dt><dd><?= $gateLocked ? '🔒 ' . e(t('ops.gate_locked')) : '✅ ' . e(t('ops.gate_open')) ?></dd>
        <dt><?= e(t('ops.maintenance')) ?></dt><dd><?= $maintenance ? '🔧' : '✅' ?></dd>
        <dt><?= e(t('ops.last_backup')) ?></dt>
        <dd><?= $lastBackup !== null ? e(substr($lastBackup['created_at'], 0, 16)) : '—' ?></dd>
        <dt><?= e(t('install.family_language')) ?></dt><dd><?= e(strtoupper((string) $settings['locale'])) ?> · <?= e((string) $settings['timezone']) ?></dd>
    </dl>
</section>

<h3 class="section-title"><?= e(t('install.step_check')) ?></h3>
<section class="form-card">
    <ul class="check-list">
        <?php foreach ($checks as $check): ?>
            <li class="check-<?= e($check['level']) ?>">
                <span class="check-icon"><?= $check['level'] === 'ok' ? '✅' : ($check['level'] === 'warn' ? '🟡' : '⛔') ?></span>
                <span class="check-label"><?= e($check['label']) ?></span>
                <span class="check-detail"><?= e($check['detail']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <a class="btn-secondary" href="<?= e(url('/parent/settings/diagnostics')) ?>">📄 <?= e(t('ops.diagnostics')) ?></a>
</section>
