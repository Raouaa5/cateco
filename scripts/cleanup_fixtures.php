<?php
require '/srv/sylius/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Sylius\Component\Core\Model\ProductInterface;

(new Dotenv())->bootEnv('/srv/sylius/.env');
$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');

// Find all products that look like Sylius fixtures
$productRepository = $container->get('sylius.repository.product');
$products = $productRepository->createQueryBuilder('p')
    ->where("p.code LIKE '%_Jeans'")
    ->orWhere("p.code LIKE '%_T_Shirt'")
    ->orWhere("p.code LIKE '%_Cap'")
    ->orWhere("p.code LIKE '%_Dress'")
    ->orWhere("p.code LIKE '%_Midi'")
    ->orWhere("p.code LIKE '%_Gown'")
    ->orWhere("p.code LIKE '%_Elegance'")
    ->orWhere("p.code = 'Palm_Tree_Paradise'")
    ->getQuery()
    ->getResult();

echo "Found " . count($products) . " fixture products to delete.\n";

try {
    foreach ($products as $i => $product) {
        $productId = $product->getId();
        
        // Find variants for this product
        $variants = $em->getConnection()->fetchAllAssociative("SELECT id FROM sylius_product_variant WHERE product_id = ?", [$productId]);
        foreach ($variants as $variant) {
            $variantId = $variant['id'];
            
            // Delete associated items
            $em->getConnection()->executeStatement("DELETE FROM sylius_order_item WHERE variant_id = ?", [$variantId]);
        }
        
        // Use Doctrine to remove the product
        $em->remove($product);
        $em->flush();
        
        echo "Deleted product: " . $product->getCode() . "\n";
    }
    
    echo "Successfully deleted all fixture products and their associated order items!\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
