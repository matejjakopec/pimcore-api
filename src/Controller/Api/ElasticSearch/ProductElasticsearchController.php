<?php

namespace App\Controller\Api\ElasticSearch;

use App\DataMapper\Es\EsProductMapper;
use App\Dto\ProductQuery;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Faker\Factory;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Brand;
use Pimcore\Model\DataObject\Category;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\QuantityValue\Unit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/es/product', name: 'elasticsearch_product_')]
class ProductElasticsearchController extends AbstractController
{
    public function __construct(
        private Client $es,
        private EsProductMapper $esMapper
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function searchEs(Request $request, ValidatorInterface $validator): JsonResponse
    {
        $dto = ProductQuery::fromArray($request->query->all());

        $errors = $validator->validate($dto);
        if (\count($errors) > 0) {
            $errs = [];
            foreach ($errors as $e) {
                $errs[] = ['field' => $e->getPropertyPath(), 'message' => $e->getMessage()];
            }
            return $this->json(['errors' => $errs], 400);
        }

        $index   = 'products';
        $perPage = $dto->perPage;
        $from    = max(0, ($dto->page - 1) * $perPage);

        $must   = [];
        $filter = [];

        if ($dto->q) {
            $must[] = [
                'bool' => [
                    'should' => [
                        ['multi_match' => ['query' => $dto->q, 'fields' => ['name^2', 'description']]],
                        ['match' => ['sku_search' => $dto->q]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ];
        }
        if ($dto->brandId !== null) {
            $filter[] = ['term' => ['brand.id' => (int)$dto->brandId]];
        }
        if ($dto->categoryId !== null) {
            $filter[] = ['term' => ['category.id' => (int)$dto->categoryId]];
        }
        if ($dto->priceMin !== null || $dto->priceMax !== null) {
            $range = [];
            if ($dto->priceMin !== null) $range['gte'] = (float)$dto->priceMin;
            if ($dto->priceMax !== null) $range['lte'] = (float)$dto->priceMax;
            $filter[] = ['range' => ['price.value' => $range]];
        }
        if ($dto->stockMin !== null || $dto->stockMax !== null) {
            $range = [];
            if ($dto->stockMin !== null) $range['gte'] = (float)$dto->stockMin;
            if ($dto->stockMax !== null) $range['lte'] = (float)$dto->stockMax;
            $filter[] = ['range' => ['stockQuantity' => $range]];
        }

        $fieldMap = [
            'name' => 'name.keyword',
            'sku'  => 'sku',
            'price' => 'price.value',
            'stockQuantity' => 'stockQuantity',
            'weight' => 'weight',
            'createdAt' => 'createdAt',
            'updatedAt' => 'updatedAt',
        ];

        $requested = $dto->sort ?: 'name';
        $sortField = $fieldMap[$requested] ?? $fieldMap['name'];
        $dir = strtolower($dto->dir) === 'desc' ? 'desc' : 'asc';

        $body = [
            'track_total_hits' => true,
            'from'  => $from,
            'size'  => $perPage,
            'query' => [
                'bool' => [
                    'must'   => $must,
                    'filter' => $filter,
                ],
            ],
            'sort' => [
                [$sortField => ['order' => $dir]],
                ['_score' => 'desc'],
            ],
        ];

        try {
            $resp  = $this->es->search(['index' => $index, 'body' => $body]);
            $hits  = $resp['hits']['hits'] ?? [];
            $total = is_array($resp['hits']['total'] ?? null)
                ? (int)$resp['hits']['total']['value']
                : (int)($resp['hits']['total'] ?? 0);

            $data = array_map(fn($h) => $this->esMapper->fromSource($h['_source'] ?? []), $hits);

            return $this->json([
                'meta' => [
                    'page'     => $dto->page,
                    'perPage'  => $perPage,
                    'total'    => $total,
                    'pages'    => (int)\ceil($total / max(1, $perPage)),
                    'sort'     => $sortField,
                    'dir'      => $dir,
                    'filters'  => [
                        'q' => $dto->q,
                        'brandId' => $dto->brandId,
                        'categoryId' => $dto->categoryId,
                        'priceMin' => $dto->priceMin,
                        'priceMax' => $dto->priceMax,
                        'stockMin' => $dto->stockMin,
                        'stockMax' => $dto->stockMax,
                    ],
                ],
                'data' => $data,
            ]);
        } catch (ClientResponseException|ServerResponseException $e) {
            return $this->json([
                'error' => 'Elasticsearch error',
                'message' => (string)$e->getMessage(),
            ], 502);
        }
    }

    #[Route('/seed', name: 'seed', methods: ['POST'])]
    public function seed(Request $request): JsonResponse
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
        $bl = new Brand\Listing();
        $bl->setOrderKey('oo_id'); $bl->setOrder('ASC');
        foreach ($bl as $b) { $brandIds[] = (int)$b->getId(); }

        $catIds = [];
        $cl = new Category\Listing();
        $cl->setOrderKey('oo_id'); $cl->setOrder('ASC');
        foreach ($cl as $c) { $catIds[] = (int)$c->getId(); }

        if (!$brandIds || !$catIds) {
            return $this->json(['error' => 'Seed some Brands and Categories first.'], 400);
        }

        $eurUnit = Unit::getByAbbreviation('EUR') ?: null;

        $faker = Factory::create();
        $created = [];
        $bulk = [];

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

            $doc = $this->docFromProduct($p);
            $bulk[] = ['index' => ['_index' => 'products', '_id' => $doc['id']]];
            $bulk[] = $doc;

            $created[] = [
                'id' => (int)$p->getId(),
                'sku' => $sku,
                'name' => $p->getName(),
            ];
        }

        if ($bulk) {
            $resp = $this->es->bulk(['body' => $bulk, 'refresh' => 'wait_for']);
            if (!empty($resp['errors'])) {
                $errs = [];
                foreach ($resp['items'] as $it) {
                    if (!empty($it['index']['error'])) {
                        $errs[] = $it['index']['error'];
                        if (count($errs) >= 5) break;
                    }
                }
                return $this->json([
                    'warning' => 'Products saved in SQL, but some ES index operations failed.',
                    'errors'  => $errs,
                    'created' => $created,
                ], 207);
            }
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
    public function bulkPriceEs(Request $request): JsonResponse
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

        $total          = $list->getTotalCount();
        $matched        = $countLimit ?? $total;
        $updated        = 0;
        $skippedNoPrice = 0;
        $skippedNullVal = 0;
        $errors         = [];

        $bulk = [];
        $bulkChunkDocs = 750;

        foreach ($list as $product) {
            try {
                $qv = $product->getPrice();
                if (!$qv instanceof \Pimcore\Model\DataObject\Data\QuantityValue) { $skippedNoPrice++; continue; }
                $value = $qv->getValue();
                if ($value === null) { $skippedNullVal++; continue; }

                $qv->setValue(round((float)$value * $multiplier, 2));
                $product->setPrice($qv);
                $product->save();
                $updated++;

                $doc = $this->docFromProduct($product);
                $bulk[] = ['index' => ['_index' => 'products', '_id' => $doc['id']]];
                $bulk[] = $doc;

                if (count($bulk) >= $bulkChunkDocs * 2) {
                    $resp = $this->es->bulk(['body' => $bulk]);
                    if (!empty($resp['errors'])) {
                        foreach ($resp['items'] as $it) {
                            if (!empty($it['index']['error'])) {
                                $errors[] = $it['index']['error'];
                                if (count($errors) >= 10) break;
                            }
                        }
                    }
                    $bulk = [];
                }
            } catch (\Throwable $e) {
                $errors[] = ['id' => (int)$product->getId(), 'message' => $e->getMessage()];
                if (count($errors) >= 10) { }
            }
        }

        // Final flush
        if ($bulk) {
            $resp = $this->es->bulk(['body' => $bulk]);
            if (!empty($resp['errors'])) {
                foreach ($resp['items'] as $it) {
                    if (!empty($it['index']['error'])) {
                        $errors[] = $it['index']['error'];
                        if (count($errors) >= 10) break;
                    }
                }
            }
        }
        $this->es->indices()->refresh(['index' => 'products']);

        return $this->json([
            'meta' => [
                'percent' => $percent,
                'matched' => $matched,
                'updated_sql' => $updated,
                'indexed_es'  => $updated,
                'skipped' => ['noPriceField' => $skippedNoPrice, 'nullPriceValue' => $skippedNullVal],
                'errors'  => count($errors),
            ],
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function updateEs(int $id, Request $request, ValidatorInterface $validator): JsonResponse
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

        $applyErr = $this->applyProductPatch($product, $payload);
        if ($applyErr) {
            return $this->json(['error' => $applyErr], 400);
        }

        $product->save();

        $doc = $this->docFromProduct($product);
        try {
            $this->es->index([
                'index' => 'products',
                'id'    => $doc['id'],
                'body'  => $doc,
                'refresh' => 'wait_for',
            ]);
        } catch (ClientResponseException|ServerResponseException $e) {
            return $this->json([
                'error' => 'Saved product, but failed to update Elasticsearch',
                'message' => (string)$e->getMessage(),
                'product' => $this->esMapper->fromSource($doc),
            ], 207);
        }

        return $this->json($this->esMapper->fromSource($doc));
    }

    private function applyProductPatch(Product $product, array $data): ?string
    {
        if (array_key_exists('name', $data)) {
            $name = $data['name'];
            if (!is_null($name) && !is_string($name)) return 'name must be string or null';
            $product->setName($name);
        }

        if (array_key_exists('sku', $data)) {
            $sku = $data['sku'];
            if (!is_null($sku) && !is_string($sku)) return 'sku must be string or null';
            $product->setSKU($sku);
        }

        if (array_key_exists('description', $data)) {
            $desc = $data['description'];
            if (!is_null($desc) && !is_string($desc)) return 'description must be string or null';
            $product->setDescription($desc);
        }

        if (array_key_exists('price', $data)) {
            $price = $data['price'];
            if (!is_null($price) && !is_array($price)) return 'price must be an object or null';
            $product->setPrice($this->makeQuantityValue($price));
        }

        if (array_key_exists('stockQuantity', $data)) {
            $sq = $data['stockQuantity'];
            if (!is_null($sq) && !is_numeric($sq)) return 'stockQuantity must be numeric or null';
            $product->setStockQuantity($sq !== null ? (float)$sq : null);
        }

        if (array_key_exists('weight', $data)) {
            $w = $data['weight'];
            if (!is_null($w) && !is_numeric($w)) return 'weight must be numeric or null';
            $product->setWeight($w !== null ? (float)$w : null);
        }

        if (array_key_exists('brandId', $data)) {
            $brandId = $data['brandId'];
            if (!is_null($brandId) && (!is_numeric($brandId) || (int)$brandId <= 0)) return 'brandId must be positive integer or null';
            $brand = $brandId ? Brand::getById((int)$brandId) : null;
            if ($brandId && !$brand) return 'Brand not found';
            $product->setBrand($brand);
        }

        if (array_key_exists('categoryId', $data)) {
            $categoryId = $data['categoryId'];
            if (!is_null($categoryId) && (!is_numeric($categoryId) || (int)$categoryId <= 0)) return 'categoryId must be positive integer or null';
            $cat = $categoryId ? Category::getById((int)$categoryId) : null;
            if ($categoryId && !$cat) return 'Category not found';
            $product->setCategory($cat);
        }

        if (array_key_exists('published', $data)) {
            $published = $data['published'];
            if (!is_null($published) && !is_bool($published)) return 'published must be boolean or null';
            $product->setPublished((bool)$published);
        }

        return null;
    }


    private function docFromProduct(Product $p): array
    {
        $brand = $p->getBrand();
        $cat   = $p->getCategory();

        $priceVal = null; $priceUnit = null;
        if (($qv = $p->getPrice()) instanceof QuantityValue) {
            $priceVal = $qv->getValue() === null ? null : (float)$qv->getValue();
            $unitObj  = $qv->getUnit();
            $priceUnit = $unitObj ? ($unitObj->getAbbreviation() ?? $unitObj->getId()) : null;
        }

        return [
            'id'          => (int)$p->getId(),
            'key'         => $p->getKey(),
            'path'        => $p->getFullPath(),
            'name'        => $p->getName(),
            'sku'         => $p->getSKU(),
            'sku_search'  => (string)$p->getSKU(),
            'description' => $p->getDescription(),

            'price'         => ['value' => $priceVal, 'unit' => $priceUnit],
            'stockQuantity' => is_numeric($p->getStockQuantity()) ? (float)$p->getStockQuantity() : null,
            'weight'        => is_numeric($p->getWeight()) ? (float)$p->getWeight() : null,

            'brand' => $brand ? [
                'id'   => (int)$brand->getId(),
                'name' => $brand->getName(),
                'path' => $brand->getFullPath(),
            ] : null,
            'category' => $cat ? [
                'id'   => (int)$cat->getId(),
                'name' => $cat->getName(),
                'path' => $cat->getFullPath(),
            ] : null,

            'createdAt' => $p->getCreationDate() ? date('c', (int)$p->getCreationDate()) : null,
            'updatedAt' => $p->getModificationDate() ? date('c', (int)$p->getModificationDate()) : null,
        ];
    }

    private function makeQuantityValue(?array $price): ?QuantityValue
    {
        if ($price === null) {
            return null;
        }
        $value = $price['value'] ?? null;
        $unitStr = $price['unit'] ?? null;

        $unit = null;
        if ($unitStr !== null && $unitStr !== '') {
            $unit = Unit::getByAbbreviation($unitStr) ?: Unit::getById($unitStr);
        }

        return new QuantityValue(
            $value !== null ? (float)$value : null,
            $unit
        );
    }
}
