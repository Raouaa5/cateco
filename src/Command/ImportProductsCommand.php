<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Sylius\Component\Product\Generator\SlugGeneratorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Console\Style\SymfonyStyle;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;

#[AsCommand(name: 'app:import-products')]
class ImportProductsCommand extends Command
{

    private FactoryInterface $productFactory;
    private FactoryInterface $productVariantFactory;
    private FactoryInterface $channelPricingFactory;
    private FactoryInterface $productImageFactory;
    private RepositoryInterface $productRepository;
    private RepositoryInterface $channelRepository;
    private RepositoryInterface $taxonRepository;
    private SlugGeneratorInterface $slugGenerator;
    private EntityManagerInterface $em;
    private ImageUploaderInterface $imageUploader;
    
    public function __construct(
        FactoryInterface $productFactory,
        FactoryInterface $productVariantFactory,
        FactoryInterface $channelPricingFactory,
        FactoryInterface $productImageFactory,
        RepositoryInterface $productRepository,
        RepositoryInterface $channelRepository,
        RepositoryInterface $taxonRepository,
        SlugGeneratorInterface $slugGenerator,
        EntityManagerInterface $em,
        ImageUploaderInterface $imageUploader
    ) {
        parent::__construct();
        $this->productFactory = $productFactory;
        $this->productVariantFactory = $productVariantFactory;
        $this->channelPricingFactory = $channelPricingFactory;
        $this->productImageFactory = $productImageFactory;
        $this->productRepository = $productRepository;
        $this->channelRepository = $channelRepository;
        $this->taxonRepository = $taxonRepository;
        $this->slugGenerator = $slugGenerator;
        $this->em = $em;
        $this->imageUploader = $imageUploader;
    }

    protected function configure(): void
    {
        $this->setDescription('Imports products from products.csv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        /** @var ChannelInterface $channel */
        $channel = $this->channelRepository->findOneBy([]);
        if (!$channel) {
            $io->error('No channel found.');
            return Command::FAILURE;
        }
        
        $locale = $channel->getDefaultLocale()?->getCode() ?? 'fr_FR';
        $io->note(sprintf('Using Channel: %s and Locale: %s', $channel->getCode(), $locale));

        $csvFile = dirname(__DIR__, 2) . '/products.csv';
        if (!file_exists($csvFile)) {
            $io->error(sprintf('File not found: %s', $csvFile));
            return Command::FAILURE;
        }

        $handle = fopen($csvFile, 'r');
        if ($handle === false) {
            $io->error('Could not open CSV file.');
            return Command::FAILURE;
        }
        fgetcsv($handle); // skip header

        $count = 0;
        $imageDir = dirname(__DIR__, 2) . '/public/media/image';
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0777, true);
        }

        // Preload taxons mapping
        $taxons = $this->taxonRepository->findAll();
        $taxonMap = [];
        foreach ($taxons as $taxon) {
            /** @var TaxonInterface $taxon */
            if ($taxon->getTranslation($locale)) {
                $taxonName = strtolower(trim($taxon->getTranslation($locale)->getName() ?? ''));
                if ($taxonName) {
                    $taxonMap[$taxonName] = $taxon;
                }
            }
        }

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 7) continue;

            $id = trim((string)($data[0] ?? ''));
            $imageUrl = trim((string)($data[1] ?? ''));
            $name = trim((string)($data[2] ?? ''));
            $sku = trim((string)($data[3] ?? ''));
            $categoryName = trim((string)($data[4] ?? ''));
            $priceTTC = trim((string)($data[6] ?? '0'));
            $quantity = count($data) >= 8 ? (int)trim((string)($data[7] ?? '0')) : 0;
            $enabled = count($data) >= 9 ? (trim((string)($data[8] ?? '1')) === '0' ? false : true) : true;

            if (empty($sku)) {
                $sku = 'SKU-' . $id;
            }
            if (empty($name)) {
                continue;
            }

            /** @var ProductInterface $product */
            $product = $this->productRepository->findOneBy(['code' => $sku]);
            if (!$product) {
                /** @var ProductInterface $newProduct */
                $newProduct = $this->productFactory->createNew();
                $product = $newProduct;
                $product->setCode($sku);
            }
            
            $product->setEnabled($enabled);
            if (!$product->hasChannel($channel)) {
                $product->addChannel($channel);
            }

            $product->setCurrentLocale($locale);
            $product->setFallbackLocale($locale);
            $product->setName($name);
            $product->setSlug($this->slugGenerator->generate($name));

            // Variant
            if ($product->getVariants()->isEmpty()) {
                /** @var ProductVariantInterface $newVariant */
                $newVariant = $this->productVariantFactory->createNew();
                $variant = $newVariant;
                $variant->setCode($sku);
                $variant->setProduct($product);
                $product->addVariant($variant);
            }
            
            /** @var ProductVariantInterface $variant */
            $variant = $product->getVariants()->first();
            $variant->setTracked(true);
            $variant->setOnHand($quantity);

            // Pricing
            $priceInCents = (int) round(((float) $priceTTC) * 100);
            $channelPricing = $variant->getChannelPricingForChannel($channel);
            if (!$channelPricing) {
                /** @var ChannelPricingInterface $newPricing */
                $newPricing = $this->channelPricingFactory->createNew();
                $channelPricing = $newPricing;
                $channelPricing->setChannelCode($channel->getCode());
                $variant->addChannelPricing($channelPricing);
            }
            $channelPricing->setPrice($priceInCents);
            $channelPricing->setOriginalPrice($priceInCents);

            // Category (Taxon)
            if (!empty($categoryName)) {
                $catNameLower = strtolower($categoryName);
                if (isset($taxonMap[$catNameLower])) {
                    $taxon = $taxonMap[$catNameLower];
                    $product->setMainTaxon($taxon);
                    if (!$product->hasTaxon($taxon)) {
                        /** @phpstan-ignore method.notFound */
                        $product->addTaxon($taxon);
                    }
                }
            }

            // Image
            if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL) && $product->getImages()->isEmpty()) {
                try {
                    $imageContent = @file_get_contents($imageUrl);
                    if ($imageContent) {
                        $parsedPath = parse_url($imageUrl, PHP_URL_PATH);
                        $imageFileName = is_string($parsedPath) ? basename($parsedPath) : '';
                        if (empty($imageFileName)) {
                            $imageFileName = $sku . '.jpg';
                        }
                        
                        $tmpPath = sys_get_temp_dir() . '/' . uniqid() . '_' . $imageFileName;
                        file_put_contents($tmpPath, $imageContent);

                        /** @var ProductImageInterface $newImage */
                        $newImage = $this->productImageFactory->createNew();
                        $image = $newImage;
                        $image->setType('main');
                        
                        $mime = mime_content_type($tmpPath) ?: 'image/jpeg';
                        $uploadedFile = new UploadedFile($tmpPath, $imageFileName, $mime, null, true);
                        $image->setFile($uploadedFile);
                        
                        $this->imageUploader->upload($image);
                        $product->addImage($image);
                        @unlink($tmpPath);
                    }
                } catch (\Exception $e) {
                    $io->warning("Error downloading image for $sku: " . $e->getMessage());
                }
            }

            $this->em->persist($product);
            $count++;

            if ($count % 50 === 0) {
                $this->em->flush();
                $this->em->clear();
                $io->text("Imported $count products...");
            }
            
            // For testing uncomment this limit
            // if ($count > 5) break;
        }

        $this->em->flush();
        $this->em->clear();
        fclose($handle);

        $io->success("Import completed! Total: $count products");

        return Command::SUCCESS;
    }
}
