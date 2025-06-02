<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    /**
     * Пользователь может просмотреть переписку, если он в ней состоит.
     */
    public function view(User $user, Conversation $conversation)
    {
        return $conversation->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Отправлять сообщения тоже можно, если состоит.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
//        return $conversation->users()
//            ->wherePivot('user_id', $user->id)
//            ->wherePivot('role', 'admin')
//            ->exists();

         return $conversation->users()->where('user_id', $user->id)->exists();
    }
}
