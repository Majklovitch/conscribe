<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\MenuRepository;
use Modules\LanguageMutations\Core;

class WebController extends Controller {
    protected array $menuItems;

    public function __construct() {
        $this->menuItems = (new MenuRepository())->all();
    }

    public function index(): void {
        $this->view('home', [
            'pageTitle' => 'Domovská stránka',
            'pageDescription' => 'Vítejte na domovské stránce našeho skvělého MVC projektu.',
            'menuItems' => $this->menuItems,
        ]);
    }
    public function test(): void {
        $this->view('test', [
            'pageTitle' => 'Testovací stránka',
            'pageDescription' => 'Tato stránka slouží k otestování funkčnosti našeho MVC frameworku.',
            'menuItems' => $this->menuItems,
        ]);
    }
}