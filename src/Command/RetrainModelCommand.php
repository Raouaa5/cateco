<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ml:retrain',
    description: 'Regenerate ML dataset from DB and retrain the CATECO V4 recommender model.',
)]
class RetrainModelCommand extends Command
{
    /**
     * Absolute path to the ml/ directory inside the container.
     * The php service mounts the project root at /srv/sylius.
     */
    private const ML_DIR = '/srv/sylius/ml';

    /**
     * Base URL of the FastAPI recommender service (Docker internal hostname).
     * The recommender container runs Python and handles ML training.
     */
    private const API_BASE = 'http://recommender:8000';

    protected function configure(): void
    {
        $this
            ->addOption(
                'no-refresh',
                null,
                InputOption::VALUE_NONE,
                'Skip the hot-reload call to the API after retraining (useful in CI/test).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        $io->title('CATECO -- ML Nightly Retrain Pipeline');
        $io->text(sprintf('Started at: %s', date('Y-m-d H:i:s')));

        $refreshToken = $_ENV['ML_REFRESH_TOKEN'] ?? 'changeme';

        // Step 1: Regenerate interactions_hybrid.csv
        $io->section('[1/3] Regenerating hybrid dataset (PHP -> CSV)');
        $phpScript = self::ML_DIR . '/generate_dataset_hybrid.php';

        if (!file_exists($phpScript)) {
            $io->error("Script not found: $phpScript");
            return Command::FAILURE;
        }

        $phpOutput = [];
        $exitCode  = null;
        exec("php $phpScript 2>&1", $phpOutput, $exitCode);
        $io->text($phpOutput);

        if ($exitCode !== 0) {
            $io->error('Dataset generation failed. Aborting retrain.');
            return Command::FAILURE;
        }
        $io->success('Dataset regenerated -> interactions_hybrid.csv');

        // Step 2: Trigger Python training via the recommender container.
        // The PHP container does not have Python installed.
        // We POST to /train on the FastAPI service, which runs in a Python container
        // and has access to the same volume — so it can read the fresh CSV and
        // write the new svd_model_v4.pkl immediately.
        $io->section('[2/3] Triggering SVD V4 training (via recommender service)');

        $trainUrl = self::API_BASE . '/train';
        $context  = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "X-Refresh-Token: $refreshToken\r\nContent-Length: 0\r\n",
                'timeout'       => 120,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($trainUrl, false, $context);
        $status   = $http_response_header[0] ?? 'No response';

        if ($response === false || !str_contains($status, '200')) {
            $io->error("Training request failed. Status: $status");
            $io->note('Is the recommender container running? Check: docker compose ps recommender');
            return Command::FAILURE;
        }
        $io->success("Model trained. Response: $response");

        // Step 3: Hot-reload the engine in memory
        if ($input->getOption('no-refresh')) {
            $io->note('Skipping API hot-reload (--no-refresh flag set).');
        } else {
            $io->section('[3/3] Reloading model in FastAPI memory');

            $refreshCtx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "X-Refresh-Token: $refreshToken\r\nContent-Length: 0\r\n",
                    'timeout'       => 30,
                    'ignore_errors' => true,
                ],
            ]);

            $refreshResp   = @file_get_contents(self::API_BASE . '/refresh', false, $refreshCtx);
            $refreshStatus = $http_response_header[0] ?? 'No response';

            if ($refreshResp === false || !str_contains($refreshStatus, '200')) {
                $io->warning("Hot-reload failed: $refreshStatus");
                $io->note('Restart the recommender container to apply the new model.');
            } else {
                $io->success("Engine reloaded -> new model is live. Response: $refreshResp");
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $io->success(sprintf('Pipeline complete in %ss.', $elapsed));

        return Command::SUCCESS;
    }
}
