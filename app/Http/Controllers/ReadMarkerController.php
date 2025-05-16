<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class ReadMarkerController extends Controller
{
    public function update(Conversation $conversation, Request $request)
    {
        $conversation->users()->updateExistingPivot(
            $request->user()->id,
            ['last_read_at' => now()]
        );

        return response()->noContent();
    }
}
