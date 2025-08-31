<?php

namespace App\DataMapper;

use Pimcore\Model\DataObject\Category;

class CategoryMapper
{
    public function toArray(Category $c): array
    {
        return [
            'id'       => (int)$c->getId(),
            'name'     => $c->getName(),
            'path'     => $c->getFullPath(),
            'parentId' => $c->getParent()?->getId(),
        ];
    }
}
