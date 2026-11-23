<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantTurn extends Model
{
    use HasFactory, HasUuids;

    public const RESULT_STATUSES = [
        'ok',
        'no_results',
        'insufficient_evidence',
        'search_fallback',
        'clarification_required',
    ];

    protected $fillable = ['sequence', 'user_query', 'result_status', 'requested_limit', 'source_references'];

    protected $hidden = ['user_query', 'source_references'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'user_query' => 'encrypted',
            'requested_limit' => 'integer',
            'source_references' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class);
    }
}
