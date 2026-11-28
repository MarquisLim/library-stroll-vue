<?php

namespace App\Models;

use App\Services\Search\AssistantInformationalReply;
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
        'informational',
    ];

    protected $fillable = ['sequence', 'user_query', 'result_status', 'requested_limit', 'source_references', 'diagnostics', 'fallback_source_ids', 'reply_code'];

    protected $hidden = ['user_query', 'source_references', 'diagnostics', 'fallback_source_ids', 'reply_code'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'user_query' => 'encrypted',
            'requested_limit' => 'integer',
            'source_references' => 'array',
            'diagnostics' => 'encrypted:array',
            'fallback_source_ids' => 'array',
            'reply_code' => 'encrypted',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class);
    }

    public function citations(): array
    {
        return $this->result_status === 'ok' && is_array($this->source_references)
            ? array_slice($this->source_references, 0, 5)
            : [];
    }

    public function replyPayload(): array
    {
        return $this->result_status === 'informational' ? AssistantInformationalReply::restore($this->reply_code) : [];
    }
}
