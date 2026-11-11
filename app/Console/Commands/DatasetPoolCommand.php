<?php

namespace App\Console\Commands;

use App\Services\ResearchDataset\RelevanceJudgmentService;

class DatasetPoolCommand extends ResearchDatasetCommand
{
    protected $signature = 'dataset:pool {--name=} {--size=30} {--extend=}';

    protected $description = 'Extend annotation pools without discarding pairs or existing human judgments';

    public function handle(): int
    {
        return $this->operation(function ($name, $files) {
            $size = filter_var($this->option('size'), FILTER_VALIDATE_INT);
            if ($size === false || $size < 1 || $size > 5000) {
                throw new \RuntimeException('Invalid pool size');
            }
            $draft = $files->directory($name).'/draft';
            $manifest = $files->object($draft.'/manifest.json');
            $items = $files->lines($draft.'/artworks.jsonl');
            $queries = $files->lines($draft.'/queries.jsonl');
            $existing = $files->lines($draft.'/pool.jsonl');
            $itemIds = array_flip(array_column($items, 'dataset_item_id'));
            $queryIds = array_flip(array_column($queries, 'query_id'));
            if ($extension = $this->option('extend')) {
                foreach ($files->lines($extension) as $pair) {
                    if (! isset($queryIds[$pair['query_id'] ?? ''], $itemIds[$pair['dataset_item_id'] ?? '']) || ! preg_match('/^[a-z0-9_-]{1,64}$/D', $pair['method'] ?? '')) {
                        throw new \RuntimeException('Invalid external pool pair/method');
                    }
                    $existing[] = ['query_id' => $pair['query_id'], 'dataset_item_id' => $pair['dataset_item_id'], 'methods' => [$pair['method']], 'initial_status' => 'unjudged'];
                }
            }
            $pool = app(RelevanceJudgmentService::class)->pool($items, $queries, $size, $manifest['seed'], $existing);
            $files->writeLines($draft.'/pool.jsonl', $pool);
            app(RelevanceJudgmentService::class)->export($draft);
            $this->line($files->report($name, 'pool', ['updated_at' => now()->utc()->toISOString(), 'before' => count($existing), 'after' => count($pool), 'rules_version' => config('research_dataset.pool_rules_version')]));
            $this->info('Пар в пуле: '.count($pool).'. Существующие ручные оценки сохранены.');

            return self::SUCCESS;
        });
    }
}
