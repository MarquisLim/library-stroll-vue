<?php

namespace App\Console\Commands;

use App\Services\AiService\ArtworkIndexInspectionService;
use Illuminate\Console\Command;

class AiReconcileArtworkCommand extends Command
{
    protected $signature = 'ai:reconcile-artwork {id : Artwork ID} {--dry-run : Run read-only diagnostics}';

    protected $description = 'Inspect MySQL metadata and the Qdrant point for one artwork';

    public function handle(ArtworkIndexInspectionService $service): int
    {
        $id = filter_var($this->argument('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('Artwork ID must be a positive integer.');

            return self::INVALID;
        }

        $result = $service->inspect($id);
        $recommendation = match ($result['group']) {
            'A' => 'eligible for a separate status recovery review',
            'B' => 'preserve existing point',
            'C' => 'review the changed revision before controlled indexing',
            'D' => 'controlled indexing after approval',
            'E' => 'leave the artwork out of indexing',
            'F' => 'review the conflict or retry the inspection',
        };

        $this->line("Artwork: {$id} (read-only)");
        $this->line("MySQL status: {$result['mysql_status']}");
        $this->line("Qdrant: {$result['qdrant_status']}");
        $this->line("Content hash: {$result['content_hash']}");
        $this->line("Schema/prompt: {$result['versions']}");
        $this->line("Embedding model: {$result['embedding_model']}");
        $this->line("Model fingerprint: {$result['fingerprint']}");
        $this->newLine();
        $this->line("Classification: {$result['group']}");
        $this->line("Reason: {$result['reason']}");
        $this->line("Recommendation: {$recommendation}");
        $this->line('Action taken: none');

        return $result['group'] === 'F' ? self::FAILURE : self::SUCCESS;
    }
}
