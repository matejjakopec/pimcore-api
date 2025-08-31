<?php

namespace App\DataMapper\Es;

class EsProductMapper
{
    public function fromSource(array $src): array
    {
        return [
            'id'            => isset($src['id']) ? (int)$src['id'] : null,
            'key'           => $src['key']        ?? null,
            'path'          => $src['path']       ?? null,
            'name'          => $src['name']       ?? null,
            'sku'           => $src['sku']        ?? null,
            'description'   => $src['description']?? null,

            'price' => $this->priceObject($src['price'] ?? null),

            'stockQuantity' => $this->numOrNull($src['stockQuantity'] ?? null),
            'weight'        => $this->numOrNull($src['weight'] ?? null),

            'brand' => $this->simpleRef($src['brand'] ?? null),
            'category' => $this->simpleRef($src['category'] ?? null),
        ];
    }

    private function priceObject(mixed $price): ?array
    {
        if (is_array($price)) {
            return [
                'value' => $this->numOrNull($price['value'] ?? null),
                'unit'  => $price['unit'] ?? null,
            ];
        }
        if (is_numeric($price)) {
            return ['value' => (float)$price, 'unit' => null];
        }
        return null;
    }

    private function numOrNull(mixed $v): float|int|null
    {
        return is_numeric($v) ? (float)$v : null;
    }

    private function simpleRef(mixed $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        return [
            'id'   => isset($v['id']) ? (int)$v['id'] : null,
            'name' => $v['name'] ?? null,
            'path' => $v['path'] ?? null,
        ];
    }
}
