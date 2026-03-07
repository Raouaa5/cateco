<?php
require_once 'vendor/autoload.php';

$kernel = new App\Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();

$channelRepository = $container->get('sylius.repository.channel');
$entityManager = $container->get('doctrine')->getManager();

$channels = $channelRepository->findAll();

foreach ($channels as $channel) {
    echo "Channel: " . $channel->getName() . "\n";
    echo "  Before: " . ($channel->getThemeName() ?? 'None') . "\n";
    
    // Set the theme to cateco/cateco (vendor/theme-name format)
    $channel->setThemeName('cateco/cateco');
    
    echo "  After: " . ($channel->getThemeName() ?? 'None') . "\n";
}

$entityManager->flush();

echo "\nAll channels updated to use cateco/cateco theme!\n";
