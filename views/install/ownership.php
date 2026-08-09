<section class="install-card">
    <?php require __DIR__ . '/_progress.php'; ?>
    <h2><?= e(t('install.step_ownership')) ?></h2>
    <p class="lead"><?= e(t('install.ownership_lead')) ?></p>
    <ol class="install-howto">
        <li><?= e(t('install.ownership_how_1')) ?></li>
        <li><?= e(t('install.ownership_how_2', ['path' => 'storage/setup-token.txt'])) ?></li>
        <li><?= e(t('install.ownership_how_3')) ?></li>
    </ol>
    <form method="post" action="<?= e(url('/install')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <input type="hidden" name="step" value="ownership">
        <label for="setup_code"><?= e(t('install.ownership_code_label')) ?></label>
        <input id="setup_code" name="setup_code" type="text" required autocomplete="off"
               spellcheck="false" maxlength="8" class="input-code" placeholder="XXXXXXXX">
        <button type="submit" class="btn-primary"><?= e(t('install.continue')) ?></button>
    </form>
</section>
