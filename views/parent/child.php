<div class="page-head">
    <h2 class="page-title">
        <?= $child['theme'] === 'football' ? '⚽' : '🛡️' ?> <?= e($child['name']) ?>
    </h2>
    <div class="balance-chips">
        <span class="chip chip-coins">🪙 <?= e((string) $child['coin_balance']) ?></span>
        <?php if ((int) $child['coin_balance'] !== $available): ?>
            <span class="chip chip-available" title="<?= eattr(t('award.available_hint')) ?>">
                <?= e(t('award.available', ['coins' => (string) $available])) ?>
            </span>
        <?php endif; ?>
        <span class="chip chip-xp">✨ <?= e((string) $child['xp_total']) ?> XP</span>
        <span class="chip"><?= e(t('kid.level', ['level' => (string) $child['level']])) ?></span>
    </div>
</div>

<?php if (!empty($templates)): ?>
    <h3 class="section-title"><?= e(t('award.templates_title')) ?></h3>
    <section class="template-grid">
        <?php foreach ($templates as $template): ?>
            <form method="post" action="<?= e(url('/parent/child/' . eurl((string) $child['id']) . '/award')) ?>"
                  <?= $template['requires_confirm'] ? 'data-confirm="' . eattr(t('award.confirm', ['title' => $template['title']])) . '"' : '' ?>>
                <?= \FamilyCastel\Core\Csrf::field() ?>
                <input type="hidden" name="op" value="<?= eattr(op_nonce()) ?>">
                <input type="hidden" name="template_id" value="<?= eattr((string) $template['id']) ?>">
                <button type="submit" class="template-btn <?= (int) $template['coins_delta'] < 0 ? 'negative' : '' ?>">
                    <span class="template-btn-title"><?= $template['is_favorite'] ? '⭐ ' : '' ?><?= e($template['title']) ?></span>
                    <span class="template-btn-delta">
                        <?= (int) $template['coins_delta'] >= 0 ? '+' : '' ?><?= e((string) $template['coins_delta']) ?> 🪙
                        <?php if ((int) $template['xp_delta'] > 0): ?> · +<?= e((string) $template['xp_delta']) ?> ✨<?php endif; ?>
                    </span>
                </button>
            </form>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<h3 class="section-title"><?= e(t('award.custom_title')) ?></h3>
<form method="post" action="<?= e(url('/parent/child/' . eurl((string) $child['id']) . '/custom')) ?>" class="form-card award-form">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <input type="hidden" name="op" value="<?= eattr(op_nonce()) ?>">
    <div class="field">
        <label for="title"><?= e(t('award.what')) ?></label>
        <input id="title" name="title" type="text" required maxlength="190"
               placeholder="<?= eattr(t('award.what_placeholder')) ?>">
    </div>
    <div class="field-row">
        <div class="field grow">
            <label for="coins"><?= e(t('award.coins')) ?></label>
            <input id="coins" name="coins" type="number" required min="-999" max="999" value="5">
        </div>
        <div class="field grow">
            <label for="xp"><?= e(t('award.xp')) ?></label>
            <input id="xp" name="xp" type="number" min="0" max="999" value="5">
        </div>
    </div>
    <div class="field">
        <label for="comment"><?= e(t('award.comment')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
        <input id="comment" name="comment" type="text" maxlength="500"
               placeholder="<?= eattr(t('award.comment_placeholder')) ?>">
    </div>
    <label class="checkbox"><input type="checkbox" name="save_as_template" value="1"> <?= e(t('award.save_template')) ?></label>
    <button type="submit" class="btn-primary"><?= e(t('award.submit')) ?></button>
</form>

<?php if (!empty($history)): ?>
    <h3 class="section-title"><?= e(t('award.history_title')) ?></h3>
    <section class="history-list">
        <?php foreach ($history as $tx): ?>
            <div class="history-row">
                <span class="history-delta <?= (int) $tx['coins_delta'] < 0 ? 'negative' : 'positive' ?>">
                    <?= (int) $tx['coins_delta'] >= 0 ? '+' : '' ?><?= e((string) $tx['coins_delta']) ?> 🪙
                </span>
                <span class="history-title"><?= e($tx['title']) ?></span>
                <span class="history-time muted"><?= e(substr((string) $tx['created_at'], 0, 16)) ?></span>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<script src="<?= e(url('/public-assets/js/confirm.js')) ?>"></script>
