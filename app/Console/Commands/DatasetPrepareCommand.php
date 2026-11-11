<?php

namespace App\Console\Commands;

use App\Services\ResearchDataset\DatasetCandidateSelector;
use App\Services\ResearchDataset\DatasetSampler;
use App\Services\ResearchDataset\DatasetVersionManager;

class DatasetPrepareCommand extends ResearchDatasetCommand
{
    protected $signature = 'dataset:prepare {--size=350} {--seed=42} {--name=} {--preset=balanced} {--include-videos} {--dry-run} {--refresh}';

    protected $description = 'Prepare a stratified research draft without modifying publications or invoking AI';

    public function handle(): int
    {
        return $this->operation(function ($name, $files) {
            $size = filter_var($this->option('size'), FILTER_VALIDATE_INT);
            $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
            if ($size === false || $size < 1 || $size > 5000 || $seed === false || $seed < 0 || $seed > 2147483647) {
                throw new \RuntimeException('Invalid size/seed');
            }
            $source = app(DatasetCandidateSelector::class)->select($this->option('preset'), (bool) $this->option('include-videos'));
            $selection = app(DatasetSampler::class)->sample($source['items'], $size, $seed, $this->option('preset'));
            $this->table(['Категория', 'Доступно', 'Выбрано', 'Цель'], array_map(fn ($cat) => [$cat, count(array_filter($source['items'], fn ($i) => $i['category'] === $cat)), $selection['distribution'][$cat] ?? 0, $selection['targets'][$cat] ?? 0], array_keys($selection['targets'])));
            $this->line('Доступно: '.count($source['items']).'; выбрано: '.count($selection['items']).'; недобор размера: '.$selection['size_shortfall']);
            if (! $source['audit']['catalog_complete'] || $source['audit']['issues']) {
                $this->warn('Исходный каталог пока не готов. Можно подготовить только черновик.');
            }
            if ($this->option('dry-run')) {
                $this->info('Dry run: no research files or database changes');

                return self::SUCCESS;
            }
            if (! $selection['items']) {
                throw new \RuntimeException('No eligible media; no draft created');
            }
            $result = app(DatasetVersionManager::class)->prepare($name, $selection, $source['audit'], $seed, $size, $this->option('preset'), (bool) $this->option('include-videos'), (bool) $this->option('refresh'));
            $report = $files->report($name, 'prepare', ['created_at' => now()->utc()->toISOString(), 'existing' => $result['existing'], 'audit' => $source['audit'], 'selection' => array_diff_key($selection, ['items' => true])]);
            $this->info(($result['existing'] ? 'Существующий черновик сохранён: ' : 'Создан черновик, не эталон: ').$result['path']);
            $this->line($report);

            return self::SUCCESS;
        }, ! $this->option('dry-run'));
    }
}
