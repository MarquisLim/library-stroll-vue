<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    public function artwork()
    {
        return $this->belongsTo(Artwork::class);
    }
}
