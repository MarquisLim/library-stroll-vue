<?php

namespace App\Policies;

use App\Models\Artwork;
use App\Models\User;

class ArtworkPolicy
{
    public function view(?User $user, Artwork $artwork): bool
    {
        if ($this->canManage($user, $artwork)) {
            return true;
        }

        return $artwork->is_published && ! $artwork->is_private;
    }

    public function update(User $user, Artwork $artwork): bool
    {
        return $this->canManage($user, $artwork);
    }

    public function delete(User $user, Artwork $artwork): bool
    {
        return $this->canManage($user, $artwork);
    }

    public function comment(User $user, Artwork $artwork): bool
    {
        return $artwork->is_published
            && $artwork->allow_comments
            && $this->view($user, $artwork);
    }

    public function download(?User $user, Artwork $artwork): bool
    {
        return $artwork->allow_download && $this->view($user, $artwork);
    }

    private function canManage(?User $user, Artwork $artwork): bool
    {
        return $user !== null
            && ($artwork->isOwnedBy($user) || $user->hasAnyRole(['Admin', 'SuperAdmin']));
    }
}
