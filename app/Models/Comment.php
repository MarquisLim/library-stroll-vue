<?php

namespace App\Models;

use App\Models\Models\Complaint\Complaint;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','text','commentable_id','parent_id','commentable_type'];

    protected static function booted()
    {
        static::addGlobalScope('not_blocked', function (Builder $builder) {
            $builder->where('is_blocked', false)
                ->whereDoesntHave('user', function ($q) {
                    $q->where('is_blocked', true);
                });
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function commentable()
    {
        return $this->morphTo();
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id');
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
}
