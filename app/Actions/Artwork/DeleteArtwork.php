<?php

namespace App\Actions\Artwork;

use App\Models\Artwork;
use App\Models\Comment;
use App\Models\Models\Complaint\Complaint;
use App\Services\AiService\ArtworkIndexVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteArtwork
{
    public function handle(Artwork $artwork): void
    {
        DB::transaction(function () use ($artwork) {
            $artwork = Artwork::withoutGlobalScopes()->lockForUpdate()->find($artwork->id);
            if (! $artwork) {
                return;
            }

            $context = [
                'artwork_id' => $artwork->id,
                'media_ids' => $artwork->media()->pluck('id')->all(),
            ];
            app(ArtworkIndexVisibility::class)->removeAfterCommit($artwork);

            Comment::withoutGlobalScopes()
                ->where('commentable_type', Artwork::class)
                ->where('commentable_id', $artwork->id)
                ->delete();

            Complaint::where('complaintable_type', Artwork::class)
                ->where('complaintable_id', $artwork->id)
                ->delete();

            $artwork->delete();
            DB::afterCommit(fn () => Log::info('Artwork deleted', $context));
        });
    }
}
