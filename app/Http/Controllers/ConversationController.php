<?php

namespace App\Http\Controllers;

use App\Events\ConversationCreated;
use App\Models\Collection;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ConversationController extends Controller
{
    public function index(Request $request, ?Conversation $conversation = null)
    {
        $user = auth()->user();
        $convs = $request->user()
            ->conversations()
            ->with([
                'users:id,name,profile_photo_path',
                'lastMessage' => function($q) {
                    $q->with([
                        'user:id,name,profile_photo_path',
                        'attachments',
                        'artwork.media',
                        'artwork.user:id,name,profile_photo_path',
                    ]);
                },
            ])
            ->orderByDesc('updated_at')
            ->paginate(20);

        foreach ($convs as $c) {
            $c->unread = $c->lastMessage && $c->pivot->last_read_id
                ? $c->messages()->where('id', '>', $c->pivot->last_read_id)
                    ->where('user_id', '!=', $user->id)->count()
                : $c->messages()
                    ->where('user_id', '!=', $request->user()->id)
                    ->count();

            $c->read_by = $c->users
                ->filter(fn($u) =>
                    $u->pivot->last_read_at &&
                    $c->lastMessage &&
                    $u->pivot->last_read_at >= $c->lastMessage->created_at
                )
                ->pluck('name');
        }

        $messages = collect();
        if ($conversation) {
            $this->authorize('view', $conversation);
            $conversation->load('users:id,name,profile_photo_path');

            $other = $conversation->users->firstWhere('id', '!=', $user->id);
            if ($other) {
                $other->is_blocked_by_me = $user->hasBlocked($other);
                $other->has_blocked_me   = $other->hasBlocked($user);
            }


            $messages = $conversation->messages()
                ->with([
                    'user',
                    'attachments',
                    'reactions',
                    'artwork' => function($q) {
                        $q->with(['media','user'])
                            ->withCount('likes');
                    },
                ])
                ->latest('id')->take(50)->get()->reverse()->values();
        }

        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return Inertia::render('Messenger/Index', [
            'conversations' => $convs,
            'conversation'  => $conversation,
            'messages'      => $messages,
            'collections'   => $collections,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_ids' => 'array|min:1',          // для dialog
            'title'    => 'nullable|string|max:100',
            'type'     => 'required|in:dialog,group',
        ]);

        if ($data['type'] === 'dialog' && count($data['user_ids']) === 1) {
            $otherId = $data['user_ids'][0];
            $meId    = $request->user()->id;

            $existing = Conversation::query()
                ->where('type', 'dialog')
                ->whereHas('users', fn($q) => $q->where('user_id', $meId))
                ->whereHas('users', fn($q) => $q->where('user_id', $otherId))
                ->withCount('users')
                ->having('users_count', 2)
                ->first();

            if ($existing) {
                if ($request->wantsJson()) {
                    return response()->json(['id' => $existing->id]);
                }
                return redirect()->route('messenger', $existing->id);
            }
        }

        $conv = DB::transaction(function () use ($data, $request) {
            $conv = Conversation::create([
                'type'  => $data['type'],
                'title' => $data['title'] ?? null,
            ]);

            $conv->users()->attach($request->user()->id, ['role'=>'admin']);
            if (!empty($data['user_ids'])) {
                $ids = array_diff($data['user_ids'], [$request->user()->id]);
                $conv->users()->attach($ids);
            }
            return $conv;
        });

        $conv->load('users');

        broadcast(new ConversationCreated($conv));

        if ($request->wantsJson()) {
            return response()->json(['id' => $conv->id]);
        }
        return redirect()->route('messenger', $conv->id);
    }

    public function show(Conversation $conversation)

    {
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()
            ->with([
                'user:id,name,profile_photo_path',
                'attachments',
                'reactions',
                'artwork' => function($q) {
                    $q->with(['media','user'])
                        ->withCount('likes');
                },
            ])
            ->latest('id')->take(50)->get()->reverse()->values();

        $otherUser = $conversation->users->firstWhere('id', '!=', auth()->id());

        $otherUser->is_blocked_by_me = auth()->user()->hasBlocked($otherUser);
        $otherUser->has_blocked_me   = $otherUser->hasBlocked(auth()->user());

        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return Inertia::render('Messenger/Chat', [
            'conversation' => $conversation->load('users:id,name,profile_photo_path'),
            'messages'     => $messages,
            'collections'     => $collections,
        ]);
    }

    public function destroy(Conversation $conversation)
    {
        $this->authorize('delete', $conversation);

        $conversation->messages()->with('attachments')->each(function ($message) {
            $message->attachments->each(function ($att) {
                Storage::disk($att->disk)->delete($att->path);
            });
        });

        $conversation->delete();

        return response()->json(['success' => true]);
    }
}
