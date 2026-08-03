<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtworkAiMetadata extends Model
{
    protected $table = 'artwork_ai_metadata';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STALE = 'stale';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_INDEXED,
        self::STATUS_FAILED,
        self::STATUS_STALE,
    ];

    protected $fillable = [
        'artwork_id',
        'status',
        'qdrant_point_id',
        'content_hash',
        'source_media_uuid',
        'summary',
        'objects',
        'themes',
        'styles',
        'mood',
        'colors',
        'tags_ru',
        'tags_en',
        'confidence',
        'chat_model',
        'vision_model',
        'embedding_model',
        'embedding_dimension',
        'metadata_schema_version',
        'prompt_version',
        'attempts',
        'last_error',
        'processed_at',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'objects' => 'array',
            'themes' => 'array',
            'styles' => 'array',
            'mood' => 'array',
            'colors' => 'array',
            'tags_ru' => 'array',
            'tags_en' => 'array',
            'confidence' => 'decimal:3',
            'metadata_schema_version' => 'integer',
            'prompt_version' => 'integer',
            'attempts' => 'integer',
            'embedding_dimension' => 'integer',
            'processed_at' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public function isIndexed(): bool
    {
        return $this->status === self::STATUS_INDEXED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isStale(): bool
    {
        return $this->status === self::STATUS_STALE;
    }

    public function markStale(): void
    {
        $this->update(['status' => self::STATUS_STALE]);
    }
}
