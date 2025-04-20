<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Artwork extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'user_id','title','description','type','is_published','allow_download','allow_comments','is_adult','has_ai','is_private','views_count'
    ];

    protected $appends = ['thumb_url', 'preview_url', 'video_duration_formatted'];

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

    public function getThumbUrlAttribute(): ?string
    {
        return optional($this->getFirstMedia('artworks'))->getUrl('thumb');
    }

    public function getPreviewUrlAttribute(): ?string
    {
        return optional($this->getFirstMedia('preview'))->getUrl();
    }

    public function getVideoDurationFormattedAttribute(): ?string
    {
        $media = $this->getFirstMedia('artworks');
        if (! $media || ! $media->hasCustomProperty('duration')) {
            return null;
        }

        $secs = $media->getCustomProperty('duration');
        $m = floor($secs / 60);
        $s = str_pad($secs % 60, 2, '0', STR_PAD_LEFT);

        return "{$m}:{$s}";
    }

    public function registerMediaConversions(Media $media = null): void
    {
        if (! $media) {
            return;
        }

        // Image
        if (str_starts_with($media->mime_type, 'image/')) {
            $this->addMediaConversion('thumb')
                ->width(400)
                ->height(400)
                ->nonQueued();
        }

        // Video
        if (str_starts_with($media->mime_type, 'video/')) {
            $randomSecond = rand(1, 20);
            $this->addMediaConversion('thumb')
                ->extractVideoFrameAtSecond($randomSecond)
                ->width(400)
                ->height(400)
                ->nonQueued();
        }
    }

    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection('artworks')
            ->useDisk('public');

        $this
            ->addMediaCollection('preview')
            ->singleFile()
            ->useDisk('public');
    }

}
