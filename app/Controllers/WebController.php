<?php

namespace App\Controllers;

use App\Core\Controller;
use Modules\LanguageMutations\Core;

class WebController extends Controller {
    public function index(): void {
        $this->view('home', [
            'pageTitle' => 'Domovská stránka',
            'pageDescription' => 'Vítejte na domovské stránce našeho skvělého MVC projektu.'
        ]);
    }
    public function test(): void {
        $this->view('test', [
            'pageTitle' => 'Testovací stránka',
            'pageDescription' => 'Tato stránka slouží k otestování funkčnosti našeho MVC frameworku.'
        ]);
    }
}