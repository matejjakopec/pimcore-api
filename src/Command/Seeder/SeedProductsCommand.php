<?php

namespace App\Command\Seeder;

use Faker\Factory;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Folder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:products',
    description: 'Seeds Product objects under /Products, assigning random existing Brand and Category'
)]
class SeedProductsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'How many products to create', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $faker = Factory::create();

        $count = (int)$input->getOption('count');
        $parentPath = '/Products';

        $parent = $this->ensureFolderPath($parentPath);

        $brandList = new DataObject\Brand\Listing();
        $brands = iterator_to_array($brandList);

        $catList = new DataObject\Category\Listing();
        $categories = iterator_to_array($catList);

        if (count($brands) === 0 || count($categories) === 0) {
            $io->error('You need at least one published Brand and one published Category.');
            return Command::FAILURE;
        }

        $created = 0;

        for ($i = 0; $i < $count; $i++) {
            $name = ucfirst($faker->word) . ' ' . $faker->companySuffix;
            $sku  = strtoupper($faker->bothify('???-#####'));
            $desc = $faker->paragraph();
            $price = round($faker->randomFloat(2, 1, 9999), 2);
            $stock = $faker->numberBetween(0, 5000);
            $weight = round($faker->randomFloat(2, 0.1, 200), 2);

            $brand = $brands[array_rand($brands)];
            $category = $categories[array_rand($categories)];

            $keyBase = $this->slugify($sku);
            $key = $keyBase ?: 'product';
            $suffix = 1;
            while (DataObject::getByPath($parentPath . '/' . $key)) {
                $key = $keyBase . '-' . (++$suffix);
            }

            $product = new DataObject\Product();
            $product->setParent($parent);
            $product->setKey($key);
            $product->setPublished(true);

            $product->setName($name);
            $product->setSKU($sku);
            $product->setDescription($desc);
            $product->setPrice(new DataObject\Data\QuantityValue($price, DataObject\QuantityValue\Unit::getByAbbreviation('EUR')));
            $product->setStockQuantity($stock);
            $product->setWeight($weight);

            $product->setBrand($brand);
            $product->setCategory($category);

            $product->save();
            $created++;
        }

        $io->success("Created {$created} Product objects under {$parentPath}.");
        return Command::SUCCESS;
    }

    private function ensureFolderPath(string $path): Folder
    {
        $path = '/' . trim($path, '/');
        $parts = array_values(array_filter(explode('/', $path)));
        $current = Folder::getById(1);
        $built = '';

        foreach ($parts as $part) {
            $built .= '/' . $part;
            $existing = DataObject::getByPath($built);
            if ($existing instanceof Folder) {
                $current = $existing;
                continue;
            }
            $folder = new Folder();
            $folder->setKey($part);
            $folder->setParent($current);
            $folder->save();
            $current = $folder;
        }

        return $current;
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('~[^\pL\d]+~u', '-', $value);
        $value = trim($value, '-');
        $value = preg_replace('~[-]+~', '-', $value);
        return $value ?: 'n-a';
    }
}
