<h2 class="page-title">🏆 <?= e(t('milestones.title')) ?></h2>

<details class="create-panel">
    <summary class="btn-primary btn-inline">＋ <?= e(t('milestones.add')) ?></summary>
    <form method="post" action="<?= e(url('/parent/milestones/create')) ?>" class="form-card">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <div class="field">
            <label for="m-child"><?= e(t('milestones.child')) ?></label>
            <select id="m-child" name="child_id" required>
                <?php foreach ($children as $child): ?>
                    <option value="<?= eattr((string) $child['id']) ?>"><?= e($child['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="m-title"><?= e(t('milestones.name')) ?></label>
            <input id="m-title" name="title" type="text" required maxlength="190"
                   placeholder="<?= eattr(t('milestones.name_placeholder')) ?>">
        </div>
        <div class="field-row">
            <div class="field grow">
                <label for="m-target"><?= e(t('milestones.target')) ?></label>
                <input id="m-target" name="target_coins" type="number" min="1" max="1000000" value="1000" required>
            </div>
            <div class="field grow">
                <label for="m-mode"><?= e(t('milestones.mode')) ?></label>
                <select id="m-mode" name="spend_mode">
                    <option value="spend"><?= e(t('milestones.mode_spend')) ?></option>
                    <option value="progress_only"><?= e(t('milestones.mode_progress')) ?></option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn-primary"><?= e(t('milestones.create')) ?></button>
    </form>
</details>

<?php foreach ($children as $child): ?>
    <?php $milestones = $milestonesByChild[(int) $child['id']] ?? []; ?>
    <?php if (empty($milestones)) { continue; } ?>
    <h3 class="section-title"><?= $child['theme'] === 'football' ? '⚽' : '🛡️' ?> <?= e($child['name']) ?></h3>
    <section class="list-cards">
        <?php foreach ($milestones as $m): ?>
            <div class="list-card milestone-admin-card">
                <div class="list-card-body">
                    <strong><?= e($m['title']) ?></strong>
                    <div class="milestone-bar" role="progressbar" aria-valuemin="0"
                         aria-valuemax="<?= eattr((string) $m['target_coins']) ?>"
                         aria-valuenow="<?= eattr((string) $m['progress_current']) ?>">
                        <div class="milestone-bar-fill" data-fraction="<?= eattr(number_format($m['progress_fraction'], 4, '.', '')) ?>"></div>
                    </div>
                    <span class="muted">
                        <?= e((string) $m['progress_current']) ?> / <?= e((string) $m['target_coins']) ?> 🪙
                        · <?= e($m['spend_mode'] === 'spend' ? t('milestones.mode_spend') : t('milestones.mode_progress')) ?>
                    </span>
                </div>
                <div class="list-card-actions">
                    <?php if ($m['progress_fraction'] >= 1.0): ?>
                        <form method="post" action="<?= e(url('/parent/milestones/' . eurl((string) $m['id']) . '/claim')) ?>"
                              data-confirm="<?= eattr(t('milestones.claim_confirm', ['title' => $m['title']])) ?>">
                            <?= \FamilyCastel\Core\Csrf::field() ?>
                            <button type="submit" class="btn-primary btn-inline">🎉 <?= e(t('milestones.claim')) ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/parent/milestones/' . eurl((string) $m['id']) . '/archive')) ?>">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <button type="submit" class="btn-ghost-danger"><?= e(t('common.archive')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
<script src="<?= e(url('/public-assets/js/confirm.js')) ?>"></script>
<script src="<?= e(url('/public-assets/js/progress.js')) ?>"></script>
