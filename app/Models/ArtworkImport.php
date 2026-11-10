<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtworkImport extends Model
{
    protected $guarded = [];

    public function scopeForDemoDataset($query, int $seed, string $preset)
    {
        return $query->where('is_demo', true)->where('metadata->dataset_seed', $seed)
            ->where(function ($q) use ($preset) {
                $q->where('metadata->dataset_preset', $preset);
                if ($preset === 'balanced') {
                    $q->orWhereNull('metadata->dataset_preset');
                }
            });
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class)->withoutGlobalScopes();
    }
}
