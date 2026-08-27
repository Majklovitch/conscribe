<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Http\Controller;
use App\Models\Menu\MenuRepository;

class WebController extends Controller {
    protected array $menuItems;

    public function __construct() {
        $this->menuItems = (new MenuRepository())->all();
    }

    public function index() {
        return $this->render('home', [
            'pageTitle' => 'Home',
            'pageDescription' => 'ConscribePHP - a lightweight PHP MVC base with a router, templates, CSRF protection, a database layer, logging, an image pipeline, a module system and security headers.',
            'menuItems' => $this->menuItems,
            'appVersion' => (string) Config::get('conscribe.ver', ''),
            'features' => $this->features(),
        ]);
    }

    public function test() {
        return $this->render('test', [
            'pageTitle' => 'Test page',
            'pageDescription' => 'This page is here to check that the MVC framework works as expected.',
            'menuItems' => $this->menuItems,
            'appVersion' => (string) Config::get('conscribe.ver', ''),
        ]);
    }

    /**
     * Content of the overview on the home page. It lives here so the template
     * stays purely presentational and the text can be changed in one place.
     *
     * @return array<int, array{icon: string, title: string, text: string}>
     */
    protected function features(): array {
        return [
            [
                'icon'  => '⇄',
                'title' => 'Router and controllers',
                'text'  => 'Explicit GET/POST route table with typed parameters, automatic HEAD handling, 405 with Allow header and pluggable error pages.',
            ],
            [
                'icon'  => '◫',
                'title' => 'Templates with a layout',
                'text'  => 'Views are wrapped in the header and footer automatically and receive data in an isolated scope. Reusable components included.',
            ],
            [
                'icon'  => '⛨',
                'title' => 'CSRF and hardened sessions',
                'text'  => 'A form token in a single call, cookies with SameSite, HttpOnly and Secure, lazy start and automatic ID rotation.',
            ],
            [
                'icon'  => '⚑',
                'title' => 'Logging and error pages',
                'text'  => 'Monolog with daily rotation, Whoops in development and a clean error page in production. Global handlers for exceptions and fatals.',
            ],
            [
                'icon'  => '◱',
                'title' => 'Image pipeline',
                'text'  => 'On-demand resize and WebP conversion with Imagick/GD auto-fallback, named presets, disk cache and atomic writes.',
            ],
            [
                'icon'  => '↻',
                'title' => 'Asset cache-busting',
                'text'  => 'A version in the URL based on the file modification time, so browsers never hold on to stale CSS or JS.',
            ],
            [
                'icon'  => '⛁',
                'title' => 'Database layer',
                'text'  => 'Lazy PDO singleton, Repository base with CRUD, pagination and transactions, Model base with typed hydration and ArrayAccess.',
            ],
            [
                'icon'  => '⊞',
                'title' => 'HTTP objects',
                'text'  => 'Immutable Request with query, body, JSON, headers, cookies and files. Response with header canonicalization and factories for HTML, JSON and redirects.',
            ],
            [
                'icon'  => '⌘',
                'title' => 'Module system',
                'text'  => 'Drop-in folders under app/Modules/ that can boot, rewrite the request, register routes and load their own helpers.',
            ],
            [
                'icon'  => '⛊',
                'title' => 'Security headers',
                'text'  => 'HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy and Permissions-Policy set by default. Open-redirect and SVG XSS protection.',
            ],
            [
                'icon'  => '☰',
                'title' => 'Tracking and consent',
                'text'  => 'GA4, Google Tag, Google Ads, Sklik and Facebook Pixel render only when configured. Cookie Consent v3 built in.',
            ],
        ];
    }
}
