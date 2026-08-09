<!doctype html>
<html lang="<?= e(\FamilyCastel\Core\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? t('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('/public-assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/public-assets/css/kid.css')) ?>">
</head>
<body class="kid-app theme-<?= eattr($child['theme'] ?? 'fantasy') ?>">
<main class="kid-main">
    <?php foreach (\FamilyCastel\Core\Session::takeFlashes() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status">
            <?= $flash['type'] === 'success' ? '✨' : '⚠️' ?> <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<nav class="kid-nav" aria-label="<?= eattr(t('kidnav.label')) ?>">
    <a href="<?= e(url('/kid')) ?>" class="kid-nav-item"><span aria-hidden="true">🏰</span><span><?= e(t('kidnav.home')) ?></span></a>
    <a href="<?= e(url('/kid/sidequests')) ?>" class="kid-nav-item"><span aria-hidden="true">🗡️</span><span><?= e(t('kidnav.quests')) ?></span></a>
    <a href="<?= e(url('/kid/rewards')) ?>" class="kid-nav-item"><span aria-hidden="true">🎁</span><span><?= e(t('kidnav.rewards')) ?></span></a>
    <a href="<?= e(url('/kid/milestones')) ?>" class="kid-nav-item"><span aria-hidden="true">🏆</span><span><?= e(t('kidnav.milestones')) ?></span></a>
    <a href="<?= e(url('/kid/journal')) ?>" class="kid-nav-item"><span aria-hidden="true">📖</span><span><?= e(t('kidnav.journal')) ?></span></a>
</nav>
<script src="<?= e(url('/public-assets/js/progress.js')) ?>"></script>
</body>
</html>
