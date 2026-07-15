<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Repositories\MenuRepository;

class WebController extends Controller {
    protected array $menuItems;

    public function __construct() {
        $this->menuItems = (new MenuRepository())->all();
    }

    public function index(Request $request): Response {
        return $this->render('home', [
            'pageTitle' => 'Domovská stránka',
            'pageDescription' => 'Vítejte na domovské stránce našeho skvělého MVC projektu.',
            'menuItems' => $this->menuItems,
        ]);
    }
    public function test(Request $request): Response {
        return $this->render('test', [
            'pageTitle' => 'Testovací stránka',
            'pageDescription' => 'Tato stránka slouží k otestování funkčnosti našeho MVC frameworku.',
            'menuItems' => $this->menuItems,
        ]);
    }
}