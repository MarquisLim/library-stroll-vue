<?php

namespace App\Services\Search;

use App\Contracts\AiServiceClient;
use App\Models\Artwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class HybridSearchService
{
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
        $lexicalLimit = config('ai.search.lexical_limit', 50);
        $semanticLimit = config('ai.search.semantic_limit', 50);
        $rrfK = config('ai.search.rrf_k', 60);

        $lexicalIds = $this->lexicalSearch($query, $filters, $lexicalLimit);

        $semanticIds = [];
        if (config('ai.enabled')) {
            $semanticIds = $this->semanticSearch($query, $filters, $semanticLimit);
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

        $finalLimit = $perPage * 3;
        $fused = $this->rrf->fuse($rankedLists, $rrfK, $finalLimit);
        $fusedIds = array_column($fused, 'id');

        if (empty($fusedIds)) {
            return ['artworks' => collect(), 'total' => 0];
        }

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
        try {
            $aiClient = app(AiServiceClient::class);
            $result = $aiClient->search($query, $limit);

            return $result->artworkIds();
        } catch (\Throwable $e) {
            Log::warning('Semantic search failed, using lexical only.', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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
