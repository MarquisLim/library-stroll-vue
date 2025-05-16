<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = ['type','title'];

    // участники
    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['joined_at', 'role', 'last_read_at'])
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage()
    {
        return $this->belongsTo(Message::class,'last_message_id');
    }

    /** true, если это диалог на двоих */
    public function isDialog(): bool
    {
        return $this->type === 'dialog';
    }
}
