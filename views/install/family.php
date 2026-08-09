<?php $family = $family ?? ['name' => '', 'locale' => \FamilyCastel\Core\I18n::locale(), 'timezone' => 'Europe/Zurich']; ?>
<section class="install-card">
    <?php require __DIR__ . '/_progress.php'; ?>
    <h2><?= e(t('install.step_family')) ?></h2>
    <p class="lead"><?= e(t('install.family_lead')) ?></p>
    <form method="post" action="<?= e(url('/install')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="step" value="family">
        <div class="field">
            <label for="family_name"><?= e(t('install.family_name')) ?></label>
            <input id="family_name" name="family_name" type="text" required maxlength="100"
                   value="<?= eattr($family['name']) ?>" placeholder="<?= eattr(t('install.family_name_placeholder')) ?>">
        </div>
        <div class="field">
            <label for="locale"><?= e(t('install.family_language')) ?></label>
            <select id="locale" name="locale">
                <?php foreach (['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano'] as $code => $label): ?>
                    <option value="<?= eattr($code) ?>" <?= $family['locale'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="timezone"><?= e(t('install.family_timezone')) ?></label>
            <select id="timezone" name="timezone">
                <?php foreach ($timezones as $tz): ?>
                    <option value="<?= eattr($tz) ?>" <?= $family['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-primary"><?= e(t('install.continue')) ?></button>
    </form>
</section>
