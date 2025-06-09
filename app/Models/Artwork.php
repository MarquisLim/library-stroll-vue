<?php

namespace App\Models;

use App\Models\Models\Complaint\Complaint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Artwork extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'user_id','title','description','type','is_published','allow_download','allow_comments','is_adult','has_ai','is_private','views_count', 'is_blocked', 'published_at'
    ];

    protected $appends = ['thumb_url', 'preview_url',   'thumb_width',
        'thumb_height', 'video_duration_formatted','liked_by_user', 'in_collections'];

    protected static function booted()
    {
        static::addGlobalScope('not_blocked', function (Builder $builder) {
            $builder->where('is_blocked', false)
                ->whereDoesntHave('user', function ($q) {
                    $q->where('is_blocked', true);
                });
        });
    }

    public function getLikedByUserAttribute(): bool
    {
        return Auth::check()
            ? $this->likes()->where('user_id', Auth::id())->exists()
            : false;
    }

    public function getInCollectionsAttribute(): array
    {
        return Auth::check()
            ? $this->collections()->where('user_id', Auth::id())->pluck('id')->toArray()
            : [];
    }

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

    public function complaints(): MorphMany
    {
        return $this->morphMany(Complaint::class, 'complaintable');
    }

    public function block()
    {
        $this->is_blocked = true;
        $this->save();
    }

    public function unblock()
    {
        $this->is_blocked = false;
        $this->save();
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

    public function getOriginalUrlAttribute()
    {
        return $this->getFirstMediaUrl('artworks');
    }

    public function registerMediaConversions(Media $media = null): void
    {
        if (! $media) {
            return;
        }

        // Image: указываем только ширину, Spatie сохранит пропорции
        if (str_starts_with($media->mime_type, 'image/')) {
            $this->addMediaConversion('thumb')
                ->width(300)
                ->format('webp')
                ->nonQueued();
        }

        // Video: случайный кадр, ширина 300px
        if (str_starts_with($media->mime_type, 'video/')) {
            $randomSecond = rand(1, 20);
            $this->addMediaConversion('thumb')
                ->extractVideoFrameAtSecond($randomSecond)
                ->width(300)
                ->format('jpg')
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

    public function getThumbWidthAttribute(): ?int
    {
        $media = $this->getFirstMedia('artworks');
        if (! $media || ! $media->hasGeneratedConversion('thumb')) {
            return null;
        }

        // путь до файла конверсии "thumb"
        $path = $media->getPath('thumb');
        if (! file_exists($path)) {
            return null;
        }

        [$w, $h] = getimagesize($path);
        return $w;
    }

    /**
     * Высота файла thumb в px (если конверсия уже есть на диске).
     */
    public function getThumbHeightAttribute(): ?int
    {
        $media = $this->getFirstMedia('artworks');
        if (! $media || ! $media->hasGeneratedConversion('thumb')) {
            return null;
        }

        // путь до файла конверсии "thumb"
        $path = $media->getPath('thumb');
        if (! file_exists($path)) {
            return null;
        }

        [$w, $h] = getimagesize($path);
        return $h;
    }

}
