<?php

namespace App\Models\Menu;

use App\Core\Database\Model;

class MenuItem extends Model {
    public string $name;
    public string $link;
    public bool $active;

    /**
     * @var MenuItem[]
     */
    public array $children = [];

    /**
     * @param MenuItem[] $children
     */
    public function __construct(string $name, string $link, bool $active = false, array $children = []) {
        parent::__construct([
            'name'     => $name,
            'link'     => $link,
            'active'   => $active,
            'children' => $children,
        ]);
    }
}
