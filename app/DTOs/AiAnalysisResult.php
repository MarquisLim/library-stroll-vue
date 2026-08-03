<?php

namespace App\DTOs;

readonly class AiAnalysisResult
{
    public function __construct(
        public int $artworkId,
        public string $summary,
        public array $objects,
        public array $themes,
        public array $styles,
        public array $mood,
        public array $colors,
        public array $tagsRu,
        public array $tagsEn,
        public float $confidence,
        public string $chatModel,
        public string $visionModel,
        public string $embeddingModel,
        public int $latencyMs,
    ) {}

    public static function fromResponse(array $data): self
    {
        return new self(
            artworkId: $data['artwork_id'],
            summary: $data['summary'] ?? '',
            objects: $data['objects'] ?? [],
            themes: $data['themes'] ?? [],
            styles: $data['styles'] ?? [],
            mood: $data['mood'] ?? [],
            colors: $data['colors'] ?? [],
            tagsRu: $data['tags_ru'] ?? [],
            tagsEn: $data['tags_en'] ?? [],
            confidence: (float) ($data['confidence'] ?? 0),
            chatModel: $data['chat_model'] ?? '',
            visionModel: $data['vision_model'] ?? '',
            embeddingModel: $data['embedding_model'] ?? '',
            latencyMs: (int) ($data['latency_ms'] ?? 0),
        );
    }

    public function toMetadataArray(): array
    {
        return [
            'summary' => $this->summary,
            'objects' => $this->objects,
            'themes' => $this->themes,
            'styles' => $this->styles,
            'mood' => $this->mood,
            'colors' => $this->colors,
            'tags_ru' => $this->tagsRu,
            'tags_en' => $this->tagsEn,
            'confidence' => $this->confidence,
            'chat_model' => $this->chatModel,
            'vision_model' => $this->visionModel,
            'embedding_model' => $this->embeddingModel,
        ];
    }
}
