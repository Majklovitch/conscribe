<?php
    $pageTitle = $pageTitle ?? "Page";
    if(http_response_code() === 404) {
        $pageTitle = $pageTitle ?? 'Page not found';
    }
    $pageDescription = $pageDescription ?? 'A lightweight PHP MVC framework with routing, templates, CSRF, a database layer, logging and an image pipeline.';
    $menuItems = $menuItems ?? [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= esc($pageDescription) ?>">
    <meta name="theme-color" content="#37bba5">
    <link rel="icon" href="/img/icon.png">
    <link type="text/css" rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <link type="text/css" rel="stylesheet" href="/css/cookieconsent.css">
    <script src="/js/cookieconsent.umd.js" defer></script>
    <script src="<?= asset('js/main.js') ?>" defer></script>
    <script src="/js/spotlight.bundle.js" defer></script>
    <title><?= esc($pageTitle) ?> | ConscribePHP</title>
    <?= renderTrackingCodes() ?>
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<header class="site-header">
    <div class="site-header__inner">
        <a class="site-logo" href="/" aria-label="ConscribePHP – home page">
            <img src="/img/conscribe-logo.svg" alt="ConscribePHP" width="512" height="123">
        </a>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
            <span class="visually-hidden">Menu</span>
            <span aria-hidden="true">☰</span>
        </button>

        <nav class="site-nav" id="site-nav" aria-label="Main navigation">
            <ul>
                <?php foreach ($menuItems as $item): ?>
                    <li>
                        <a href="<?= esc($item->link) ?>"<?= $item->active ? ' class="active" aria-current="page"' : '' ?>>
                            <?= esc($item->name) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>
<main id="content">
