<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ProductQuery
{
    #[Assert\PositiveOrZero]
    public ?int $brandId = null;

    #[Assert\PositiveOrZero]
    public ?int $categoryId = null;

    /** Free text: matches name or SKU */
    public ?string $q = null;

    #[Assert\Type('numeric')]
    public $priceMin = null;

    #[Assert\Type('numeric')]
    public $priceMax = null;

    #[Assert\Type('numeric')]
    public $stockMin = null;

    #[Assert\Type('numeric')]
    public $stockMax = null;

    /** @Assert\Choice({"name","sku","price","stockQuantity","weight"}) */
    public string $sort = 'name';

    /** @Assert\Choice({"asc","desc","ASC","DESC"}) */
    public string $dir = 'asc';

    #[Assert\Positive]
    public int $page = 1;

    #[Assert\Range(min: 1, max: 1000000)]
    public int $perPage = 25;

    public static function fromArray(array $q): self
    {
        $dto = new self();
        $dto->brandId    = isset($q['brandId']) ? (int)$q['brandId'] : null;
        $dto->categoryId = isset($q['categoryId']) ? (int)$q['categoryId'] : null;
        $dto->q          = $q['q'] ?? null;
        $dto->priceMin   = $q['priceMin'] ?? null;
        $dto->priceMax   = $q['priceMax'] ?? null;
        $dto->stockMin   = $q['stockMin'] ?? null;
        $dto->stockMax   = $q['stockMax'] ?? null;
        $dto->sort       = $q['sort'] ?? 'name';
        $dto->dir        = $q['dir'] ?? 'asc';
        $dto->page       = isset($q['page']) ? max(1, (int)$q['page']) : 1;
        $dto->perPage    = isset($q['perPage']) ? max(1, (int)$q['perPage']) : 25;
        return $dto;
    }
}
