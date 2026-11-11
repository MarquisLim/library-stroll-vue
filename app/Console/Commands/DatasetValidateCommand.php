<?php

namespace App\Console\Commands;

use App\Services\ResearchDataset\DatasetValidator;

class DatasetValidateCommand extends ResearchDatasetCommand
{
    protected $signature = 'dataset:validate {--name=} {--dataset-version=} {--offline}';

    protected $description = 'Validate research snapshots, provenance and independent annotation completeness';

    public function handle(): int
    {
        return $this->operation(function ($name, $files) {
            $report = app(DatasetValidator::class)->validate($name, $this->option('dataset-version'), (bool) $this->option('offline'));
            $this->showValidation($report);
            $this->line($files->report($name, 'validation', $report));

            return $report['ready_to_freeze'] ? self::SUCCESS : self::FAILURE;
        });
    }
}
