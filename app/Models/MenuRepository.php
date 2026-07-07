<?php

namespace App\Models;

class MenuRepository {
    /**
     * @var MenuItem[]
     */
    private array $items = [];

    public function __construct() {
        // Populate default menu items
        $this->add('Domů', '/');
        $this->add('Test', '/test');
    }

    /**
     * Add a menu item to the repository.
     *
     * @param string $name
     * @param string $link
     * @return self
     */
    public function add(string $name, string $link): self {
        $active = $this->isLinkActive($link);
        $this->items[] = new MenuItem($name, $link, $active);
        return $this;
    }

    /**
     * Get all menu items.
     *
     * @return MenuItem[]
     */
    public function all(): array {
        return $this->items;
    }

    /**
     * Check if the given link matches the current page or URI.
     *
     * @param string $link
     * @return bool
     */
    private function isLinkActive(string $link): bool {
        $currentPage = defined('CURRENT_PAGE') ? CURRENT_PAGE : 'home';

        // Get current path from request URI
        $currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $currentUri = '/' . trim($currentUri, '/');
        
        $linkUri = parse_url($link, PHP_URL_PATH) ?? '/';
        $linkUri = '/' . trim($linkUri, '/');

        // Check if exact match
        if ($currentUri === $linkUri) {
            return true;
        }

        // Special handling for home page
        if ($linkUri === '/' || $linkUri === '/home') {
            return $currentPage === 'home' || $currentUri === '/home';
        }

        // Check if the current page matches the link path segment
        return trim($linkUri, '/') === $currentPage;
    }
}
