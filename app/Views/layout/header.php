<?php
    $pageTitle = match (CURRENT_PAGE) {
        'test' => 'Testovací stránka',
        '' => 'Domovská stránka',
        default => 'Stránka',
    };
    if(http_response_code() === 404) {
        $pageTitle = 'Stránka nenalezena';
    }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link type="text/css" rel="stylesheet" href="/css/style.css">
    <script src="/js/main.js"></script>
    <script src="/js/spotlight.bundle.js"></script>
    <title><?= $pageTitle ?> | MVC_Projekt</title>
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