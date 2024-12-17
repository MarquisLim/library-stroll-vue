<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Artwork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommentController extends Controller
{
    public function index($artworkId, Request $request)
    {
        $perPage = 5;
        $page = (int)$request->get('page', 1);

        $artwork = Artwork::findOrFail($artworkId);

        $query = Comment::where('commentable_id', $artworkId)
            ->where('commentable_type', Artwork::class)
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->orderBy('created_at', 'desc');

        $total = $query->count();
        $comments = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'comments' => $comments,
            'total' => $total,
            'hasMore' => $page * $perPage < $total
        ]);
    }


    /**
     * Добавление нового комментария.
     */
    public function store(Request $request, $artworkId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Неавторизован'], 403);
        }

        $artwork = Artwork::findOrFail($artworkId);

        $request->validate([
            'text' => 'required|string|max:1000',
        ]);

        $comment = Comment::create([
            'user_id' => Auth::id(),
            'commentable_id' => $artworkId,
            'commentable_type' => Artwork::class,
            'text' => $request->text,
        ]);

        $comment->load('user');

        // Уведомить автора артворка о новом комментарии (при необходимости)
        // ...

        return response()->json(['comment' => $comment]);
    }

    /**
     * Добавление ответа на комментарий.
     */
    public function reply(Request $request, $id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Неавторизован'], 403);
        }

        $parent = Comment::findOrFail($id);

        $request->validate([
            'text' => 'required|string|max:1000',
        ]);

        $reply = Comment::create([
            'user_id' => Auth::id(),
            'commentable_id' => $parent->commentable_id,
            'commentable_type' => $parent->commentable_type,
            'parent_id' => $parent->id,
            'text' => $request->text,
        ]);

        $reply->load('user');

        // Уведомить автора исходного комментария о новом ответе (при необходимости)
        // ...

        return response()->json(['reply' => $reply]);
    }

}
