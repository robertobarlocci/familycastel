<div class="page-head">
    <h2 class="page-title">⛔ <?= e(t('penalties.title')) ?></h2>
</div>

<p class="muted"><?= e(t('penalties.intro')) ?></p>

<?php if (empty($children)): ?>
    <section class="empty-state"><p><?= e(t('penalties.no_children')) ?></p></section>
<?php else: ?>
    <form method="post" action="<?= e(url('/parent/penalties/create')) ?>"
          class="form-card penalty-form" enctype="multipart/form-data">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="op" value="<?= eattr(op_nonce()) ?>">

        <div class="field">
            <label for="penalty-child"><?= e(t('penalties.child')) ?></label>
            <select id="penalty-child" name="child_id" required>
                <?php foreach ($children as $child): ?>
                    <option value="<?= eattr((string) $child['id']) ?>"><?= e($child['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="penalty-reason"><?= e(t('penalties.reason')) ?></label>
            <input type="text" id="penalty-reason" name="reason" maxlength="190" required
                   placeholder="<?= eattr(t('penalties.reason_placeholder')) ?>">
        </div>

        <div class="field-row">
            <div class="field grow">
                <label for="penalty-coins"><?= e(t('penalties.coins')) ?></label>
                <input type="number" id="penalty-coins" name="coins" min="1" max="999" value="1" required>
                <small><?= e(t('penalties.coins_hint')) ?></small>
            </div>
        </div>

        <div class="field">
            <label for="penalty-comment"><?= e(t('penalties.comment')) ?> <span class="optional"><?= e(t('penalties.optional')) ?></span></label>
            <input type="text" id="penalty-comment" name="comment" maxlength="500"
                   placeholder="<?= eattr(t('penalties.comment_placeholder')) ?>">
        </div>

        <div class="field field-photo">
            <label for="penalty-photo"><?= e(t('penalties.photo')) ?> <span class="optional"><?= e(t('penalties.optional')) ?></span></label>
            <input type="file" id="penalty-photo" name="photo" accept="image/jpeg,image/png,image/webp">
            <small><?= e(t('penalties.photo_hint', ['size' => (string) (int) floor($maxUploadBytes / (1024 * 1024))])) ?></small>
        </div>

        <div class="install-actions">
            <button type="submit" class="btn-primary"><?= e(t('penalties.submit')) ?></button>
        </div>
    </form>
<?php endif; ?>

<h3 class="section-title"><?= e(t('penalties.recent')) ?></h3>

<?php if (empty($recent)): ?>
    <section class="empty-state"><p><?= e(t('penalties.empty')) ?></p></section>
<?php else: ?>
    <section class="list-cards">
        <?php foreach ($recent as $row): ?>
            <article class="list-card penalty-card">
                <?php if ($row['photo_id'] !== null): ?>
                    <img class="penalty-thumb"
                         src="<?= e(url('/parent/penalties/photo/' . eurl((string) $row['id']))) ?>"
                         alt="" loading="lazy" decoding="async">
                <?php else: ?>
                    <span class="list-card-emoji" aria-hidden="true">⛔</span>
                <?php endif; ?>
                <div class="list-card-body">
                    <strong><?= e($row['title']) ?></strong>
                    <span class="muted">
                        <?= e($row['child_name']) ?> · <?= e(substr((string) $row['created_at'], 0, 16)) ?>
                    </span>
                    <?php if (!empty($row['comment'])): ?>
                        <span class="muted">💬 <?= e($row['comment']) ?></span>
                    <?php endif; ?>
                </div>
                <span class="history-delta negative"><?= e((string) (int) $row['coins_delta']) ?> 🪙</span>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
