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
    <form method="post" action="<?= e(url('/logout')) ?>" class="topbar-logout">
        <?= \FamilyCastel\Core\Csrf::field() ?>
        <button type="submit" class="btn-ghost"><?= e(t('parent.logout')) ?></button>
    </form>
</header>
<main class="parent-main">
    <?= $content ?>
</main>
</body>
</html>
