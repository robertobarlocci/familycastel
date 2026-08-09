<h1 class="kid-page-title">🗡️ <?= e(t('kidnav.quests')) ?></h1>

<?php if (empty($available)): ?>
    <section class="empty-state kid-empty">
        <p><?= e(t('kidquests.empty')) ?></p>
    </section>
<?php else: ?>
    <section class="quest-grid">
        <?php foreach ($available as $quest): ?>
            <article class="quest-card">
                <h2 class="quest-card-title"><?= e($quest['title']) ?></h2>
                <?php if (!empty($quest['description'])): ?>
                    <p class="quest-card-desc"><?= e($quest['description']) ?></p>
                <?php endif; ?>
                <p class="quest-card-reward">
                    +<?= e((string) $quest['coins_reward']) ?> 🪙
                    <?php if ((int) $quest['xp_reward'] > 0): ?> · +<?= e((string) $quest['xp_reward']) ?> ✨<?php endif; ?>
                </p>
                <?php if ($quest['expires_at'] !== null): ?>
                    <p class="quest-card-expiry">⏳ <?= e(t('kidquests.expires', ['date' => substr((string) $quest['expires_at'], 0, 16)])) ?></p>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/kid/sidequests/accept')) ?>">
                    <?= \FamilyCastel\Core\Csrf::field() ?>
                    <input type="hidden" name="quest_id" value="<?= eattr((string) $quest['id']) ?>">
                    <button type="submit" class="btn-primary"><?= e(t('kidquests.accept')) ?></button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php
$active = array_values(array_filter($claims, fn ($c) => in_array($c['status'], ['accepted', 'completed_pending'], true)));
$past = array_values(array_filter($claims, fn ($c) => !in_array($c['status'], ['accepted', 'completed_pending'], true)));
?>
<?php if (!empty($active)): ?>
    <h2 class="kid-section-title"><?= e(t('kidquests.mine')) ?></h2>
    <section class="claim-list">
        <?php foreach ($active as $claim): ?>
            <div class="claim-row status-<?= e($claim['status']) ?>">
                <div class="claim-row-body">
                    <strong><?= e($claim['title']) ?></strong>
                    <span class="muted">+<?= e((string) $claim['coins_reward']) ?> 🪙<?php if ((int) $claim['xp_reward'] > 0): ?> · +<?= e((string) $claim['xp_reward']) ?> ✨<?php endif; ?></span>
                </div>
                <?php if ($claim['status'] === 'accepted'): ?>
                    <form method="post" action="<?= e(url('/kid/sidequests/complete')) ?>">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <input type="hidden" name="claim_id" value="<?= eattr((string) $claim['id']) ?>">
                        <button type="submit" class="btn-primary btn-inline"><?= e(t('kidquests.done')) ?></button>
                    </form>
                    <form method="post" action="<?= e(url('/kid/sidequests/cancel')) ?>">
                        <?= \FamilyCastel\Core\Csrf::field() ?>
                        <input type="hidden" name="claim_id" value="<?= eattr((string) $claim['id']) ?>">
                        <button type="submit" class="btn-ghost"><?= e(t('kidquests.giveback')) ?></button>
                    </form>
                <?php else: ?>
                    <span class="status-badge">⌛ <?= e(t('kidquests.waiting')) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<h2 class="kid-section-title"><?= e(t('kidquests.suggest_title')) ?></h2>
<form method="post" action="<?= e(url('/kid/suggestions')) ?>" class="form-card kid-form">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <div class="field">
        <label for="s-title"><?= e(t('kidquests.suggest_what')) ?></label>
        <input id="s-title" name="title" type="text" required maxlength="190"
               placeholder="<?= eattr(t('kidquests.suggest_placeholder')) ?>">
    </div>
    <div class="field">
        <label for="s-coins"><?= e(t('kidquests.suggest_coins')) ?></label>
        <input id="s-coins" name="coins" type="number" min="1" max="999" value="5" required>
    </div>
    <div class="field">
        <label for="s-comment"><?= e(t('award.comment')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
        <input id="s-comment" name="comment" type="text" maxlength="500">
    </div>
    <button type="submit" class="btn-primary"><?= e(t('kidquests.suggest_send')) ?></button>
</form>

<?php if (!empty($past)): ?>
    <h2 class="kid-section-title"><?= e(t('kidquests.history')) ?></h2>
    <section class="claim-list">
        <?php foreach (array_slice($past, 0, 10) as $claim): ?>
            <div class="claim-row status-<?= e($claim['status']) ?>">
                <div class="claim-row-body">
                    <strong><?= e($claim['title']) ?></strong>
                    <span class="muted">
                        <?= e(t('kidquests.status_' . $claim['status'])) ?>
                        <?php if (!empty($claim['parent_comment'])): ?> · 💬 <?= e($claim['parent_comment']) ?><?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
