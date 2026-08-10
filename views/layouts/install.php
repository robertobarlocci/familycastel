<!doctype html>
<html lang="<?= e(\FamilyCastel\Core\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('install.title')) ?> — Family Castel</title>
    <link rel="stylesheet" href="<?= e(asset('/public-assets/css/install.css')) ?>">
</head>
<body class="install">
<main class="install-wrap">
    <header class="install-brand">
        <div class="install-castle" aria-hidden="true">🏰</div>
        <h1 class="install-wordmark">Family&nbsp;Castel</h1>
    </header>
    <?= $content ?>
</main>
</body>
</html>
