<?php

namespace App\Command\Search;

use Elastic\Elasticsearch\Client;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Product\Listing as ProductListing;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'search:es:index-products',
    description: '(Re)create index and bulk index all Products into Elasticsearch'
)]
class IndexProductsToElasticsearchCommand extends Command
{
    public function __construct(private Client $es)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index', null, InputOption::VALUE_OPTIONAL, 'Index name', 'products')
            ->addOption('recreate', null, InputOption::VALUE_NONE, 'Drop & recreate index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $index    = (string)$input->getOption('index');
        $recreate = (bool)$input->getOption('recreate');

        if ($recreate && $this->es->indices()->exists(['index' => $index])->asBool()) {
            $this->es->indices()->delete(['index' => $index]);
        }

        if (!$this->es->indices()->exists(['index' => $index])->asBool()) {
            $this->es->indices()->create([
                'index' => $index,
                'body'  => [
                    'settings' => [
                        'number_of_shards'   => 1,
                        'number_of_replicas' => 0,
                        'analysis' => [
                            'analyzer' => [
                                'edge_ngram' => [
                                    'tokenizer' => 'edge_ngram',
                                    'filter'    => ['lowercase'],
                                ],
                            ],
                            'tokenizer' => [
                                'edge_ngram' => [
                                    'type'       => 'edge_ngram',
                                    'min_gram'   => 2,
                                    'max_gram'   => 12,
                                    'token_chars'=> ['letter','digit'],
                                ],
                            ],
                        ],
                    ],
                    'mappings' => [
                        'dynamic' => false,
                        'properties' => [
                            'id'   => ['type' => 'integer'],
                            'key'  => ['type' => 'keyword'],
                            'path' => ['type' => 'keyword'],

                            'name' => [
                                'type'     => 'text',
                                'analyzer' => 'standard',
                                'fields'   => [
                                    'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
                                ],
                            ],
                            'sku'        => ['type' => 'keyword'],
                            'sku_search' => ['type' => 'text', 'analyzer' => 'edge_ngram'],
                            'description'=> ['type' => 'text'],

                            'price' => [
                                'type' => 'object',
                                'properties' => [
                                    'value' => ['type' => 'double'],
                                    'unit'  => ['type' => 'keyword'],
                                ],
                            ],

                            'stockQuantity' => ['type' => 'double'],
                            'weight'        => ['type' => 'double'],

                            'brand' => [
                                'properties' => [
                                    'id'   => ['type' => 'integer'],
                                    'name' => ['type' => 'keyword'],
                                    'path' => ['type' => 'keyword'],
                                ],
                            ],
                            'category' => [
                                'properties' => [
                                    'id'   => ['type' => 'integer'],
                                    'name' => ['type' => 'keyword'],
                                    'path' => ['type' => 'keyword'],
                                ],
                            ],

                            'createdAt' => ['type' => 'date', 'format' => 'strict_date_optional_time||epoch_millis'],
                            'updatedAt' => ['type' => 'date', 'format' => 'strict_date_optional_time||epoch_millis'],
                        ],
                    ],
                ],
            ]);
            $io->writeln("<info>Created index: {$index}</info>");
        }

        $list = new ProductListing();
        $list->setOrderKey('oo_id');
        $list->setOrder('ASC');

        $total = $list->getTotalCount();
        $io->writeln("Indexing {$total} products into '{$index}' ...");

        $batch = [];
        $count = 0;
        $batchSize = 1000;

        foreach ($list as $p) {
            $doc = $this->docFromProduct($p);
            $batch[] = ['index' => ['_index' => $index, '_id' => $doc['id']]];
            $batch[] = $doc;

            if (count($batch) >= $batchSize * 2) {
                $this->flushBatch($batch, $io);
                $count += $batchSize;
                $io->writeln("  -> indexed {$count}/{$total}");
                $batch = [];
            }
        }

        if ($batch) {
            $this->flushBatch($batch, $io);
            $count += intdiv(count($batch), 2);
        }

        $this->es->indices()->refresh(['index' => $index]);

        $io->success("Done. Indexed {$count} products.");
        return Command::SUCCESS;
    }

    private function flushBatch(array $batch, SymfonyStyle $io): void
    {
        $resp = $this->es->bulk(['body' => $batch]);
        if (!empty($resp['errors'])) {
            foreach ($resp['items'] as $it) {
                if (!empty($it['index']['error'])) {
                    $io->writeln('<error>Bulk error: '.json_encode($it['index']['error']).'</error>');
                }
            }
        }
    }

    private function docFromProduct(Product $p): array
    {
        $brand = $p->getBrand();
        $cat   = $p->getCategory();

        $priceValue = null;
        $priceUnit  = null;
        $qv = $p->getPrice();
        if ($qv instanceof QuantityValue) {
            $priceValue = $qv->getValue() === null ? null : (float)$qv->getValue();
            $unit = $qv->getUnit();
            $priceUnit = $unit ? ($unit->getAbbreviation() ?? $unit->getId()) : null;
        }

        return [
            'id'          => (int)$p->getId(),
            'key'         => $p->getKey(),
            'path'        => $p->getFullPath(),
            'name'        => $p->getName(),
            'sku'         => $p->getSKU(),
            'sku_search'  => (string)$p->getSKU(),
            'description' => $p->getDescription(),

            'price'         => ['value' => $priceValue, 'unit' => $priceUnit],
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
}
