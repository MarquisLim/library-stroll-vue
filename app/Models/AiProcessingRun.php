<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProcessingRun extends Model
{
    protected $fillable = [
        'artwork_id',
        'operation',
        'status',
        'request_id',
        'model',
        'parameters',
        'latency_ms',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public static function latestPublicationCleanup(int $artworkId): ?self
    {
        return self::where('operation', 'delete_index')
            ->where('parameters->stage', 'publication_cleanup')
            ->where('parameters->deleted_artwork_id', $artworkId)
            ->latest('id')->first();
    }
}
