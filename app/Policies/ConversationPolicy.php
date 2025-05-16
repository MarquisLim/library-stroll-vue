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
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->users()->whereKey($user->id)->exists();
        // или, если users загружены:
        // return $conversation->users->contains($user->id);
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
