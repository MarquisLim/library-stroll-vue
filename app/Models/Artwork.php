<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Artwork extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'user_id','title','description','type','is_published','allow_download','allow_comments','is_adult','has_ai','is_private','views_count'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class,'artwork_tag');
    }

    public function collections()
    {
        return $this->belongsToMany(Collection::class,'artwork_collection');
    }

    public function comments()
    {
        return $this->morphMany(Comment::class,'commentable');
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }
}
