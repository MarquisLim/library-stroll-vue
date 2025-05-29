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
            $pivot = $c->users
            ->firstWhere('id', $request->user()->id)
                ->pivot;

            $lastRead = $pivot->last_read_at;

            $c->unread = $c->pivot->last_read_at
                ? $c->messages()
                    ->where('user_id', '!=', $request->user()->id)
                    ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
                    ->count()
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

            $conversation->load(['users' => fn ($q) => $q
                ->where('users.id', $user->id)
                ->select('users.id', 'conversation_user.last_read_at')
            ]);

            $pivot     = $conversation->users()
                ->where('users.id', $user->id)
                ->first()
                ->pivot;

            $lastRead  = $pivot->last_read_at;

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
                    'artwork' => fn ($q) => $q
                        ->with(['media','user'])
                        ->withCount('likes'),
                ])
                ->latest('id')->take(50)->get()->reverse()->values()
                ->each(function ($m) use ($lastRead, $user) {
                    // <<< ВАЖНО: добавляем булево свойство
                    $m->unread_for_me =
                        $m->user_id !== $user->id &&
                        ($lastRead === null || $m->created_at > $lastRead);
                });
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
                // для AJAX-запросов возвращаем JSON
                if ($request->wantsJson()) {
                    return response()->json(['id' => $existing->id]);
                }
                // иначе редиректим на существующий чат
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

        $conversation->load(['users' => fn ($q) => $q
            ->where('users.id', auth()->id())
            ->select('users.id', 'conversation_user.last_read_at')
        ]);
        $lastRead = optional($conversation->users->first())->pivot->last_read_at;

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
            ->latest('id')->take(50)->get()->reverse()->values()
            ->each(function ($m) use ($lastRead) {
                $m->unread_for_me = $m->user_id !== auth()->id()
                    && ($lastRead === null || $m->created_at > $lastRead);
            });

        $otherUser = $conversation->users->firstWhere('id', '!=', auth()->id());

        $otherUser->is_blocked_by_me = auth()->user()->hasBlocked($otherUser);
        $otherUser->has_blocked_me   = $otherUser->hasBlocked(auth()->user());

        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();


        return Inertia::render('Messenger/Chat', [
            'conversation' => $conversation,
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
