<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Artwork extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

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
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id');
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }


    public function registerMediaConversions(Media $media = null): void
    {
        // Для изображений
        $this->addMediaConversion('thumb')
            ->width(400)
            ->height(400)
            ->nonQueued();

        // Для видео (если хотите извлекать кадр на 1-й секунде)
        if ($media && str_starts_with($media->mime_type, 'video/')) {
            $this->addMediaConversion('thumb')
                ->extractVideoFrameAtSecond(1)
                ->nonQueued();
        }
    }
}
