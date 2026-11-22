<?php

namespace App\Console\Commands;

use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkColbertExpectation;
use App\Services\AiService\ArtworkColbertInspectionService;
use Illuminate\Console\Command;

class AiReconcileColbertCommand extends Command
{
    protected $signature = 'ai:reconcile-colbert
        {--dry-run : Run read-only diagnostics (also the default)}
        {--limit=20 : Maximum work IDs to check, between 1 and 100}
        {--id= : Inspect one artwork ID}';

    protected $description = 'Compare Laravel, Qdrant and ColBERT without changing either index';

    public function handle(ArtworkColbertInspectionService $inspection): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $rawId = $this->option('id');
        $id = $rawId === null ? null : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false || $id === false) {
            $this->error('Use a positive --id or a --limit between 1 and 100.');

            return self::INVALID;
        }

        $ids = $id === null ? ArtworkColbertExpectation::orderBy('artwork_id')->limit($limit)->pluck('artwork_id')
            ->merge(ArtworkAiMetadata::orderBy('artwork_id')->limit($limit)->pluck('artwork_id'))
            ->unique()->sort()->take($limit) : collect([$id]);

        $this->info('Read-only ColBERT inspection. Only Laravel-known IDs are inspected.');
        $rows = [];
        $failed = false;
        foreach ($ids as $artworkId) {
            $report = $inspection->inspect((int) $artworkId);
            $rows[] = [$artworkId, ...array_values($report)];
            $failed = $failed || $report['category'] === 'conflict_or_unavailable';
        }

        $this->table(['Artwork', 'Eligible', 'MySQL', 'Qdrant', 'Dense revision', 'Expected', 'ColBERT', 'Category', 'Reason'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
