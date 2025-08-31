<?php

namespace App\DataMapper;

use Pimcore\Model\DataObject\Brand;
use Pimcore\Model\DataObject\Data\UrlSlug;

class BrandMapper
{
    public function toArray(Brand $b): array
    {
        return [
            'id'   => (int)$b->getId(),
            'name' => $b->getName(),
            'path' => $b->getFullPath(),
        ];
    }
}
