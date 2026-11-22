<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtworkColbertExpectation extends Model
{
    public const INDEXED = 'indexed';

    public const DELETED = 'deleted';

    public const CREATED_AT = null;

    protected $primaryKey = 'artwork_id';

    public $incrementing = false;

    protected $fillable = ['artwork_id', 'state', 'revision_hash', 'dense_attempt', 'searchable_text', 'text_sha256'];

    protected $hidden = ['searchable_text'];

    protected function casts(): array
    {
        return [
            'dense_attempt' => 'integer',
            'searchable_text' => 'encrypted',
        ];
    }
}
