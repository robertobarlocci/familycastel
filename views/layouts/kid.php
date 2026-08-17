<!doctype html>
<html lang="<?= e(\FamilyCastel\Core\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= ($child['theme'] ?? '') === 'football' ? '#0d3b2e' : '#2b2f6b' ?>">
    <title><?= e($title ?? t('app.name')) ?></title>
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(url('/public-assets/icons/icon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(url('/public-assets/icons/icon-192.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/public-assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/public-assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/public-assets/css/kid.css')) ?>">
</head>
<body class="kid-app theme-<?= eattr($child['theme'] ?? 'fantasy') ?>" data-sound="<?= !empty($child['sound_enabled']) ? '1' : '0' ?>" data-sw="<?= eattr(asset('/sw.js')) ?>">
<?php
$currentPath = \FamilyCastel\Core\Router::resolvePath(
    (string) ($_SERVER['REQUEST_URI'] ?? '/kid'),
    \FamilyCastel\Core\BasePath::get(),
    $_GET
);
$kidCurrent = static function (string $path) use ($currentPath): string {
    $active = $path === '/kid'
        ? $currentPath === '/kid'
        : $currentPath === $path || str_starts_with($currentPath, $path . '/');

    return $active ? ' aria-current="page"' : '';
};

// Derived HERE rather than passed in: View::renderFile() extracts caller data
// into this scope, so every name a view passes is effectively reserved (the
// 2026-08-10 extract(EXTR_SKIP) lesson). A local the caller cannot populate
// cannot collide. `?? 0` keeps a future kid page that forgets the key rendering
// without a badge instead of warning.
$journalUnseen = (int) ($journalUnseen ?? 0);
$journalUnseenLabel = $journalUnseen > \FamilyCastel\Domain\JournalService::UNSEEN_CAP
    ? \FamilyCastel\Domain\JournalService::UNSEEN_CAP . '+'
    : (string) $journalUnseen;
?>
<main class="kid-main">
    <?php foreach (\FamilyCastel\Core\Session::takeFlashes() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status">
            <?= $flash['type'] === 'success' ? '✨' : '⚠️' ?> <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<nav class="kid-nav" aria-label="<?= eattr(t('kidnav.label')) ?>">
    <a href="<?= e(url('/kid')) ?>" class="kid-nav-item"<?= $kidCurrent('/kid') ?>><span aria-hidden="true">🏰</span><span><?= e(t('kidnav.home')) ?></span></a>
    <a href="<?= e(url('/kid/sidequests')) ?>" class="kid-nav-item"<?= $kidCurrent('/kid/sidequests') ?>><span aria-hidden="true">🗡️</span><span><?= e(t('kidnav.quests')) ?></span></a>
    <a href="<?= e(url('/kid/rewards')) ?>" class="kid-nav-item"<?= $kidCurrent('/kid/rewards') ?>><span aria-hidden="true">🎁</span><span><?= e(t('kidnav.rewards')) ?></span></a>
    <a href="<?= e(url('/kid/milestones')) ?>" class="kid-nav-item"<?= $kidCurrent('/kid/milestones') ?>><span aria-hidden="true">🏆</span><span><?= e(t('kidnav.milestones')) ?></span></a>
<?php /* The badge is the FIRST child on purpose: `.kid-nav-item span:last-child` is a
         descendant rule that stretches the label and gives it its ellipsis, and a badge
         appended last would steal it. It is absolutely positioned, so order costs nothing
         visually. No aria-hidden either — that selector sizes the emoji at 1.3rem. The
         accessible name comes from aria-label on the link, so a screen reader says
         "Journal — 3 neu" instead of "3 book Journal". */ ?>
    <a href="<?= e(url('/kid/journal')) ?>" class="kid-nav-item"<?= $kidCurrent('/kid/journal') ?><?= $journalUnseen > 0 ? ' aria-label="' . eattr(t('kidnav.journal_new', ['count' => $journalUnseenLabel])) . '"' : '' ?>><?php if ($journalUnseen > 0): ?><span class="kid-nav-badge"><?= e($journalUnseenLabel) ?></span><?php endif; ?><span aria-hidden="true">📖</span><span><?= e(t('kidnav.journal')) ?></span></a>
</nav>
<?php require __DIR__ . '/../partials/_cookie_notice.php'; ?>
<script src="<?= e(asset('/public-assets/js/progress.js')) ?>"></script>
<script src="<?= e(asset('/public-assets/js/sounds.js')) ?>"></script>
<script src="<?= e(asset('/public-assets/js/pwa.js')) ?>"></script>
</body>
</html>
