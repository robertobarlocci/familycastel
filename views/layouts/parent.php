<!doctype html>
<html lang="<?= e(\FamilyCastel\Core\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? t('parent.title')) ?> — Family Castel</title>
    <link rel="stylesheet" href="<?= e(url('/public-assets/css/app.css')) ?>">
</head>
<body class="parent-app">
<header class="topbar">
    <a class="topbar-brand" href="<?= e(url('/parent')) ?>">🏰 <span>Family Castel</span></a>
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
    <nav class="topbar-nav">
        <a href="<?= e(url('/parent')) ?>"><?= e(t('nav.dashboard')) ?></a>
        <a href="<?= e(url('/parent/approvals')) ?>" class="nav-approvals"><?= e(t('nav.approvals')) ?><?php if ($pendingTotal > 0): ?><span class="nav-badge"><?= e((string) $pendingTotal) ?></span><?php endif; ?></a>
        <a href="<?= e(url('/parent/sidequests')) ?>"><?= e(t('nav.sidequests')) ?></a>
        <a href="<?= e(url('/parent/rewards')) ?>"><?= e(t('nav.rewards')) ?></a>
        <a href="<?= e(url('/parent/milestones')) ?>"><?= e(t('nav.milestones')) ?></a>
        <a href="<?= e(url('/parent/children')) ?>"><?= e(t('nav.children')) ?></a>
        <a href="<?= e(url('/parent/templates')) ?>"><?= e(t('nav.templates')) ?></a>
        <a href="<?= e(url('/parent/settings/status')) ?>"><?= e(t('nav.system')) ?></a>
    </nav>
    <form method="post" action="<?= e(url('/logout')) ?>" class="topbar-logout">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <button type="submit" class="btn-ghost"><?= e(t('parent.logout')) ?></button>
    </form>
</header>
<main class="parent-main">
    <?php foreach (\FamilyCastel\Core\Session::takeFlashes() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status">
            <?= $flash['type'] === 'success' ? '✅' : '⚠️' ?> <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
</body>
</html>
