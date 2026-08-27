<?php

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Models\Menu\MenuRepository;

class WebController extends Controller {
    protected array $menuItems;

    public function __construct() {
        $this->menuItems = (new MenuRepository())->all();
    }

    public function index(): Response {
        return $this->render('home', [
            'pageTitle' => 'Domovská stránka',
            'pageDescription' => 'Vítejte na domovské stránce našeho skvělého MVC projektu.',
            'menuItems' => $this->menuItems,
        ]);
    }

    public function test(): Response {
        return $this->render('test', [
            'pageTitle' => 'Testovací stránka',
            'pageDescription' => 'Tato stránka slouží k otestování funkčnosti našeho MVC frameworku.',
            'menuItems' => $this->menuItems,
        ]);
    }
}