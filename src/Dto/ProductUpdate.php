<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ProductUpdate
{
    #[Assert\Length(min: 1, max: 255)]
    public ?string $name = null;

    #[Assert\Length(min: 1, max: 255)]
    public ?string $sku = null;

    public ?string $description = null;

    /** price as { "value": <number|null>, "unit": "<string|null>" } */
    #[Assert\Type('array')]
    public ?array $price = null;

    #[Assert\Type('numeric')]
    public $stockQuantity = null; // float|int|null

    #[Assert\Type('numeric')]
    public $weight = null; // float|int|null

    #[Assert\Positive]
    public ?int $brandId = null;

    #[Assert\Positive]
    public ?int $categoryId = null;

    public ?bool $published = null;

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->name          = array_key_exists('name', $data) ? $data['name'] : null;
        $dto->sku           = array_key_exists('sku', $data) ? $data['sku'] : null;
        $dto->description   = array_key_exists('description', $data) ? $data['description'] : null;
        $dto->price         = array_key_exists('price', $data) ? $data['price'] : null;
        $dto->stockQuantity = array_key_exists('stockQuantity', $data) ? $data['stockQuantity'] : null;
        $dto->weight        = array_key_exists('weight', $data) ? $data['weight'] : null;
        $dto->brandId       = array_key_exists('brandId', $data) ? (is_null($data['brandId']) ? null : (int)$data['brandId']) : null;
        $dto->categoryId    = array_key_exists('categoryId', $data) ? (is_null($data['categoryId']) ? null : (int)$data['categoryId']) : null;
        $dto->published     = array_key_exists('published', $data) ? (bool)$data['published'] : null;
        return $dto;
    }
}
