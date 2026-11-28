<?php

namespace App\Services\Search;

use App\Contracts\AiServiceClient;
use App\Models\Artwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class HybridSearchService
{
    private const RERANK_LIMIT = 30;

    public function __construct(
        private readonly ReciprocalRankFusion $rrf,
    ) {}

    /**
     * Execute hybrid search combining lexical and semantic results.
     *
     * @return array{artworks: Collection, total: int}
     */
    public function search(
        string $query,
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
    ): array {
        $trace = AssistantSearchDiagnostics::current();
        $lexicalLimit = config('ai.search.lexical_limit', 50);
        $semanticLimit = config('ai.search.semantic_limit', 50);
        $rrfK = config('ai.search.rrf_k', 60);

        $trace?->used($query);
        $start = $trace ? hrtime(true) : 0;
        $lexicalIds = $this->lexicalSearch($query, $filters, $lexicalLimit);
        if ($trace) {
            $trace->data['filters'] = ['lexical_and_hybrid' => $filters, 'dense' => null, 'qdrant' => null,
                'assistant_visibility' => ['public', 'policy_view', 'ai_indexing_enabled', 'available_image']];
            $trace->stage('lexical', $start, count($lexicalIds), ['match' => 'title_like', 'limit' => $lexicalLimit]);
            foreach ($lexicalIds as $rank => $id) {
                $trace->candidate($id, ['lexical_rank' => $rank + 1]);
            }
        }

        $semanticIds = [];
        if (config('ai.enabled')) {
            $semanticIds = $this->semanticSearch($query, $filters, $semanticLimit);
            if (config('ai.rerank_enabled') && $semanticIds !== []) {
                $semanticIds = $this->rerankSemanticResults($query, $semanticIds);
            }
        }

        if (empty($lexicalIds) && empty($semanticIds)) {
            return ['artworks' => collect(), 'total' => 0];
        }

        $rankedLists = [];
        if (! empty($lexicalIds)) {
            $rankedLists[] = array_map(fn ($id) => ['id' => $id], $lexicalIds);
        }
        if (! empty($semanticIds)) {
            $rankedLists[] = array_map(fn ($id) => ['id' => $id], $semanticIds);
        }

        $finalLimit = $lexicalLimit + $semanticLimit;
        $start = $trace ? hrtime(true) : 0;
        $fused = $this->rrf->fuse($rankedLists, $rrfK, $finalLimit);
        if ($trace) {
            $trace->stage('rrf', $start, count($fused), ['k' => $rrfK, 'limit' => $finalLimit]);
            foreach ($fused as $rank => $row) {
                $trace->candidate($row['id'], ['rrf_score' => $row['score'], 'rrf_rank' => $rank + 1]);
            }
        }
        $fusedIds = array_column($fused, 'id');

        if (empty($fusedIds)) {
            return ['artworks' => collect(), 'total' => 0];
        }

        $start = $trace ? hrtime(true) : 0;
        $query_builder = Artwork::publiclyVisible()
            ->whereIn('id', $fusedIds)
            ->with(['user', 'media', 'likes', 'tags', 'collections'])
            ->withCount('likes');

        $this->applyFilters($query_builder, $filters);

        $artworks = $query_builder->get();

        $sorted = $artworks->sortBy(function ($artwork) use ($fusedIds) {
            return array_search($artwork->id, $fusedIds);
        })->values();

        $total = $sorted->count();
        $paged = $sorted->forPage($page, $perPage)->values();

        $userId = Auth::id();
        if ($userId) {
            foreach ($paged as $a) {
                $a->liked_by_user = $a->likes()->where('user_id', $userId)->exists();
                $a->in_collections = $a->collections()
                    ->where('user_id', $userId)
                    ->pluck('id')
                    ->toArray();
            }
        }

        if ($trace) {
            $trace->stage('hybrid', $start, $total, ['page_count' => $paged->count(), 'page' => $page, 'page_size' => $perPage]);
            foreach ($sorted as $rank => $work) {
                $trace->candidate($work->id, ['hybrid_rank' => $rank + 1]);
            }
            foreach (array_diff($sorted->pluck('id')->all(), $paged->pluck('id')->all()) as $id) {
                $trace->exclude($id, 'outside_hybrid_page');
            }
            foreach (array_diff($fusedIds, $sorted->pluck('id')->all()) as $id) {
                $trace->exclude($id, 'hybrid_public_scope_or_filters');
            }
        }

        return ['artworks' => $paged, 'total' => $total];
    }

    /**
     * @return int[]
     */
    private function lexicalSearch(string $query, array $filters, int $limit): array
    {
        $builder = Artwork::publiclyVisible()
            ->where('title', 'like', "%{$query}%")
            ->withCount('likes');

        $this->applyFilters($builder, $filters);

        return $builder
            ->orderByDesc('likes_count')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * @return int[]
     */
    private function semanticSearch(string $query, array $filters, int $limit): array
    {
        $trace = AssistantSearchDiagnostics::current();
        $start = $trace ? hrtime(true) : 0;
        try {
            $aiClient = app(AiServiceClient::class);
            $semanticFilters = [];
            foreach (['type' => 'media_type', 'tag' => 'tags', 'author_id' => 'user_id', 'has_ai' => 'has_ai'] as $key => $payloadKey) {
                if (isset($filters[$key])) {
                    $semanticFilters[$payloadKey] = match ($key) {
                        'has_ai' => (bool) $filters[$key],
                        'author_id' => (int) $filters[$key],
                        default => $filters[$key],
                    };
                }
            }
            if ($trace) {
                $trace->data['filters']['dense'] = $semanticFilters;
            }
            $result = $aiClient->search($query, $limit, $semanticFilters);
            if ($trace) {
                $trace->stage('dense', $start, count($result->results),
                    ['limit' => $limit, 'service_ms' => $result->latencyMs, 'scope' => 'after_fastapi_visibility']);
                $trace->model('dense', $result->model);
                foreach ($result->results as $row) {
                    if (is_int($row['artwork_id'] ?? null)) {
                        $trace->candidate($row['artwork_id'], ['dense_rank' => $row['rank'] ?? null, 'dense_score' => $row['score'] ?? null]);
                    }
                }
            }

            return $result->artworkIds();
        } catch (\Throwable $e) {
            $trace?->stage('dense', $start);
            $trace?->error('dense', 'lexical_only_fallback', $e instanceof RequestException ? $e->response->status() : null);
            Log::warning('Semantic search failed, using lexical only.', [
                'exception_type' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @param  int[]  $denseIds
     * @return int[]
     */
    private function rerankSemanticResults(string $query, array $denseIds): array
    {
        $candidates = array_slice($denseIds, 0, self::RERANK_LIMIT);
        $count = count($candidates);

        try {
            $result = app(AiServiceClient::class)->rerank($query, $candidates, $count);
            $rerankedIds = $result->artworkIds();
            $expectedIds = $candidates;
            $returnedIds = $rerankedIds;
            sort($expectedIds);
            sort($returnedIds);

            if (! $result->reranked
                || count($result->results) !== $count
                || array_column($result->results, 'rank') !== range(1, $count)
                || count(array_unique($rerankedIds)) !== $count
                || $expectedIds !== $returnedIds) {
                throw new \UnexpectedValueException('Invalid ColBERT candidate permutation.');
            }
        } catch (\Throwable $e) {
            Log::warning('ColBERT reranking failed. Using Dense order.', [
                'candidates' => $count,
                'reason' => $e::class,
            ]);

            return $denseIds;
        }

        Log::debug('ColBERT reranking applied.', [
            'candidates' => $count,
            'latency_ms' => $result->latencyMs,
        ]);

        return [...$rerankedIds, ...array_slice($denseIds, $count)];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['type']) && in_array($filters['type'], ['image', 'video'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['tag'])) {
            $query->whereHas('tags', fn ($q) => $q->where('name', $filters['tag']));
        }

        if (isset($filters['has_ai'])) {
            $query->where('has_ai', (bool) $filters['has_ai']);
        }

        if (! empty($filters['author_id'])) {
            $query->where('user_id', (int) $filters['author_id']);
        }
    }
}
