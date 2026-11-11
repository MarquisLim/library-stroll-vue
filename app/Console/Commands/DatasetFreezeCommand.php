<?php

namespace App\Console\Commands;

use App\Services\ResearchDataset\DatasetValidator;
use App\Services\ResearchDataset\DatasetVersionManager;

class DatasetFreezeCommand extends ResearchDatasetCommand
{
    protected $signature = 'dataset:freeze {--name=} {--dataset-version=}';

    protected $description = 'Freeze a validated independently judged dataset; never overwrite a version';

    public function handle(): int
    {
        return $this->operation(function ($name, $files) {
            $version = (string) $this->option('dataset-version');
            $destination = $files->version($name, $version);
            if (file_exists($destination)) {
                throw new \RuntimeException('Version already exists; no files changed');
            }
            $report = app(DatasetValidator::class)->validate($name);
            $this->showValidation($report);
            $this->line($files->report($name, 'pre-freeze', $report));
            if (! $report['ready_to_freeze']) {
                $this->warn('Фиксация запрещена: черновик ещё не готов к независимой оценке.');

                return self::FAILURE;
            }
            $this->info(app(DatasetVersionManager::class)->freeze($name, $version, $report));

            return self::SUCCESS;
        });
    }
}
