<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    protected $fillable = [
        'user_id','artwork_id'
    ];

    public function artwork()
    {
        return $this->belongsTo(Artwork::class);
    }
}
