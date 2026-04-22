<?php

namespace App\Controllers;

class WebController {

    public function render($pageName, $id = null): void {

        $_SESSION['form_load_time'] = time();
        $safePage = preg_replace('/[^a-z0-9-]/', '', $pageName);

        $basePath = dirname(__DIR__) . '/Views/';
        $fileView = $basePath . $safePage . '.php';
        $headerDefault = $basePath . 'layout/header.php';
        $footer = $basePath . 'layout/footer.php';
        $notFound = $basePath . '404.php';

        $viewToRender = $fileView;
        if (!is_file($fileView)) {
            http_response_code(404);
            $viewToRender = $notFound;
        }

        require_once $headerDefault;
        require_once $viewToRender;
        require_once $footer;
    }
}