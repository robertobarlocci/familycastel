<section class="kid-home-placeholder">
    <h2>🏰 <?= e(t('kid.welcome', ['name' => $child['name'] ?? ''])) ?></h2>
    <p>🪙 <?= e((string) ($child['coin_balance'] ?? 0)) ?> · ✨ <?= e((string) ($child['xp_total'] ?? 0)) ?> XP ·
        <?= e(t('kid.level', ['level' => (string) ($child['level'] ?? 1)])) ?></p>
    <form method="post" action="<?= e(url('/logout')) ?>">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <button type="submit" class="btn-ghost"><?= e(t('kid.logout')) ?></button>
    </form>
</section>
