<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Artwork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommentController extends Controller
{
    public function index($artworkId){
        $artwork = Artwork::findOrFail($artworkId);
        $comments = Comment::where('commentable_id',$artworkId)
            ->where('commentable_type', Artwork::class)
            ->whereNull('parent_id')
            ->with(['user','replies.user'])
            ->get();
        return response()->json(['comments'=>$comments]);
    }

    public function store(Request $request, $artworkId){
        if(!Auth::check()){
            return response()->json(['error'=>'Not authorized'],403);
        }
        $artwork = Artwork::findOrFail($artworkId);
        $comment = new Comment();
        $comment->user_id = Auth::id();
        $comment->text = $request->text;
        $comment->commentable_id = $artworkId;
        $comment->commentable_type = Artwork::class;
        $comment->save();
        $comment->load('user','replies.user');

        // Уведомить автора о новом комментарии
        $author = $artwork->user;
        if($author->id!==Auth::id()){
            $author->notify(new CommentAddedNotification(Auth::user(), $artwork, $comment));
        }

        return response()->json(['comment'=>$comment]);
    }

    public function reply(Request $request, $id){
        if(!Auth::check()){
            return response()->json(['error'=>'Not authorized'],403);
        }
        $parent = Comment::findOrFail($id);
        $reply = new Comment();
        $reply->user_id = Auth::id();
        $reply->text = $request->text;
        $reply->commentable_id = $parent->commentable_id;
        $reply->commentable_type = $parent->commentable_type;
        $reply->parent_id = $parent->id;
        $reply->save();
        $reply->load('user');

        // Уведомить автора исходного комментария
        $parentUser=$parent->user;
        if($parentUser->id!==Auth::id()){
            $parentUser->notify(new CommentRepliedNotification(Auth::user(), $parent, $reply));
        }

        return response()->json(['reply'=>$reply]);
    }

}
