<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RecommendationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ml:test-recommendations',
    description: 'Test the RecommendationService end-to-end from inside Symfony.',
)]
class TestRecommendationCommand extends Command
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('user_id', InputArgument::OPTIONAL, 'Customer ID to test', '22');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $userId = (int) $input->getArgument('user_id');

        $io->title("Testing RecommendationService for user_id=$userId");

        // 1. Raw HTTP check
        $io->section('[1] Raw HTTP to http://recommender:8000');
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
            $raw = @file_get_contents("http://recommender:8000/recommendations?user_id=$userId&top_k=3", false, $ctx);
            if ($raw === false) {
                $io->error('Cannot reach http://recommender:8000 — check Docker network / container name');
                return Command::FAILURE;
            }
            $decoded = json_decode($raw, true);
            $io->success("HTTP OK — fallback: " . ($decoded['fallback'] ? 'yes' : 'no'));
            $io->text("Products: " . implode(', ', array_column($decoded['recommendations'] ?? [], 'product_id')));
        } catch (\Throwable $e) {
            $io->error('Raw HTTP failed: ' . $e->getMessage());
        }

        // 2. Through RecommendationService
        $io->section('[2] Through RecommendationService (full Symfony DI chain)');
        try {
            $products = $this->recommendationService->getRecommendations($userId, 5);
            if (empty($products)) {
                $io->warning('Service returned EMPTY array — check logs for silent errors');
            } else {
                $io->success(sprintf('Got %d products:', count($products)));
                foreach ($products as $p) {
                    $io->text(sprintf('  - [%d] %s', $p->getId(), $p->getName()));
                }
            }
        } catch (\Throwable $e) {
            $io->error('Service threw exception: ' . $e->getMessage());
            $io->text($e->getTraceAsString());
        }

        return Command::SUCCESS;
    }
}
