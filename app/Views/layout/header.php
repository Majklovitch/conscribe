<?php
    $pageTitle = $pageTitle ?? match (CURRENT_PAGE) {
        'test' => 'Testovací stránka',
        'home', '' => 'Domovská stránka',
        default => 'Stránka',
    };
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
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link type="text/css" rel="stylesheet" href="/css/style.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/main.js') ?>">
    <link type="text/css" rel="stylesheet" href="/css/cookieconsent.css">
    <script src="/js/cookieconsent.umd.js" defer></script>
    <script src="/js/main.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/main.js') ?>" defer></script>
    <script src="/js/spotlight.bundle.js" defer></script>
    <title><?= htmlspecialchars($pageTitle) ?> | MVC_Projekt</title>
</head>
<body>
<header>
    <p>Webový projekt</p>
    <nav>
        <ul>
            <li><a href="/">Domů</a></li>
            <li><a href="/test">Test</a></li>
        </ul>
    </nav>
</header>
<main>