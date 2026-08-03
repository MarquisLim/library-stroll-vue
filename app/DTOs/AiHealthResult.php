<?php

namespace App\DTOs;

readonly class AiHealthResult
{
    public function __construct(
        public string $status,
        public string $ollama,
        public string $qdrant,
    ) {}

    public static function fromResponse(array $data): self
    {
        return new self(
            status: $data['status'] ?? 'unknown',
            ollama: $data['services']['ollama'] ?? 'unknown',
            qdrant: $data['services']['qdrant'] ?? 'unknown',
        );
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public static function unavailable(): self
    {
        return new self(
            status: 'unavailable',
            ollama: 'unknown',
            qdrant: 'unknown',
        );
    }
}
