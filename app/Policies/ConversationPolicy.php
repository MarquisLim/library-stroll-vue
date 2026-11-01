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


    public function sendMessage(User $user, Conversation $conversation): bool
    {
        if (! $this->view($user, $conversation)) {
            return false;
        }

        if (! $conversation->isDialog()) {
            return true;
        }

        return ! $conversation->users()->where('users.id', '!=', $user->id)->get()
            ->contains(fn (User $other) => $user->hasBlocked($other) || $other->hasBlocked($user));
    }

    public function update(User $user, Conversation $conversation)
    {
        $isAdmin = $conversation->users()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        return $isAdmin
            ? Response::allow()
            : Response::deny('Только администратор может менять название.');
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        $participants = $conversation->users()->where('user_id', $user->id);

        if (! $conversation->isDialog()) {
            $participants->wherePivot('role', 'admin');
        }

        return $participants->exists();
    }
}
