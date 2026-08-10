<h1 class="kid-page-title">⚙️ <?= e(t('kidnav.settings')) ?></h1>

<form method="post" action="<?= e(url('/kid/settings')) ?>" class="form-card kid-form">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <fieldset class="field theme-picker">
        <legend><?= e(t('kidsettings.world')) ?></legend>
        <?php foreach ($allowedThemes as $theme): ?>
            <label class="theme-option <?= ($child['theme'] ?? '') === $theme ? 'selected' : '' ?>">
                <input type="radio" name="theme" value="<?= eattr($theme) ?>"
                       <?= ($child['theme'] ?? '') === $theme ? 'checked' : '' ?>>
                <span class="theme-option-emoji" aria-hidden="true"><?= $theme === 'football' ? '⚽' : '🏰' ?></span>
                <span><?= e(t('theme.' . $theme)) ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <label class="checkbox">
        <input type="checkbox" name="sound_enabled" value="1" <?= !empty($child['sound_enabled']) ? 'checked' : '' ?>>
        🔊 <?= e(t('kidsettings.sounds')) ?>
    </label>

    <button type="submit" class="btn-primary"><?= e(t('common.save')) ?></button>
</form>
