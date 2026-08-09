<!doctype html>
<html lang="<?= e(\FamilyCastel\Core\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? t('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('/public-assets/css/app.css')) ?>">
</head>
<body class="kid-app theme-<?= eattr($child['theme'] ?? 'fantasy') ?>">
<main class="kid-main">
    <?= $content ?>
</main>
</body>
</html>
