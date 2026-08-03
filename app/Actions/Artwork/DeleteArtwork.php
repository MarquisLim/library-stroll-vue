<?php

namespace App\Actions\Artwork;

use App\Jobs\DeleteArtworkIndexJob;
use App\Models\Artwork;
use App\Models\Comment;
use App\Models\Models\Complaint\Complaint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteArtwork
{
    public function handle(Artwork $artwork): void
    {
        $context = [
            'artwork_id' => $artwork->id,
            'media_ids' => $artwork->media()->pluck('id')->all(),
        ];

        DB::transaction(function () use ($artwork) {
            Comment::withoutGlobalScopes()
                ->where('commentable_type', Artwork::class)
                ->where('commentable_id', $artwork->id)
                ->delete();

            Complaint::where('complaintable_type', Artwork::class)
                ->where('complaintable_id', $artwork->id)
                ->delete();

            $artwork->delete();
        });

        Log::info('Artwork deleted', $context);

        if (config('ai.enabled')) {
            DeleteArtworkIndexJob::dispatch($context['artwork_id']);
        }
    }
}
