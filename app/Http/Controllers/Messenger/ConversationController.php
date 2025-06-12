<?php

namespace App\Http\Controllers\Messenger;

use App\Events\ConversationCreated;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ConversationController extends Controller
{
    public function index(Request $request, ?Conversation $conversation = null)
    {
        $user = auth()->user();
        $convs = $user
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
            ->withCount([
                'messages as unread' => function ($q) use ($user) {
                    $q->where('user_id', '!=', $user->id)
                        ->when(function ($query) use ($user) {
                            $query->whereColumn('created_at', '>', 'conversation_user.last_read_at');
                        });
                }
            ])
            ->orderByDesc('updated_at')
            ->paginate(20);


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
                    'replyTo.user:id,name,profile_photo_path',
                    'replyTo.attachments',
                    'replyTo.artwork:id,title',
                    'replies',
                    'artwork' => function($q) {
                        $q->with(['media','user'])->withCount('likes');
                    },
                ])
                ->take(50)
                ->latest('id')->get()->reverse()->values();
        }

        foreach ($messages as $m) {
            Log::info('Message ID='.$m->id.' replyTo_id=' . ($m->replyTo ? $m->replyTo->id : 'null'));
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
            'user_ids' => 'array|min:1',
            'title'    => 'nullable|string|max:100',
            'type'     => 'required|in:dialog,group',
            'avatar'   => 'nullable|file|image|max:5120',
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

        // Группа
        $conv = DB::transaction(function () use ($data, $request) {
            $avatarPath = null;
            if ($request->hasFile('avatar')) {
                $avatarPath = $request->file('avatar')->store('conversation_avatars', 'public');
            }
            $conv = Conversation::create([
                'type'  => $data['type'],
                'title' => $data['title'] ?? null,
                'avatar' => $avatarPath,
            ]);
            $conv->users()->attach($request->user()->id, ['role' => 'admin']);
            $otherIds = array_diff($data['user_ids'], [$request->user()->id]);
            if (!empty($otherIds)) {
                $conv->users()->attach($otherIds);
            }
            return $conv;
        });

        $conv->load('users');

        broadcast(new ConversationCreated($conv));

        if ($request->wantsJson()) {
            return response()->json(['id' => $conv->id, 'avatar_url' => $conv->avatar_url]);
        }
        return redirect()->route('messenger', $conv->id);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'title' => 'required|string|max:100',
        ]);

        $conversation->update(['title' => $data['title']]);

        return response()->json([
            'title'      => $conversation->title,
            'avatar_url' => $conversation->avatar_url,
        ]);
    }

    public function addUser(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);
        $user = Auth::user();
        $pivot = $conversation->users()->where('user_id', $user->id)->first()->pivot;
        if ($pivot->role !== 'admin') {
            abort(403);
        }

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $newUser = User::findOrFail($data['user_id']);
        // Проверяем, что его ещё нет в группе
        if ($conversation->users()->where('user_id', $newUser->id)->exists()) {
            return response()->json(['message' => 'Пользователь уже в группе'], 422);
        }
        $conversation->users()->attach($newUser->id);
        return response()->json(['success' => true]);
    }

    public function removeUser(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);
        $user = Auth::user();
        $pivot = $conversation->users()->where('user_id', $user->id)->first()->pivot;
        if ($pivot->role !== 'admin') {
            abort(403);
        }

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        // Нельзя удалить самого себя этим методом
        if ($data['user_id'] === $user->id) {
            return response()->json(['message' => 'Нельзя удалить самого себя'], 422);
        }

        $conversation->users()->detach($data['user_id']);
        return response()->json(['success' => true]);
    }

    public function leaveGroup(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);
        $user = Auth::user();
        $pivot = $conversation->users()->where('user_id', $user->id)->first()->pivot;
        if ($pivot->role === 'admin') {
            return response()->json(['message' => 'Админ не может просто выйти'], 422);
        }

        $conversation->users()->detach($user->id);
        return response()->json(['success' => true]);
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
