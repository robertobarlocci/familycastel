<h1 class="kid-page-title">🏅 <?= e(t('kidnav.achievements')) ?></h1>

<section class="achievement-grid">
    <?php foreach ($achievements as $achievement): ?>
        <?php $unlocked = $achievement['unlocked_at'] !== null; ?>
        <article class="achievement-card rarity-<?= e($achievement['rarity']) ?> <?= $unlocked ? 'unlocked' : 'locked' ?>">
            <span class="achievement-icon" aria-hidden="true"><?= $unlocked ? '🏅' : '🔒' ?></span>
            <h2 class="achievement-title"><?= e(t($achievement['title_key'])) ?></h2>
            <p class="achievement-desc"><?= e(t($achievement['description_key'])) ?></p>
            <span class="achievement-rarity"><?= e(t('rarity.' . $achievement['rarity'])) ?></span>
            <?php if ($unlocked): ?>
                <span class="achievement-date"><?= e(substr((string) $achievement['unlocked_at'], 0, 10)) ?></span>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
