<?php $isNew = $child === null; ?>
<h2 class="page-title"><?= e($isNew ? t('children.add') : t('children.edit', ['name' => $child['name']])) ?></h2>

<form method="post" action="<?= e(url('/parent/children/save')) ?>" class="form-card">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= eattr((string) $child['id']) ?>"><?php endif; ?>

    <div class="field">
        <label for="name"><?= e(t('children.name')) ?></label>
        <input id="name" name="name" type="text" required maxlength="100" value="<?= eattr($child['name'] ?? '') ?>">
    </div>

    <div class="field">
        <label for="theme"><?= e(t('children.theme')) ?></label>
        <select id="theme" name="theme">
            <?php foreach ($themes as $theme): ?>
                <option value="<?= eattr($theme) ?>" <?= ($child['theme'] ?? 'fantasy') === $theme ? 'selected' : '' ?>>
                    <?= e(t('theme.' . $theme)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="character_key"><?= e(t('children.character')) ?></label>
        <select id="character_key" name="character_key">
            <?php foreach ($characters as $theme => $keys): ?>
                <?php foreach ($keys as $key): ?>
                    <option value="<?= eattr($key) ?>" data-theme="<?= eattr($theme) ?>"
                        <?= ($child['character_key'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= e(t('character.' . $key)) ?> (<?= e(t('theme.' . $theme)) ?>)
                    </option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="pin"><?= e(t('children.pin')) ?> <span class="optional">(<?= e(t('install.optional')) ?>)</span></label>
        <input id="pin" name="pin" type="text" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6"
               placeholder="<?= eattr($isNew || ($child['pin_hash'] ?? null) === null ? t('children.pin_none') : t('children.pin_set')) ?>">
        <small><?= e(t('children.pin_hint')) ?></small>
        <?php if (!$isNew && ($child['pin_hash'] ?? null) !== null): ?>
            <label class="checkbox"><input type="checkbox" name="clear_pin" value="1"> <?= e(t('children.pin_clear')) ?></label>
        <?php endif; ?>
    </div>

    <label class="checkbox">
        <input type="checkbox" name="sound_enabled" value="1" <?= ($child['sound_enabled'] ?? 1) ? 'checked' : '' ?>>
        <?= e(t('children.sound')) ?>
    </label>

    <div class="install-actions">
        <a class="btn-secondary" href="<?= e(url('/parent/children')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn-primary"><?= e(t('common.save')) ?></button>
    </div>
</form>
