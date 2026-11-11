<?php

namespace App\Console\Commands;

use App\Services\ResearchDataset\DatasetFiles;
use Illuminate\Console\Command;

abstract class ResearchDatasetCommand extends Command
{
    protected function operation(callable $callback, bool $lock = true): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Research dataset commands are restricted to local/testing');

            return self::FAILURE;
        }
        try {
            $files = app(DatasetFiles::class);
            $name = (string) $this->option('name');
            $files->directory($name);

            return $lock ? $files->locked($name, fn () => $callback($name, $files)) : $callback($name, $files);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function showValidation(array $report): void
    {
        $this->table(['Проверка', 'Результат'], [['Структура', $report['structurally_valid'] ? 'OK' : 'ERROR'], ['Готовность эталона', $report['ready_to_freeze'] ? 'READY' : 'DRAFT'], ['Пары в пуле', $report['annotation']['pool_pairs'] ?? 0], ['Проверенные пары', $report['annotation']['judged_pool_pairs'] ?? 0], ['Запросы с достаточной разметкой', $report['annotation']['sufficient_queries'] ?? 0]]);
        foreach (['errors', 'warnings', 'blockers'] as $kind) {
            $counts = array_count_values(array_map(fn ($text) => explode(':', $text, 2)[0], $report[$kind]));
            foreach ($counts as $code => $count) {
                $this->line("$kind: $code ($count)");
            }
        }
    }
}
