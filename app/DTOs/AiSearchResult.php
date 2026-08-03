<?php

namespace App\DTOs;

readonly class AiSearchResult
{
    /**
     * @param  array<int, array{artwork_id: int, score: float, rank: int}>  $results
     */
    public function __construct(
        public array $results,
        public string $model,
        public int $latencyMs,
        public bool $reranked = false,
    ) {}

    public static function fromResponse(array $data): self
    {
        return new self(
            results: $data['results'] ?? [],
            model: $data['model'] ?? '',
            latencyMs: (int) ($data['latency_ms'] ?? 0),
            reranked: $data['reranked'] ?? false,
        );
    }

    /**
     * @return int[]
     */
    public function artworkIds(): array
    {
        return array_column($this->results, 'artwork_id');
    }

    public static function empty(): self
    {
        return new self(results: [], model: '', latencyMs: 0);
    }
}
