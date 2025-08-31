<?php

namespace App\Controller\Api;

use App\DataMapper\BrandMapper;
use App\DataMapper\CategoryMapper;
use Pimcore\Model\DataObject\Brand\Listing as BrandListing;
use Pimcore\Model\DataObject\Category\Listing as CategoryListing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class TaxonomyController extends AbstractController
{
    public function __construct(
        private BrandMapper $brandMapper,
        private CategoryMapper $categoryMapper
    ) {}

    #[Route('/brands', name: 'api_brands_index', methods: ['GET'])]
    public function brands(): JsonResponse
    {
        $list = new BrandListing();
        $list->setOrderKey('name');
        $list->setOrder('ASC');

        $data = [];
        foreach ($list as $brand) {
            $data[] = $this->brandMapper->toArray($brand);
        }

        return $this->json($data);
    }

    #[Route('/categories', name: 'api_categories_index', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        $list = new CategoryListing();
        $list->setOrderKey('name');
        $list->setOrder('ASC');

        $data = [];
        foreach ($list as $cat) {
            $data[] = $this->categoryMapper->toArray($cat);
        }

        return $this->json($data);
    }
}
