<?php

namespace App\Services\AiService;

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use Illuminate\Support\Str;

class AiMonitoringService
{
    public function snapshot(array $filters): array
    {
        $published = Artwork::withoutGlobalScopes()->where('is_published', true);
        $counts = (clone $published)->leftJoin('artwork_ai_metadata as metadata', 'artworks.id', '=', 'metadata.artwork_id')
            ->selectRaw("COALESCE(metadata.status, 'missing') as state, COUNT(*) as total")
            ->groupBy('metadata.status')->pluck('total', 'state');
        $stats = collect([...ArtworkAiMetadata::STATUSES, 'missing'])->mapWithKeys(fn ($state) => [$state => (int) ($counts[$state] ?? 0)])->all();
        $stats['total'] = array_sum($stats);
        $stats['coverage'] = $stats['total'] ? round($stats['indexed'] * 100 / $stats['total'], 1) : 0;

        $query = (clone $published)->with('aiMetadata');
        if (($filters['status'] ?? '') === 'missing') {
            $query->whereDoesntHave('aiMetadata');
        } elseif (! empty($filters['status'])) {
            $query->whereHas('aiMetadata', fn ($metadata) => $metadata->where('status', $filters['status']));
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($item) => $item->where('title', 'like', '%'.$search.'%')
                ->when(ctype_digit($search), fn ($item) => $item->orWhere('artworks.id', (int) $search)));
        }
        $artworks = $query->orderByDesc('artworks.updated_at')->orderByDesc('artworks.id')->paginate(15)->withQueryString();
        $running = AiProcessingRun::where('status', 'processing')->latest('id')->limit(100)->with(['artwork' => fn ($q) => $q->withoutGlobalScopes()])->get();
        $byArtwork = $running->unique('artwork_id')->keyBy('artwork_id');
        $artworks->through(function (Artwork $artwork) use ($byArtwork) {
            $metadata = $artwork->aiMetadata;

            return [
                'id' => $artwork->id, 'title' => $artwork->title ?: 'Без названия', 'is_private' => (bool) $artwork->is_private,
                'status' => $metadata?->status ?? 'missing', 'attempts' => $metadata?->attempts ?? 0,
                'updated_at' => $metadata?->updated_at?->toISOString(), 'indexed_at' => $metadata?->indexed_at?->toISOString(),
                'error' => $this->safeError($metadata?->last_error),
                'metadata' => $metadata ? $metadata->only(['summary', 'tags_ru', 'tags_en', 'chat_model', 'vision_model', 'embedding_model', 'embedding_dimension', 'qdrant_point_id', 'confidence']) : null,
                'run' => isset($byArtwork[$artwork->id]) ? $this->run($byArtwork[$artwork->id]) : null,
            ];
        });

        $history = AiProcessingRun::with(['artwork' => fn ($q) => $q->withoutGlobalScopes()])->latest('id')->limit(20)->get()->map(fn ($run) => $this->run($run));

        return [
            'checked_at' => now()->toISOString(), 'enabled' => (bool) config('ai.enabled'), 'stats' => $stats,
            'artworks' => $artworks, 'active_runs' => $running->take(20)->map(fn ($run) => $this->run($run))->values(), 'history' => $history,
            'settings' => ['queue' => config('ai.queue'), 'queue_connection' => config('queue.default'),
                'queue_driver' => config('queue.connections.'.config('queue.default').'.driver'),
                'auto_analyze' => (bool) config('ai.auto_analyze_on_publish'), 'auto_reindex' => (bool) config('ai.auto_reindex_on_update'),
                'timeout_seconds' => 600, 'schema_version' => config('ai.schema_version'), 'prompt_version' => config('ai.prompt_version')],
        ];
    }

    private function run(AiProcessingRun $run): array
    {
        return [
            'id' => $run->id, 'artwork_id' => $run->artwork_id, 'title' => $run->artwork?->title ?? 'Работа удалена',
            'operation' => $run->operation, 'status' => $run->status, 'request_id' => $run->request_id,
            'stage' => $run->parameters['stage'] ?? ($run->status === 'success' ? 'completed' : $run->status),
            'reason' => in_array($run->parameters['reason'] ?? null, ['revision_changed', 'artwork_unpublished_or_deleted'], true) ? $run->parameters['reason'] : null,
            'warnings' => collect($run->parameters['warnings'] ?? [])->take(5)->map(fn ($warning) => $this->safeError((string) $warning))->all(),
            'model' => $run->model, 'latency_ms' => $run->latency_ms, 'error' => $this->safeError($run->error),
            'started_at' => $run->started_at?->toISOString(), 'finished_at' => $run->finished_at?->toISOString(),
            'overdue' => $run->status === 'processing' && $run->started_at?->lt(now()->subSeconds(660)),
        ];
    }

    private function safeError(?string $error): ?string
    {
        if (! $error) {
            return null;
        }
        $error = preg_replace('#(https?://)[^\s/@]+:[^\s/@]+@#i', '$1[redacted]@', $error);
        foreach (array_filter([config('ai.api_key')]) as $secret) {
            $error = str_replace($secret, '[redacted]', $error);
        }

        return Str::limit($error, 500);
    }
}
