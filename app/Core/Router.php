<?php
namespace App\Core;

class Router {

    public function run(): void {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
        $uri = trim($uri, '/');

        if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|svg)$/i', $uri)) {
            $this->error(404, 'File was not found.');
        }

        $parts = $uri === '' ? [] : array_values(array_filter(explode('/', $uri), 'strlen'));
        /*
        $lang = 'cs';
        $supportedLanguages = ['cs', 'en', 'pl'];

        if (!empty($parts) && in_array($parts[0], $supportedLanguages, true)) {
            $lang = array_shift($parts);
        }
*/
        $page = $parts[0] ?? 'home';
        $actionName = $page === 'home' ? 'index' : $page;
        $params = array_slice($parts, 1);

        /*if (!defined('LANG')) {
            define('LANG', $lang);
        }*/

        if (!defined('CURRENT_PAGE')) {
            define('CURRENT_PAGE', $page);
        }

        if($actionName == 'admin') {
            $controllerClass = "App\\Controllers\\AdminController";
        } else {
            $controllerClass = "App\\Controllers\\WebController";
        }
        if (class_exists($controllerClass)) {
            $controller = new $controllerClass();

            if (method_exists($controller, $actionName)) {
                call_user_func_array([$controller, $actionName], $params);
            } else {
                $this->error(404, "Action '$actionName' in $controllerClass was not found.");
            }
        }
    }

    private function error($code, $message): void{
        http_response_code($code);

        $data = [
            'pageTitle' => "Error $code",
            'code' => (int) $code,
            'message' => (string) $message,
        ];

        View::render('404', $data);
        exit;
    }
}