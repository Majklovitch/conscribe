<?php
    $pageTitle = $pageTitle ?? "Stránka";
    if(http_response_code() === 404) {
        $pageTitle = $pageTitle ?? 'Stránka nenalezena';
    }
    $pageDescription = $pageDescription ?? 'Základní webový MVC projekt v PHP.';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= esc($pageDescription) ?>">
    <link type="text/css" rel="stylesheet" href="/css/style.css?v=<?= asset('/js/main.js') ?>">
    <link type="text/css" rel="stylesheet" href="/css/cookieconsent.css">
    <script src="/js/cookieconsent.umd.js" defer></script>
    <script src="/js/main.js?v=<?= asset('/js/main.js'); ?>" defer></script>
    <script src="/js/spotlight.bundle.js" defer></script>
    <title><?= esc($pageTitle) ?> | MVC_Projekt</title>
    <?= renderTrackingCodes() ?>
</head>
<body>
<header>
    <p>Webový projekt</p>
    <nav>
        <ul>
            <?php
            foreach ($menuItems as $item): ?>
                <li>
                    <a href="<?= esc($item->link) ?>"<?= $item->active ? ' class="active"' : '' ?>>
                        <?= esc($item->name) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</header>
<main>