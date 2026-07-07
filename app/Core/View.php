<?php
namespace App\Core;

class View {
    public static function render($viewPath, $data = [], $layout = 'main'): void {
        extract($data);

        $viewFile = __DIR__ . '/../Views/' . $viewPath . '.php';

        if (!file_exists($viewFile)) {
            $viewFile = __DIR__ . '/../Views/404.php';
        }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        $header = dirname(__DIR__, 1) . '/Views/layout/header.php';
        $footer = dirname(__DIR__, 1) . '/Views/layout/footer.php';
        if($layout == 'main') {
            require $header;
        }
        echo $content;
        if($layout == 'main') {
            require $footer;
        }
    }
}