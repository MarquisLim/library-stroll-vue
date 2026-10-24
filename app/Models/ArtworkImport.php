<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtworkImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class)->withoutGlobalScopes();
    }
}
