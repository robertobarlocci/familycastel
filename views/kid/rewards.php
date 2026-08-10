<h1 class="kid-page-title">🎁 <?= e(t('kidnav.rewards')) ?></h1>
<p class="kid-balance-line">🪙 <?= e(t('kidrewards.available', ['coins' => (string) $available])) ?></p>

<?php if (empty($catalog)): ?>
    <section class="empty-state kid-empty"><p><?= e(t('kidrewards.empty')) ?></p></section>
<?php else: ?>
    <section class="quest-grid">
        <?php foreach ($catalog as $reward): ?>
            <?php $affordable = $available >= (int) $reward['cost_coins']; ?>
            <article class="reward-card <?= $affordable ? '' : 'locked' ?>">
                <span class="reward-card-icon" aria-hidden="true"><?= e($reward['icon'] ?? '🎁') ?: '🎁' ?></span>
                <h2 class="quest-card-title"><?= e($reward['title']) ?></h2>
                <?php if (!empty($reward['description'])): ?>
                    <p class="quest-card-desc"><?= e($reward['description']) ?></p>
                <?php endif; ?>
                <?php if ($reward['duration_minutes'] !== null): ?>
                    <p class="quest-card-desc">⏱️ <?= e(t('kidrewards.duration', ['minutes' => (string) $reward['duration_minutes']])) ?></p>
                <?php endif; ?>
                <p class="reward-card-cost">🪙 <?= e((string) $reward['cost_coins']) ?></p>
                <form method="post" action="<?= e(url('/kid/rewards/redeem')) ?>">
                    <?= \FamilyCastel\Core\Csrf::field() ?>
                    <input type="hidden" name="op" value="<?= eattr(op_nonce()) ?>">
                    <input type="hidden" name="reward_id" value="<?= eattr((string) $reward['id']) ?>">
                    <button type="submit" class="btn-primary" <?= $affordable ? '' : 'disabled' ?>>
                        <?= e($affordable ? t('kidrewards.redeem') : t('kidrewards.save_more')) ?>
                    </button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<h2 class="kid-section-title"><?= e(t('kidrewards.wish_title')) ?></h2>
<form method="post" action="<?= e(url('/kid/rewards/wish')) ?>" class="form-card kid-form">
    <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="op" value="<?= eattr(op_nonce()) ?>">
    <div class="field">
        <label for="w-title"><?= e(t('kidrewards.wish_what')) ?></label>
        <input id="w-title" name="title" type="text" required maxlength="190"
               placeholder="<?= eattr(t('kidrewards.wish_placeholder')) ?>">
    </div>
    <div class="field">
        <label for="w-duration"><?= e(t('kidrewards.wish_duration')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
        <input id="w-duration" name="duration_minutes" type="number" min="1" max="600">
    </div>
    <button type="submit" class="btn-primary"><?= e(t('kidrewards.wish_send')) ?></button>
</form>

<?php if (!empty($requests)): ?>
    <h2 class="kid-section-title"><?= e(t('kidrewards.mine')) ?></h2>
    <section class="claim-list">
        <?php foreach ($requests as $request): ?>
            <div class="claim-row status-<?= e($request['status']) ?>">
                <div class="claim-row-body">
                    <strong><?= e($request['title']) ?></strong>
                    <span class="muted">
                        <?php $shownCost = $request['approved_cost_coins'] ?? $request['cost_coins']; ?><?php if ((int) $shownCost > 0): ?>🪙 <?= e((string) $shownCost) ?> · <?php endif; ?>
                        <?= e(t('kidrewards.status_' . $request['status'])) ?>
                        <?php if (!empty($request['parent_comment'])): ?> · 💬 <?= e($request['parent_comment']) ?><?php endif; ?>
                    </span>
                </div>
                <?php if ($request['status'] === 'pending'): ?>
                    <form method="post" action="<?= e(url('/kid/rewards/cancel')) ?>">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <input type="hidden" name="request_id" value="<?= eattr((string) $request['id']) ?>">
                        <button type="submit" class="btn-ghost"><?= e(t('kidrewards.cancel')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
