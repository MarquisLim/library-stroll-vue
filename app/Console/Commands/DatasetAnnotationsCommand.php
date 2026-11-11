<?php

namespace App\Console\Commands;

use App\Services\ResearchDataset\RelevanceJudgmentService;

class DatasetAnnotationsCommand extends ResearchDatasetCommand
{
    protected $signature = 'dataset:annotations {--name=} {--import=}';

    protected $description = 'Export annotation aids or import independent human CSV grades 0–3';

    public function handle(): int
    {
        return $this->operation(function ($name, $files) {
            $draft = $files->directory($name).'/draft';
            if ($input = $this->option('import')) {
                $report = app(RelevanceJudgmentService::class)->import($draft, $input);
                $this->line($files->report($name, 'judgment-import', $report));
                $this->info('Добавлено оценок: '.$report['added'].'; уже существовали: '.$report['existing']);
            }
            app(RelevanceJudgmentService::class)->export($draft);
            $this->info($draft.'/annotation-template.csv');
            $this->line($draft.'/annotation.html');

            return self::SUCCESS;
        });
    }
}
