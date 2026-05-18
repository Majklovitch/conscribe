<?php

namespace App\Controllers;

use App\Core\Controller;
use Modules\LanguageMutations\Core;

class WebController extends Controller {
    public function index(): void {
        $this->view('home');
    }
    public function test(): void {
        $this->view('test');
    }
}