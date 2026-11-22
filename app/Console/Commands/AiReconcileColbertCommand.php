<?php

namespace App\Console\Commands;

use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkColbertExpectation;
use App\Services\AiService\ArtworkColbertInspectionService;
use App\Services\AiService\ArtworkColbertRepairService;
use Illuminate\Console\Command;

class AiReconcileColbertCommand extends Command
{
    protected $signature = 'ai:reconcile-colbert
        {--dry-run : Run read-only diagnostics (also the default)}
        {--limit=20 : Maximum work IDs to check, between 1 and 100}
        {--id= : Select one artwork ID}
        {--apply : Queue a repair for the selected artwork}
        {--confirm-id= : Confirm the artwork ID for --apply}';

    protected $description = 'Compare Laravel, Qdrant and ColBERT and optionally queue a repair for one artwork';

    public function handle(ArtworkColbertInspectionService $inspection, ArtworkColbertRepairService $repair): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $rawId = $this->option('id');
        $id = $rawId === null ? null : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false || $id === false) {
            $this->error('Use a positive --id or a --limit between 1 and 100.');

            return self::INVALID;
        }
        $apply = $this->option('apply');
        $confirm = $this->option('confirm-id');
        if ($apply && ($id === null || filter_var($confirm, FILTER_VALIDATE_INT) !== $id
            || $this->option('dry-run') || $this->input->hasParameterOption('--limit'))) {
            $this->error('Use --apply with --id and a matching --confirm-id, without --dry-run or --limit.');

            return self::INVALID;
        }
        if (! $apply && $confirm !== null) {
            $this->error('--confirm-id requires --apply.');

            return self::INVALID;
        }
        if ($apply) {
            $result = $repair->enqueue($id);
            $this->line($result);
            if ($result === 'repair_queued') {
                $this->line('Run a read-only inspection after the worker has processed the job.');
            }

            return in_array($result, ['already_consistent', 'repair_queued'], true) ? self::SUCCESS : self::FAILURE;
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
