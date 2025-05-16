<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageReaction extends Model
{
    public $timestamps = false;
    protected $fillable = ['emoji'];

    public function message() { return $this->belongsTo(Message::class); }
    public function user()    { return $this->belongsTo(User::class); }
}
