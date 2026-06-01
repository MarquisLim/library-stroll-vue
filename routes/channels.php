<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Conversation;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Definitions
|--------------------------------------------------------------------------
|
| Здесь вы регистрируете callback’и, которые скажут Laravel — можно ли
| текущему пользователю слушать private-канал с таким-то именем.
|
*/


Broadcast::routes([
    'middleware' => ['web', 'auth:web'],
]);
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    return $user->conversations()
        ->where('conversations.id', $conversationId)
        ->exists();
});

Broadcast::channel('user.{id}', function($user, $id){
    return (int)$user->id === (int)$id;
});




