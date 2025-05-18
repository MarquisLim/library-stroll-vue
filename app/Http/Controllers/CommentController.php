<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Artwork;
use App\Notifications\CommentReceived;
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
            ->with(['user', 'replies.user', 'replies.parent.user'])
            ->orderBy('created_at', 'desc');

        $total = $query->count();
        $comments = Comment::whereNull('parent_id')
            ->where('commentable_id', $artworkId)
            ->with([
                'user',
                'replies.user',
                'replies.parent.user',
                'replies.replies.user',
                'replies.replies.parent.user',
            ])
            ->orderBy('created_at','desc')
            ->skip(($page-1)*$perPage)->take($perPage)
            ->get();

        return response()->json([
            'comments' => $comments,
            'total' => $total,
            'hasMore' => $page * $perPage < $total
        ]);
    }


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
        $owner   = $comment->commentable->user;
        $owner->notify(new CommentReceived($comment));

        return response()->json(['comment' => $comment]);
    }


    public function reply(Request $request, $id)
    {
        $user   = Auth::user() ?: abort(403);
        $parent = Comment::with('parent')->findOrFail($id);

        $request->validate(['text' => 'required|string|max:1000']);

        $root = $parent->parent_id ? $parent->parent : $parent;

        $reply = Comment::create([
            'user_id'          => $user->id,
            'commentable_id'   => $root->commentable_id,
            'commentable_type' => Artwork::class,
            'parent_id'        => $root->id,
            'text'             => $request->text,
        ]);

        $owner   = $reply->commentable->user;
        $owner->notify(new CommentReceived($reply));

        $reply->load(['user', 'parent.user']);
        return response()->json(['reply' => $reply]);
    }


}
