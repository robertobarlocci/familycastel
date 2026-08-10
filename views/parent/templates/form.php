<?php
/**
 * @var array<string, mixed>|null $template the record being edited, null on /new
 * @var list<array<string, mixed>> $children
 */
// Defensive: treat anything that is not a record as "new" rather than indexing
// into it. A missing view variable must degrade to the create form, never to a
// 500 (issue #12).
$template = is_array($template ?? null) ? $template : null;
$isNew = $template === null;
$selectedIds = [];
if (!$isNew && ($template['child_ids'] ?? null) !== null) {
    $selectedIds = array_map(intval(...), (array) json_decode((string) $template['child_ids'], true));
}
?>
<h2 class="page-title"><?= e($isNew ? t('templates.add') : t('templates.edit')) ?></h2>

<form method="post" action="<?= e(url('/parent/templates/save')) ?>" class="form-card">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= eattr((string) $template['id']) ?>"><?php endif; ?>

    <div class="field">
        <label for="title"><?= e(t('templates.name')) ?></label>
        <input id="title" name="title" type="text" required maxlength="190"
               value="<?= eattr($template['title'] ?? '') ?>" placeholder="<?= eattr(t('templates.name_placeholder')) ?>">
    </div>

    <div class="field-row">
        <div class="field grow">
            <label for="coins_delta"><?= e(t('award.coins')) ?></label>
            <input id="coins_delta" name="coins_delta" type="number" required min="-999" max="999"
                   value="<?= eattr((string) ($template['coins_delta'] ?? 5)) ?>">
            <small><?= e(t('templates.coins_hint')) ?></small>
        </div>
        <div class="field grow">
            <label for="xp_delta"><?= e(t('award.xp')) ?></label>
            <input id="xp_delta" name="xp_delta" type="number" min="0" max="999"
                   value="<?= eattr((string) ($template['xp_delta'] ?? 5)) ?>">
        </div>
    </div>

    <div class="field">
        <label for="scope"><?= e(t('templates.scope')) ?></label>
        <select id="scope" name="scope">
            <option value="all" <?= ($template['scope'] ?? 'all') === 'all' ? 'selected' : '' ?>><?= e(t('templates.scope_all')) ?></option>
            <option value="selected" <?= ($template['scope'] ?? '') === 'selected' ? 'selected' : '' ?>><?= e(t('templates.scope_selected')) ?></option>
        </select>
    </div>

    <fieldset class="field checkbox-list">
        <legend><?= e(t('templates.scope_children')) ?></legend>
        <?php foreach ($children as $child): ?>
            <label class="checkbox">
                <input type="checkbox" name="child_ids[]" value="<?= eattr((string) $child['id']) ?>"
                    <?= in_array((int) $child['id'], $selectedIds, true) ? 'checked' : '' ?>>
                <?= e($child['name']) ?>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <label class="checkbox">
        <input type="checkbox" name="is_favorite" value="1" <?= !empty($template['is_favorite']) ? 'checked' : '' ?>>
        ⭐ <?= e(t('templates.favorite')) ?>
    </label>
    <label class="checkbox">
        <input type="checkbox" name="requires_confirm" value="1" <?= !empty($template['requires_confirm']) ? 'checked' : '' ?>>
        <?= e(t('templates.requires_confirm')) ?>
    </label>

    <div class="install-actions">
        <a class="btn-secondary" href="<?= e(url('/parent/templates')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn-primary"><?= e(t('common.save')) ?></button>
    </div>
</form>
