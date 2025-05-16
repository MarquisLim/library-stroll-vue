<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    protected $fillable = ['disk','path','mime','size','message_id'];

    protected $appends = ['url'];

    public function message() { return $this->belongsTo(Message::class); }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
