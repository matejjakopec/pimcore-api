<?php

namespace App\Command\Seeder;

use Faker\Factory;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Data\UrlSlug;
use Pimcore\Model\DataObject\Folder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:brands',
    description: 'Create a bunch of Brand objects for testing'
)]
class SeedBrandsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'How many brands to create', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $count  = (int)$input->getOption('count');
        $path   = '/Brands';

        $faker = Factory::create();

        $parent = $this->ensureFolderPath($path);

        $created = 0;
        for ($i = 1; $i <= $count; $i++) {
            $name = $faker->company;
            $slug = $this->slugify($name);
            $key  = $slug;

            $finalKey = $key;
            $dedupe = 1;
            while (DataObject::getByPath(rtrim($path, '/') . '/' . $finalKey)) {
                $finalKey = $key . '-' . (++$dedupe);
            }

            $brand = new DataObject\Brand();
            $brand->setParent($parent);
            $brand->setKey($finalKey);
            $brand->setPublished(true);

            $brand->setName($name);
            $brand->setDescription($faker->catchPhrase);

            $brand->setSlug([
                new UrlSlug('/' . $finalKey, null),
            ]);

            $brand->save();
            $created++;
        }

        $io->success("Created {$created}/{$count} random brands in '{$path}'.");
        return Command::SUCCESS;
    }

    private function ensureFolderPath(string $path): Folder
    {
        $path = '/' . trim($path, '/');
        $parts = array_values(array_filter(explode('/', $path)));
        $current = Folder::getById(1);
        $builtPath = '';

        foreach ($parts as $part) {
            $builtPath .= '/' . $part;
            $existing = DataObject::getByPath($builtPath);
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
