<header class="kid-world">
    <?php
    $tier = $worldTier;
    $worldTheme = \FamilyCastel\Domain\ThemeService::isValid($child['theme'] ?? '') ? $child['theme'] : 'fantasy';
    require __DIR__ . '/_world_' . $worldTheme . '.php';
    ?>
    <div class="kid-world-overlay">
        <h1 class="kid-hero-name"><?= e($child['name'] ?? '') ?></h1>
        <p class="kid-hero-title"><?= e(t($titleKey)) ?> · <?= e(t('kid.level', ['level' => (string) ($child['level'] ?? 1)])) ?></p>
    </div>
</header>

<div class="kid-hero-stats">
    <span class="chip chip-coins">🪙 <?= e((string) ($child['coin_balance'] ?? 0)) ?></span>
    <span class="chip chip-xp">✨ <?= e((string) ($child['xp_total'] ?? 0)) ?> XP</span>
</div>
<div class="xp-bar" role="progressbar"
     aria-valuemin="<?= eattr((string) $progress['level_xp']) ?>"
     aria-valuemax="<?= eattr((string) $progress['next_level_xp']) ?>"
     aria-valuenow="<?= eattr((string) ($child['xp_total'] ?? 0)) ?>"
     aria-label="<?= eattr(t('kidhome.xp_progress')) ?>">
    <div class="xp-bar-fill" data-fraction="<?= eattr(number_format($progress['fraction'], 4, '.', '')) ?>"></div>
</div>
<p class="xp-bar-hint">
    <?= e(t('kidhome.next_level', [
        'xp' => (string) max(0, $progress['next_level_xp'] - (int) ($child['xp_total'] ?? 0)),
        'level' => (string) ($progress['level'] + 1),
    ])) ?>
    <?php if ($nextTierLevel !== null): ?>
        · <?= e(t('kidhome.next_world', ['level' => (string) $nextTierLevel])) ?>
    <?php endif; ?>
</p>

<?php if (!empty($celebrations)): ?>
    <section class="celebration-card" data-celebrate="1">
        <h2>🏅 <?= e(t('kidhome.new_achievement')) ?></h2>
        <ul>
            <?php foreach ($celebrations as $achievement): ?>
                <li><strong><?= e(t($achievement['title_key'])) ?></strong> — <?= e(t($achievement['description_key'])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (!empty($milestones)): ?>
    <section class="milestone-teaser">
        <?php $m = $milestones[0]; ?>
        <h2>🏆 <?= e($m['title']) ?></h2>
        <div class="milestone-bar" role="progressbar" aria-valuemin="0"
             aria-valuemax="<?= eattr((string) $m['target_coins']) ?>"
             aria-valuenow="<?= eattr((string) $m['progress_current']) ?>">
            <div class="milestone-bar-fill" data-fraction="<?= eattr(number_format($m['progress_fraction'], 4, '.', '')) ?>"></div>
        </div>
        <p><?= e(t('kidmilestones.progress', [
            'current' => (string) $m['progress_current'],
            'target' => (string) $m['target_coins'],
            'remaining' => (string) $m['progress_remaining'],
        ])) ?></p>
    </section>
<?php endif; ?>

<section class="kid-tiles">
    <a class="kid-tile" href="<?= e(url('/kid/sidequests')) ?>">
        <span class="kid-tile-emoji" aria-hidden="true">🗡️</span>
        <span class="kid-tile-label"><?= e(t('kidnav.quests')) ?></span>
        <?php if ($openQuests > 0): ?><span class="kid-tile-badge"><?= e((string) $openQuests) ?></span><?php endif; ?>
    </a>
    <a class="kid-tile" href="<?= e(url('/kid/rewards')) ?>">
        <span class="kid-tile-emoji" aria-hidden="true">🎁</span>
        <span class="kid-tile-label"><?= e(t('kidnav.rewards')) ?></span>
    </a>
    <a class="kid-tile" href="<?= e(url('/kid/achievements')) ?>">
        <span class="kid-tile-emoji" aria-hidden="true">🏅</span>
        <span class="kid-tile-label"><?= e(t('kidnav.achievements')) ?></span>
    </a>
    <a class="kid-tile" href="<?= e(url('/kid/settings')) ?>">
        <span class="kid-tile-emoji" aria-hidden="true">⚙️</span>
        <span class="kid-tile-label"><?= e(t('kidnav.settings')) ?></span>
    </a>
</section>

<form method="post" action="<?= e(url('/logout')) ?>" class="kid-logout">
    <?= \FamilyCastel\Core\Csrf::field() ?>
    <button type="submit" class="btn-ghost"><?= e(t('kid.logout')) ?></button>
</form>
<script src="<?= e(url('/public-assets/vendor/confetti.js')) ?>"></script>
<script src="<?= e(url('/public-assets/js/celebrate.js')) ?>"></script>
