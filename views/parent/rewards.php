<h2 class="page-title">🎁 <?= e(t('rewards.title')) ?></h2>

<details class="create-panel" <?= empty($rewards) ? 'open' : '' ?>>
    <summary class="btn-primary btn-inline">＋ <?= e(t('rewards.add')) ?></summary>
    <form method="post" action="<?= e(url('/parent/rewards/create')) ?>" class="form-card">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <div class="field">
            <label for="r-title"><?= e(t('rewards.name')) ?></label>
            <input id="r-title" name="title" type="text" required maxlength="190"
                   placeholder="<?= eattr(t('rewards.name_placeholder')) ?>">
        </div>
        <div class="field-row">
            <div class="field grow">
                <label for="r-cost"><?= e(t('rewards.cost')) ?></label>
                <input id="r-cost" name="cost_coins" type="number" min="1" max="100000" value="30" required>
            </div>
            <div class="field grow">
                <label for="r-duration"><?= e(t('rewards.duration')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
                <input id="r-duration" name="duration_minutes" type="number" min="1" max="600">
            </div>
        </div>
        <div class="field">
            <label for="r-icon"><?= e(t('rewards.icon')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
            <input id="r-icon" name="icon" type="text" maxlength="8" placeholder="🎮">
        </div>
        <button type="submit" class="btn-primary"><?= e(t('rewards.create')) ?></button>
    </form>
</details>

<?php if (empty($rewards)): ?>
    <section class="empty-state"><p>🎁 <?= e(t('rewards.empty')) ?></p></section>
<?php else: ?>
    <section class="list-cards">
        <?php foreach ($rewards as $reward): ?>
            <div class="list-card">
                <span class="list-card-emoji" aria-hidden="true"><?= e($reward['icon'] ?: '🎁') ?></span>
                <div class="list-card-body">
                    <strong><?= e($reward['title']) ?></strong>
                    <span class="muted">
                        🪙 <?= e((string) $reward['cost_coins']) ?>
                        <?php if ($reward['duration_minutes'] !== null): ?> · ⏱️ <?= e((string) $reward['duration_minutes']) ?> min<?php endif; ?>
                    </span>
                </div>
                <form method="post" action="<?= e(url('/parent/rewards/' . eurl((string) $reward['id']) . '/archive')) ?>">
                    <?= \FamilyCastel\Core\Csrf::field() ?>
                    <button type="submit" class="btn-ghost-danger"><?= e(t('common.archive')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
