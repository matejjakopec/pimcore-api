<?php

namespace App\Controller\Api\Sql;

use App\DataMapper\Sql\ProductMapper;
use App\Dto\ProductQuery;
use App\Dto\ProductUpdate;
use Faker\Factory;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Brand;
use Pimcore\Model\DataObject\Category;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Product\Listing as ProductListing;
use Pimcore\Model\DataObject\QuantityValue\Unit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/sql/product', name: 'sql_product_')]
class ProductController extends AbstractController
{
    public function __construct(private readonly ProductMapper $mapper)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function index(Request $request, ValidatorInterface $validator): JsonResponse
    {
        $dto = ProductQuery::fromArray($request->query->all());

        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            $errs = [];
            foreach ($errors as $e) {
                $errs[] = ['field' => $e->getPropertyPath(), 'message' => $e->getMessage()];
            }
            return $this->json(['errors' => $errs], 400);
        }

        $list = new ProductListing();

        $conditions = [];
        $params = [];

        if ($dto->q) {
            $conditions[] = '(name LIKE ? OR SKU LIKE ?)';
            $like = '%'.$dto->q.'%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($dto->brandId !== null) {
            $conditions[] = 'brand__id = ?';
            $params[] = $dto->brandId;
        }

        if ($dto->categoryId !== null) {
            $conditions[] = 'category__id = ?';
            $params[] = $dto->categoryId;
        }

        if ($dto->priceMin !== null) {
            $conditions[] = 'price >= ?';
            $params[] = (float)$dto->priceMin;
        }

        if ($dto->priceMax !== null) {
            $conditions[] = 'price <= ?';
            $params[] = (float)$dto->priceMax;
        }

        if ($dto->stockMin !== null) {
            $conditions[] = 'stockQuantity >= ?';
            $params[] = (float)$dto->stockMin;
        }

        if ($dto->stockMax !== null) {
            $conditions[] = 'stockQuantity <= ?';
            $params[] = (float)$dto->stockMax;
        }

        if ($conditions) {
            $list->setCondition(implode(' AND ', $conditions), $params);
        }

        $sortable = ['name','sku','price','stockQuantity','weight'];
        $sort = in_array($dto->sort, $sortable, true) ? $dto->sort : 'name';
        $dir  = strtoupper($dto->dir) === 'DESC' ? 'DESC' : 'ASC';

        $list->setOrderKey($sort);
        $list->setOrder($dir);

        $perPage = $dto->perPage;
        $page = $dto->page;
        $offset = ($page - 1) * $perPage;

        $list->setLimit($perPage);
        $list->setOffset($offset);

        $total = $list->getTotalCount();
        $items = [];
        foreach ($list as $product) {
            $items[] = $this->mapper->toArray($product);
        }

