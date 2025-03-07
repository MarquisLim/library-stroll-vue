<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id','artwork_id'
    ];

    public function artwork()
    {
        return $this->belongsTo(Artwork::class);
    }
}
