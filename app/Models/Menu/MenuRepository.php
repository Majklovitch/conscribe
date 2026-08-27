<?php

namespace App\Models\Menu;

class MenuRepository {
    /**
     * @var MenuItem[]
     */
    private array $items = [];

    public function __construct() {
        $this->add('Home', '/');
        $this->add("Test", '/test');
    }

    /**
     * @param MenuItem[] $children
     */
    public function add(string $name, string $link, array $children = []): self {
        $active = $this->isLinkActive($link);

        // An active child makes the parent active too.
        foreach ($children as $child) {
            if ($child->active) {
                $active = true;
                break;
            }
        }

        $this->items[] = new MenuItem($name, $link, $active, $children);
        return $this;
    }

    public function addSubmenu(string $parentName, string $name, string $link): self {
        $parent = $this->findItemByName($this->items, $parentName);
        if ($parent !== null) {
            $active = $this->isLinkActive($link);
            $child = new MenuItem($name, $link, $active);
            $parent->children[] = $child;

            if ($child->active) {
                $this->bubbleActive($this->items, $parentName);
            }
        }
        return $this;
    }

    /**
     * @param MenuItem[] $items
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
     * Bubbles the active state up into all parent items.
     *
     * @param MenuItem[] $items
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
     * @return MenuItem[]
     */
    public function all(): array {
        return $this->items;
    }

    public function isLinkActive(string $link): bool {
        if (str_starts_with($link, '/#') || str_starts_with($link, '#')) {
            return false;
        }

        $currentPage = defined('CURRENT_PAGE') ? CURRENT_PAGE : 'home';

        $currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $currentUri = '/' . trim($currentUri, '/');

        $linkUri = parse_url($link, PHP_URL_PATH) ?? '/';
        $linkUri = '/' . trim($linkUri, '/');

        if ($currentUri === $linkUri) {
            return true;
        }

        if ($linkUri === '/' || $linkUri === '/home') {
            return $currentPage === 'home' || $currentUri === '/home';
        }

        return trim($linkUri, '/') === $currentPage;
    }
}

