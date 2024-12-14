<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = ['user_id','name','is_private'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function artworks()
    {
        return $this->hasMany(Artwork::class);
    }
}
