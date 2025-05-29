<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class ReadMarkerController extends Controller
{
    public function update(Conversation $conversation, Request $request)
    {
        $this->authorize('view', $conversation);

        $conversation->users()->updateExistingPivot(
            $request->user()->id,
            [
                'last_read_at'  => now(),
                'last_read_id'  => $request->input('last_read_id')
            ]
        );

        return response()->noContent();
    }

}
