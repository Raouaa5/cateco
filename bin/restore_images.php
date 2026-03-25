<?php

declare(strict_types=1);

use App\Kernel;
use App\Entity\Product\ProductImage;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Doctrine\ORM\EntityManagerInterface;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $em */
$em = $container->get('doctrine.orm.entity_manager');
/** @var \Sylius\Component\Resource\Repository\RepositoryInterface $productRepository */
$productRepository = $container->get('sylius.repository.product');
/** @var ImageUploaderInterface $imageUploader */
// Correct service ID found via debug_ids.php
$imageUploader = $container->get('sylius.uploader.image');

$csvFile = __DIR__ . '/../products.csv';
if (!file_exists($csvFile)) {
    echo "CSV file not found: $csvFile\n";
    exit(1);
}

$handle = fopen($csvFile, 'r');
fgetcsv($handle); // skip header

$count = 0;
$restored = 0;
$skipped = 0;

echo "Starting image restoration...\n";

while (($data = fgetcsv($handle)) !== false) {
    if (count($data) < 4) continue;

    $sku = trim($data[3]);
    $imageUrl = trim($data[1]);
    
    // Normalize URL (fix double slashes etc)
    $imageUrl = str_replace(['cateco.fr//', 'cateco.fr///'], 'cateco.fr/', $imageUrl);

    if (empty($sku) || empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        continue;
    }

    $count++;
    
    /** @var ProductInterface|null $product */
    $product = $productRepository->findOneBy(['code' => $sku]);
    if (!$product) {
        // echo "Product not found: $sku\n";
        $skipped++;
        continue;
    }

    // Check if product already has images
    if (!$product->getImages()->isEmpty()) {
        $skipped++;
        continue;
    }

    echo "Restoring image for product: $sku ($imageUrl)\n";

    try {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
            ]
        ]);
        $imageContent = @file_get_contents($imageUrl, false, $context);
        if ($imageContent) {
            $imageFileName = basename(parse_url($imageUrl, PHP_URL_PATH));
            if (empty($imageFileName)) {
                $imageFileName = $sku . '.jpg';
            }
            
            $tmpPath = sys_get_temp_dir() . '/' . uniqid() . '_' . $imageFileName;
            file_put_contents($tmpPath, $imageContent);

            /** @var ProductImageInterface $image */
            $image = new ProductImage();
            $image->setType('main');
            
            $mime = mime_content_type($tmpPath) ?: 'image/jpeg';
            $uploadedFile = new UploadedFile($tmpPath, $imageFileName, $mime, null, true);
            $image->setFile($uploadedFile);
            
            $imageUploader->upload($image);
            $product->addImage($image);
            
            $em->persist($image);
            $em->persist($product);
            $restored++;
            @unlink($tmpPath);
            echo "Successfully restored image for $sku. Total restored: $restored\n";
        } else {
            echo "Failed to download image content for $sku ($imageUrl)\n";
        }
    } catch (\Exception $e) {
        echo "Error for $sku: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
    }

    if ($restored > 0) {
        $em->flush();
        echo "Restored so far: $restored\n";
    }
}

$em->flush();
echo "\nRestoration finished!\n";
echo "Total processed lines: $count\n";
echo "Images restored: $restored\n";
echo "Skipped: $skipped\n";

fclose($handle);
