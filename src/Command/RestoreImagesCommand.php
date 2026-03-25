<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product\ProductImage;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Doctrine\ORM\EntityManagerInterface;

class RestoreImagesCommand extends Command
{
    protected static $defaultName = 'app:restore-images';

    private $productRepository;
    private $imageUploader;
    private $entityManager;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ImageUploaderInterface $imageUploader,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->productRepository = $productRepository;
        $this->imageUploader = $imageUploader;
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $csvFile = __DIR__ . '/../../products.csv';
        if (!file_exists($csvFile)) {
            $output->writeln("CSV file not found: $csvFile");
            return Command::FAILURE;
        }

        $handle = fopen($csvFile, 'r');
        fgetcsv($handle); // skip header

        $count = 0;
        $restored = 0;
        $skipped = 0;

        $output->writeln("Starting image restoration...");

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 4) continue;

            $sku = trim($data[3]);
            $imageUrl = trim($data[1]);

            if (empty($sku) || empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                continue;
            }

            $count++;
            
            /** @var ProductInterface|null $product */
            $product = $this->productRepository->findOneBy(['code' => $sku]);
            if (!$product) {
                $skipped++;
                continue;
            }

            // Check if product already has images
            if (!$product->getImages()->isEmpty()) {
                $skipped++;
                continue;
            }

            $output->writeln("Restoring image for product: $sku ($imageUrl)");

            try {
                $imageContent = @file_get_contents($imageUrl);
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
                    
                    $this->imageUploader->upload($image);
                    $product->addImage($image);
                    
                    $this->entityManager->persist($product);
                    $restored++;
                    @unlink($tmpPath);
                } else {
                    $output->writeln("Failed to download image for $sku");
                }
            } catch (\Exception $e) {
                $output->writeln("Error for $sku: " . $e->getMessage());
            }

            if ($count % 50 === 0) {
                $this->entityManager->flush();
                $output->writeln("Processed $count rows... Restored so far: $restored");
            }
        }

        $this->entityManager->flush();
        $output->writeln("\nRestoration finished!");
        $output->writeln("Total processed: $count");
        $output->writeln("Images restored: $restored");
        $output->writeln("Skipped (already have image or not found): $skipped");

        fclose($handle);
        return Command::SUCCESS;
    }
}
