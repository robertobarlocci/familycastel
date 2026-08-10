<h1 class="kid-page-title">🏆 <?= e(t('kidnav.milestones')) ?></h1>

<?php if (empty($milestones)): ?>
    <section class="empty-state kid-empty"><p><?= e(t('kidmilestones.empty')) ?></p></section>
<?php else: ?>
    <?php foreach ($milestones as $m): ?>
        <section class="milestone-card">
            <h2><?= e($m['title']) ?></h2>
            <div class="milestone-bar big" role="progressbar" aria-valuemin="0"
                 aria-valuemax="<?= eattr((string) $m['target_coins']) ?>"
                 aria-valuenow="<?= eattr((string) $m['progress_current']) ?>"
                 aria-label="<?= eattr($m['title']) ?>">
                <div class="milestone-bar-fill" data-fraction="<?= eattr(number_format($m['progress_fraction'], 4, '.', '')) ?>"></div>
            </div>
            <p class="milestone-numbers">
                <strong><?= e((string) $m['progress_current']) ?> / <?= e((string) $m['target_coins']) ?></strong> 🪙
                · <?= e((string) round($m['progress_fraction'] * 100)) ?>%
            </p>
            <p class="muted"><?= e(t('kidmilestones.remaining', ['coins' => (string) $m['progress_remaining']])) ?></p>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<h2 class="kid-section-title"><?= e(t('kidmilestones.wish_title')) ?></h2>
<form method="post" action="<?= e(url('/kid/milestones/wish')) ?>" class="form-card kid-form">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <div class="field">
        <label for="m-title"><?= e(t('kidmilestones.wish_what')) ?></label>
        <input id="m-title" name="title" type="text" required maxlength="190"
               placeholder="<?= eattr(t('kidmilestones.wish_placeholder')) ?>">
    </div>
    <div class="field">
        <label for="m-coins"><?= e(t('kidmilestones.wish_coins')) ?></label>
        <input id="m-coins" name="coins" type="number" min="1" max="100000" value="500" required>
    </div>
    <button type="submit" class="btn-primary"><?= e(t('kidmilestones.wish_send')) ?></button>
</form>

<?php if (!empty($wishes)): ?>
    <h2 class="kid-section-title"><?= e(t('kidmilestones.wishes')) ?></h2>
    <section class="claim-list">
        <?php foreach ($wishes as $wish): ?>
            <div class="claim-row status-<?= e($wish['status']) ?>">
                <div class="claim-row-body">
                    <strong><?= e($wish['title']) ?></strong>
                    <span class="muted">
                        🪙 <?= e((string) $wish['suggested_coins']) ?> ·
                        <?= e(t('kidmilestones.status_' . $wish['status'])) ?>
                        <?php if (!empty($wish['parent_comment'])): ?> · 💬 <?= e($wish['parent_comment']) ?><?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
