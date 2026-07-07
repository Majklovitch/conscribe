<?php

namespace App\Models;

class MenuItem {
    public string $name;
    public string $link;
    public bool $active;

    public function __construct(string $name, string $link, bool $active = false) {
        $this->name = $name;
        $this->link = $link;
        $this->active = $active;
    }
}