        return $this->json([
            'meta' => [
                'page'     => $page,
                'perPage'  => $perPage,
                'total'    => $total,
                'pages'    => (int)ceil($total / max(1, $perPage)),
                'sort'     => $sort,
                'dir'      => $dir,
                'filters'  => [
                    'q'          => $dto->q,
                    'brandId'    => $dto->brandId,
                    'categoryId' => $dto->categoryId,
                    'priceMin'   => $dto->priceMin,
                    'priceMax'   => $dto->priceMax,
                    'stockMin'   => $dto->stockMin,
                    'stockMax'   => $dto->stockMax,
                ],
            ],
            'data' => $items,
        ]);
    }

    #[Route('/seed', name: 'seed', methods: ['POST'])]
    public function seedSql(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];
        $count = (int)($payload['count'] ?? 0);
        if ($count < 1) {
            return $this->json(['error' => 'Provide {"count": <positive integer>}'], 400);
        }

        $parentPath = '/Products';
        $parent = DataObject::getByPath($parentPath);
        if (!$parent instanceof Folder) {
            \Pimcore\Model\DataObject\Service::createFolderByPath($parentPath);
            $parent = DataObject::getByPath($parentPath);
        }

        $brandIds = [];
        foreach (new Brand\Listing() as $b) { $brandIds[] = (int)$b->getId(); }

        $catIds = [];
        foreach (new Category\Listing() as $c) { $catIds[] = (int)$c->getId(); }

        if (!$brandIds || !$catIds) {
            return $this->json(['error' => 'Seed some Brands and Categories first.'], 400);
        }

        $eurUnit = Unit::getByAbbreviation('EUR') ?: null;

        $faker = Factory::create();
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $sku = strtoupper($faker->lexify('???') . '-' . $faker->numerify('#####'));
            $key = strtolower(str_replace(' ', '-', $sku));

            if (DataObject::getByPath(rtrim($parentPath, '/') . '/' . $key)) {
                $i--;
                continue;
            }

            $p = new Product();
            $p->setParent($parent);
            $p->setKey($key);
            $p->setPublished(true);

            $p->setName($faker->company());
            $p->setSKU($sku);
            $p->setDescription($faker->paragraph());

            $priceVal = round($faker->randomFloat(2, 1, 9999), 2);
            $p->setPrice(new QuantityValue($priceVal, $eurUnit));

            $p->setStockQuantity($faker->numberBetween(0, 5000));
            $p->setWeight(round($faker->randomFloat(2, 0.1, 250), 2));

            $brandId = $brandIds[array_rand($brandIds)];
            $catId   = $catIds[array_rand($catIds)];

            $p->setBrand(Brand::getById($brandId));
            $p->setCategory(Category::getById($catId));

            $p->save();

            $created[] = [
                'id'   => (int)$p->getId(),
                'sku'  => $sku,
                'name' => $p->getName(),
            ];
        }

        return $this->json([
            'meta' => [
                'requested' => $count,
                'created'   => count($created),
            ],
            'data' => $created,
        ]);
    }

    #[Route('/bulk-price', name: 'bulk_price', methods: ['POST'])]
    public function bulkPrice(Request $request): JsonResponse
    {
        $p = json_decode($request->getContent(), true) ?: [];
        if (!isset($p['percent']) || !is_numeric($p['percent'])) {
            return $this->json(['error' => 'Please provide {"percent": number}'], 400);
        }
        $percent    = (float)$p['percent'];
        $multiplier = 1 + ($percent / 100.0);
        $countLimit = isset($p['count']) && is_numeric($p['count']) && (int)$p['count'] > 0 ? (int)$p['count'] : null;

        $list = new \Pimcore\Model\DataObject\Product\Listing();
        $list->setOrderKey('oo_id');
        $list->setOrder('ASC');
        if ($countLimit !== null) {
            $list->setLimit($countLimit);
        }

        $total   = $list->getTotalCount();
        $updated = 0; $skippedNoPrice = 0; $skippedNullValue = 0; $errors = [];

        foreach ($list as $product) {
            try {
                $qv = $product->getPrice();
                if (!$qv instanceof \Pimcore\Model\DataObject\Data\QuantityValue) { $skippedNoPrice++; continue; }
                $value = $qv->getValue();
                if ($value === null) { $skippedNullValue++; continue; }

                $qv->setValue(round((float)$value * $multiplier, 2));
                $product->setPrice($qv);
                $product->save();
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => (int)$product->getId(), 'message' => $e->getMessage()];
                if (count($errors) >= 10) { /* keep response small */ }
            }
        }

        return $this->json([
            'meta' => [
                'percent' => $percent,
                'matched' => $countLimit ?? $total,
                'updated' => $updated,
                'skipped' => ['noPriceField' => $skippedNoPrice, 'nullPriceValue' => $skippedNullValue],
                'errors'  => count($errors),
            ],
            'errors' => array_slice($errors, 0, 10),
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, ValidatorInterface $validator): JsonResponse
    {
        /** @var Product|null $product */
        $product = Product::getById($id);
        if (!$product instanceof Product) {
            return $this->json(['error' => 'Product not found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $dto = ProductUpdate::fromArray($payload);
        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            $errs = [];
            foreach ($errors as $e) {
                $errs[] = ['field' => $e->getPropertyPath(), 'message' => $e->getMessage()];
            }
            return $this->json(['errors' => $errs], 400);
        }

        if (array_key_exists('name', $payload)) {
            $product->setName($dto->name);
        }
        if (array_key_exists('sku', $payload)) {
            $product->setSKU($dto->sku);
        }
        if (array_key_exists('description', $payload)) {
            $product->setDescription($dto->description);
        }

        if (array_key_exists('price', $payload)) {
            $qv = $this->makeQuantityValue($dto->price);
            $product->setPrice($qv);
        }

        if (array_key_exists('stockQuantity', $payload)) {
            $product->setStockQuantity($dto->stockQuantity !== null ? (float)$dto->stockQuantity : null);
        }
        if (array_key_exists('weight', $payload)) {
            $product->setWeight($dto->weight !== null ? (float)$dto->weight : null);
        }

        if (array_key_exists('brandId', $payload)) {
            $brand = $dto->brandId ? Brand::getById($dto->brandId) : null;
            if ($dto->brandId && !$brand) {
                return $this->json(['error' => 'Brand not found'], 400);
            }
            $product->setBrand($brand);
        }

        if (array_key_exists('categoryId', $payload)) {
            $category = $dto->categoryId ? Category::getById($dto->categoryId) : null;
            if ($dto->categoryId && !$category) {
                return $this->json(['error' => 'Category not found'], 400);
            }
            $product->setCategory($category);
        }

        if (array_key_exists('published', $payload)) {
            $product->setPublished((bool)$dto->published);
        }

        $product->save();

        return $this->json($this->mapper->toArray($product));
    }

    private function makeQuantityValue(?array $price): ?QuantityValue
    {
        if ($price === null) {
            return null;
        }

        $value = $price['value'] ?? null;
        $unitStr = $price['unit'] ?? null;

        if ($value === null && $unitStr === null) {
            return null;
        }

        $unit = null;
        if ($unitStr !== null && $unitStr !== '') {
            $unit = Unit::getByAbbreviation($unitStr);
            if (!$unit) {
                $unit = Unit::getById($unitStr);
            }
        }

        return new QuantityValue(
            $value !== null ? (float)$value : null,
            $unit
        );
    }
}
