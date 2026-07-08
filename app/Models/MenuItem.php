<?php

namespace App\Models;

class MenuItem {
    public string $name;
    public string $link;
    public bool $active;
    /**
     * @var MenuItem[]
     */
    public array $children = [];

    /**
     * MenuItem constructor.
     *
     * @param string $name
     * @param string $link
     * @param bool $active
     * @param MenuItem[] $children
     */
    public function __construct(string $name, string $link, bool $active = false, array $children = []) {
        $this->name = $name;
        $this->link = $link;
        $this->active = $active;
        $this->children = $children;
    }
}

