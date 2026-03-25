<?php

declare(strict_types=1);

use App\Kernel;
use Sylius\Component\Core\Model\ProductImageInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');
/** @var \Sylius\Component\Resource\Repository\RepositoryInterface $imageRepository */
$imageRepository = $container->get('sylius.repository.product_image');

$images = $imageRepository->findAll();
$totalCount = count($images);
$removedCount = 0;
$publicDir = __DIR__ . '/../public/media/image';

echo "Scanning $totalCount image entries...\n";

foreach ($images as $image) {
    /** @var ProductImageInterface $image */
    $path = $image->getPath();
    
    if (!$path) {
        echo "Removing image with empty path (ID: {$image->getId()})\n";
        $em->remove($image);
        $removedCount++;
        continue;
    }

    $fullPath = $publicDir . '/' . $path;
    
    if (!file_exists($fullPath)) {
        echo "File missing: $path (ID: {$image->getId()}). Removing entry.\n";
        $em->remove($image);
        $removedCount++;
    }
}

echo "Cleanup complete. Removed $removedCount broken image entries.\n";
$em->flush();
echo "Flush done.\n";
