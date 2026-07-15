<?php

namespace App\Models\Repositories;

use App\Models\MenuItem;

class MenuRepository {
    /**
     * @var MenuItem[]
     */
    private array $items = [];

    public function __construct() {
        // Populate default menu items
        $this->add('Domů', '/');
        $this->add("Test", '/test');
    }

    /**
     * Add a menu item to the repository.
     *
     * @param string $name
     * @param string $link
     * @param MenuItem[] $children
     * @return self
     */
    public function add(string $name, string $link, array $children = []): self {
        $active = $this->isLinkActive($link);
        
        // If any of the children are active, parent should also be active
        foreach ($children as $child) {
            if ($child->active) {
                $active = true;
                break;
            }
        }

        $this->items[] = new MenuItem($name, $link, $active, $children);
        return $this;
    }

    /**
     * Add a submenu item to a parent menu item by name.
     *
     * @param string $parentName Name of the parent menu item.
     * @param string $name Name of the submenu item.
     * @param string $link Link of the submenu item.
     * @return self
     */
    public function addSubmenu(string $parentName, string $name, string $link): self {
        $parent = $this->findItemByName($this->items, $parentName);
        if ($parent !== null) {
            $active = $this->isLinkActive($link);
            $child = new MenuItem($name, $link, $active);
            $parent->children[] = $child;
            
            // If the child is active, cascade active status up
            if ($child->active) {
                $this->bubbleActive($this->items, $parentName);
            }
        }
        return $this;
    }

    /**
     * Find a MenuItem by its name recursively.
     *
     * @param array $items
     * @param string $name
     * @return MenuItem|null
     */
    private function findItemByName(array $items, string $name): ?MenuItem {
        foreach ($items as $item) {
            if ($item->name === $name) {
                return $item;
            }
            if (!empty($item->children)) {
                $found = $this->findItemByName($item->children, $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Bubbles the active state up to all parent items.
     *
     * @param array $items
     * @param string $targetName
     * @return bool
     */
    private function bubbleActive(array $items, string $targetName): bool {
        foreach ($items as $item) {
            if ($item->name === $targetName) {
                $item->active = true;
                return true;
            }
            if (!empty($item->children)) {
                if ($this->bubbleActive($item->children, $targetName)) {
                    $item->active = true;
                    return true;
                }
            }
        }
        return false;
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
    public function isLinkActive(string $link): bool {
        if (str_starts_with($link, '/#') || str_starts_with($link, '#')) {
            return false;
        }

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

