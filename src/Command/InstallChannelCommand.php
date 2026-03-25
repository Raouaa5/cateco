<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:install:channel')]
class InstallChannelCommand extends Command
{
    public function __construct(
        private RepositoryInterface $channelRepository,
        private FactoryInterface $channelFactory,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Checking for existing channels...');

        $channels = $this->channelRepository->findAll();
        
        if (count($channels) > 0) {
            $output->writeln(sprintf('<info>Found %d existing channel(s). No action needed.</info>', count($channels)));
            return Command::SUCCESS;
        }

        $output->writeln('Creating default channel...');

        /** @var ChannelInterface $channel */
        $channel = $this->channelFactory->createNew();
        
        $channel->setCode('DEFAULT');
        $channel->setName('Default Channel');
        $channel->setEnabled(true);
        $channel->setHostname('localhost');
        
        // Let Sylius core or database handle default Locales and Currencies later
        // or we would need to inject LocaleRepository and CurrencyRepository to fetch the objects

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $output->writeln('<info>Default channel created successfully!</info>');
        
        return Command::SUCCESS;
    }
}
