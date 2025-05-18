<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Comment;
use App\Models\Models\Complaint\ComplaintType;
use App\Models\User;
use App\Notifications\ComplaintCreated;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    private function validateAndCreate(Request $request, $entity)
    {
        $data = $request->validate([
            'type'        => 'required|exists:complaint_types,slug',
            'details'     => 'nullable|string|max:1000',
        ]);
        $type = ComplaintType::where('slug', $data['type'])->first();
        $complaint = $entity->complaints()->create([
            'user_id'           => $request->user()->id,
            'complaint_type_id' => $type->id,
            'details'           => $data['details'] ?? null,
        ]);

        $owner = $entity instanceof Comment
            ? $entity->commentable->user
            : ($entity instanceof Artwork
                ? $entity->user
                : $entity);

        $owner->notify( new ComplaintCreated($complaint, $owner->id) );

        User::role(['Moderator','Admin','SuperAdmin'])
            ->each(fn ($mod) => $mod->notify(
                new ComplaintCreated($complaint, $mod->id)
            ));

        return response()->json(['message'=>'Ваша жалоба отправлена.']);
    }

    public function storeArtwork(Request $r, Artwork $artwork)
    {
        return $this->validateAndCreate($r, $artwork);
    }

    public function storeComment(Request $r, Comment $comment)
    {
        return $this->validateAndCreate($r, $comment);
    }

    public function storeProfile(Request $r, User $user)
    {
        return $this->validateAndCreate($r, $user);
    }
}
