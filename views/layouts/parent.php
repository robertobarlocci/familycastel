<!doctype html>
<html lang="<?= e(\FamilyCastel\Core\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? t('parent.title')) ?> — Family Castel</title>
    <link rel="stylesheet" href="<?= e(asset('/public-assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/public-assets/css/app.css')) ?>">
</head>
<body class="parent-app">
<?php
$currentPath = \FamilyCastel\Core\Router::resolvePath(
    (string) ($_SERVER['REQUEST_URI'] ?? '/parent'),
    \FamilyCastel\Core\BasePath::get(),
    $_GET
);
$isCurrent = static function (string $path, bool $prefix = false) use ($currentPath): bool {
    return $prefix ? $currentPath === $path || str_starts_with($currentPath, $path . '/') : $currentPath === $path;
};
$currentAttr = static fn (string $path, bool $prefix = false): string => $isCurrent($path, $prefix) ? ' aria-current="page"' : '';
?>
<header class="topbar" data-parent-header>
    <div class="topbar-row">
        <a class="topbar-brand" href="<?= e(url('/parent')) ?>">🏰 <span>Family Castel</span></a>
        <button class="topbar-menu-toggle" type="button" aria-expanded="false" aria-controls="parent-menu"
                aria-label="<?= eattr(t('nav.open_menu')) ?>" data-close-label="<?= eattr(t('nav.close_menu')) ?>" hidden>
            <span class="hamburger-lines" aria-hidden="true"><span></span><span></span><span></span></span>
        </button>
    </div>
    <?php
    $pendingTotal = 0;
    try {
        $pendingTotal = array_sum((new \FamilyCastel\Domain\NotificationService(
            \FamilyCastel\Core\Db::fromConfig(new \FamilyCastel\Core\Config(FC_ROOT . '/config'))
        ))->pendingCounts());
    } catch (\Throwable) {
        // Badge is decoration — never break the page for it.
    }
    ?>
    <div class="topbar-menu" id="parent-menu">
        <nav class="topbar-nav" aria-label="<?= eattr(t('nav.parent_label')) ?>">
            <a href="<?= e(url('/parent')) ?>"<?= $currentAttr('/parent') ?>><span class="nav-icon" aria-hidden="true">🏠</span><span><?= e(t('nav.dashboard')) ?></span></a>
            <a href="<?= e(url('/parent/approvals')) ?>" class="nav-approvals"<?= $currentAttr('/parent/approvals', true) ?>><span class="nav-icon" aria-hidden="true">✅</span><span><?= e(t('nav.approvals')) ?></span><?php if ($pendingTotal > 0): ?><span class="nav-badge"><?= e((string) $pendingTotal) ?></span><?php endif; ?></a>
            <a href="<?= e(url('/parent/sidequests')) ?>"<?= $currentAttr('/parent/sidequests', true) ?>><span class="nav-icon" aria-hidden="true">🗡️</span><span><?= e(t('nav.sidequests')) ?></span></a>
            <a href="<?= e(url('/parent/rewards')) ?>"<?= $currentAttr('/parent/rewards', true) ?>><span class="nav-icon" aria-hidden="true">🎁</span><span><?= e(t('nav.rewards')) ?></span></a>
            <a href="<?= e(url('/parent/milestones')) ?>"<?= $currentAttr('/parent/milestones', true) ?>><span class="nav-icon" aria-hidden="true">🏆</span><span><?= e(t('nav.milestones')) ?></span></a>
            <a href="<?= e(url('/parent/children')) ?>"<?= $currentAttr('/parent/children', true) ?>><span class="nav-icon" aria-hidden="true">🧒</span><span><?= e(t('nav.children')) ?></span></a>
            <a href="<?= e(url('/parent/templates')) ?>"<?= $currentAttr('/parent/templates', true) ?>><span class="nav-icon" aria-hidden="true">⭐</span><span><?= e(t('nav.templates')) ?></span></a>
            <a href="<?= e(url('/parent/penalties')) ?>"<?= $currentAttr('/parent/penalties', true) ?>><span class="nav-icon" aria-hidden="true">⛔</span><span><?= e(t('nav.penalties')) ?></span></a>
            <a href="<?= e(url('/parent/settings/status')) ?>"<?= $currentAttr('/parent/settings/status') ?>><span class="nav-icon" aria-hidden="true">🩺</span><span><?= e(t('nav.system')) ?></span></a>
            <a href="<?= e(url('/parent/settings/updates')) ?>" class="nav-updates"<?= $currentAttr('/parent/settings/updates') ?>><span class="nav-icon" aria-hidden="true">🔄</span><span><?= e(t('ops.updates_title')) ?></span><span class="nav-version">v<?= e(FC_VERSION) ?></span></a>
        </nav>
        <form method="post" action="<?= e(url('/logout')) ?>" class="topbar-logout">
            <?= \FamilyCastel\Core\Csrf::field() ?>
            <button type="submit" class="btn-ghost"><?= e(t('parent.logout')) ?></button>
        </form>
    </div>
</header>
<main class="parent-main">
    <?php foreach (\FamilyCastel\Core\Session::takeFlashes() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status">
            <?= $flash['type'] === 'success' ? '✅' : '⚠️' ?> <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<?php require __DIR__ . '/../partials/_cookie_notice.php'; ?>
<script src="<?= e(asset('/public-assets/js/navigation.js')) ?>"></script>
</body>
</html>
