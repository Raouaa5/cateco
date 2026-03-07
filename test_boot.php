<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(__DIR__.'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
try {
    $kernel->boot();
    echo "Kernel booted successfully\n";
    
    $container = $kernel->getContainer();
    $twig = $container->get('twig');
    
    echo "Attempting to render homepage...\n";
    // Using the path from the user's error message: @SyliusShop/homepage/index.html.twig
    $output = $twig->render('@SyliusShop/homepage/index.html.twig');
    echo "Render successful! First 100 chars: " . substr($output, 0, 100) . "\n";
    
} catch (\Exception $e) {
    echo "Caught exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
    echo "In " . $e->getFile() . " on line " . $e->getLine() . "\n";
}
