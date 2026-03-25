<?php

declare(strict_types=1);

use App\Kernel;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');
/** @var \Sylius\Component\Resource\Repository\RepositoryInterface $productRepository */
$productRepository = $container->get('sylius.repository.product');
/** @var \Sylius\Component\Resource\Repository\RepositoryInterface $taxonRepository */
$taxonRepository = $container->get('sylius.repository.taxon');
/** @var \Sylius\Component\Resource\Factory\FactoryInterface $productTaxonFactory */
$productTaxonFactory = $container->get('sylius.factory.product_taxon');

$taxonCodes = ['1_2_3', '1_euro', '2_euro', '3_euro'];
$taxons = [];

foreach ($taxonCodes as $code) {
    $taxon = $taxonRepository->findOneBy(['code' => $code]);
    if ($taxon) {
        $taxons[$code] = $taxon;
        echo "Found taxon: $code (ID: {$taxon->getId()})\n";
    }
}

$parentTaxon = $taxonRepository->findOneBy(['code' => '1_2_3']);
if (!$parentTaxon) {
    // Fallback search by ID if code is different
    $parentTaxon = $taxonRepository->find(183);
}

if (!$parentTaxon) {
    echo "Parent taxon not found!\n";
    exit(1);
}

echo "Parent Taxon: " . $parentTaxon->getName() . " (ID: " . $parentTaxon->getId() . ")\n";

// 1. Fix products that have these taxons as main_taxon_id but are missing from sylius_product_taxon
$products = $productRepository->findAll();
$count = 0;

foreach ($products as $product) {
    /** @var ProductInterface $product */
    $mainTaxon = $product->getMainTaxon();
    
    if (!$mainTaxon) continue;

    // Check if main taxon is one of our target taxons or their children
    $isTarget = ($mainTaxon->getId() === $parentTaxon->getId());
    if (!$isTarget) {
        foreach ([62, 63, 64] as $childId) { // Hardcoded IDs from previous DB check for safety
             if ($mainTaxon->getId() === $childId) {
                 $isTarget = true;
                 break;
             }
        }
    }
    
    // Also check by code just in case
    if (!$isTarget && in_array($mainTaxon->getCode(), $taxonCodes)) {
        $isTarget = true;
    }

    if ($isTarget) {
        $changed = false;
        
        // Ensure it's in its main taxon
        if (!$product->hasTaxon($mainTaxon)) {
            echo "Adding product {$product->getCode()} to its main taxon {$mainTaxon->getCode()}\n";
            /** @var ProductTaxonInterface $productTaxon */
            $productTaxon = $productTaxonFactory->createNew();
            $productTaxon->setProduct($product);
            $productTaxon->setTaxon($mainTaxon);
            $product->addProductTaxon($productTaxon);
            $changed = true;
        }
        
        // Ensure it's also in the parent taxon (1€, 2€, 3€) if it's in a child
        if ($mainTaxon->getId() !== $parentTaxon->getId() && !$product->hasTaxon($parentTaxon)) {
            echo "Adding product {$product->getCode()} to parent taxon {$parentTaxon->getCode()}\n";
            /** @var ProductTaxonInterface $productTaxon */
            $productTaxon = $productTaxonFactory->createNew();
            $productTaxon->setProduct($product);
            $productTaxon->setTaxon($parentTaxon);
            $product->addProductTaxon($productTaxon);
            $changed = true;
        }

        if ($changed) {
            $em->persist($product);
            $count++;
        }
    }
}

echo "Syncing $count products...\n";
$em->flush();
echo "Done!\n";
