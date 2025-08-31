<?php

namespace App\DataMapper\Sql;

use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\DataObject\Product;

class ProductMapper
{
    public function toArray(Product $p): array
    {
        $brand = $p->getBrand();
        $category = $p->getCategory();

        return [
            'id'            => (int)$p->getId(),
            'key'           => $p->getKey(),
            'path'          => $p->getFullPath(),
            'name'          => $p->getName(),
            'sku'           => $p->getSKU(),
            'description'   => $p->getDescription(),
            'price'         => $this->qvArray($p->getPrice()),
            'stockQuantity' => $p->getStockQuantity(),
            'weight'        => $p->getWeight(),

            'brand' => $brand ? [
                'id'   => (int)$brand->getId(),
                'name' => $brand->getName(),
                'path' => $brand->getFullPath(),
            ] : null,

            'category' => $category ? [
                'id'   => (int)$category->getId(),
                'name' => $category->getName(),
                'path' => $category->getFullPath(),
            ] : null,
        ];
    }

    private function qvArray(mixed $qv): ?array
    {
        if (!$qv instanceof QuantityValue) {
            return null;
        }
        $unit = $qv->getUnit();
        return [
            'value' => $qv->getValue(),
            'unit'  => $unit ? ($unit->getAbbreviation() ?? $unit->getId()) : null,
        ];
    }
}
