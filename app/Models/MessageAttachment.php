<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageAttachment extends Model
{
    protected $fillable = ['disk','path','mime','size','message_id','user_id'];

    protected $appends = ['url'];

    public function message() { return $this->belongsTo(Message::class); }

    public function url(): string
    {
        return route('messenger.attachments.show', $this);
    }

    public function getUrlAttribute(): string
    {
        return $this->url();
    }
}
