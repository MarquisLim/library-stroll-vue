<?php

namespace App\Contracts;

use App\DTOs\AiAnalysisResult;
use App\DTOs\AiHealthResult;
use App\DTOs\AiSearchResult;

interface AiServiceClient
{
    public function health(): AiHealthResult;

    public function analyzeArtwork(
        int $artworkId,
        string $title = '',
        string $description = '',
        array $existingTags = [],
        ?string $imagePath = null,
    ): AiAnalysisResult;

    public function indexArtwork(
        int $artworkId,
        array $metadata,
        string $searchableText,
        array $payload,
        string $contentHash = '',
        string $idempotencyKey = '',
    ): array;

    public function deleteArtworkIndex(int $artworkId): array;

    public function search(string $query, int $limit = 20, array $filters = []): AiSearchResult;

    public function similar(int $artworkId, int $limit = 20, array $filters = []): AiSearchResult;

    public function rerank(string $query, array $artworkIds, int $limit = 20): AiSearchResult;
}
