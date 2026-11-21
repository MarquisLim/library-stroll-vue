<?php

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Services\AiService\ArtworkIndexVisibility;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            app(ArtworkIndexVisibility::class)->authorDeleting($user);
            $user->delete();
        });
    }
}
