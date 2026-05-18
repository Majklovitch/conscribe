<?php
namespace App\Controllers;

use App\Core\Controller;

class AdminController extends Controller {
    public function admin(): void {
        $this->view('admin/dashboard', [], 'admin');
    }
}