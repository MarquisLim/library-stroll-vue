<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'reply_to_id',
        'has_attachments',
        'last_message_id',
        'artwork_id',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class,'reply_to_id');
    }
    public function replies()
    {
        return $this->hasMany(Message::class,'reply_to_id');
    }

    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function artwork()
    {
        return $this->belongsTo(Artwork::class);
    }
}
