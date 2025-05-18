<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CollectionController extends Controller
{
    public function show(Collection $collection)
    {
        $userId  = Auth::id();
        $isOwner = $userId === $collection->user_id;

        if ($collection->is_private && ! $isOwner) {
            abort(403, 'У вас нет доступа к этой коллекции');
        }

        $collection->load([
            'user',
            'artworks' => function ($query) use ($isOwner) {
                if (! $isOwner) {
                    $query->where('is_published', true)
                        ->where('is_private', false);
                }
                $query->with(['user', 'media', 'likes', 'collections'])
                    ->withCount('likes');
            }
        ]);

        $collection->artworks = $collection->artworks->map(function ($art) use ($userId) {
            $art->liked_by_user  = $userId
                ? $art->likes->contains('user_id', $userId)
                : false;
            $art->in_collections = $userId
                ? $art->collections->pluck('id')->toArray()
                : [];
            return $art;
        });

        $userCollections = Collection::where('user_id', $userId)
            ->with(['artworks' => function ($q) {
                $q->where('is_published', true)
                    ->where('is_private', false)
                    ->with('media');
            }])
            ->get();

        return inertia('Collections/Show', [
            'collection' => [
                'id'              => $collection->id,
                'name'            => $collection->name,
                'created_at'      => $collection->created_at,
                'artworks_count'  => $collection->artworks->count(),
            ],
            'author'          => $collection->user->only('id','name','profile_photo_url'),
            'artworks'        => $collection->artworks,
            'userCollections' => $userCollections,
        ]);
    }

    public function getCollectionsWithMedia()
    {
        $collections = Collection::with(['artworks.media'])->get();

        $collections = $collections->map(function ($collection) {
            $firstMedia = optional($collection->artworks->first()?->getFirstMedia())->getUrl();
            return [
                'id' => $collection->id,
                'name' => $collection->name,
                'image' => $firstMedia // URL первой медиа-записи или null
            ];
        });

        return response()->json($collections);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'is_private' => 'boolean'
        ]);

        $data['user_id'] = Auth::id();
        $collection = Collection::create($data);

        $collection->setRelation('artworks', collect([]));
        $collection->artworks_count = 0;

        return response()->json(['collection' => $collection]);
    }

    public function update(Request $request, $id)
    {
        $collection = Collection::findOrFail($id);

        if ($collection->user_id !== Auth::id()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'is_private' => 'boolean',
        ]);

        $collection->update($data);

        $collection->load(['artworks' => function($q) {
            $q->where('is_published', true)
                ->where('is_private', false)
                ->with('media');
        }]);

        return response()->json([
            'message' => 'Коллекция обновлена',
            'collection' => $collection,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $collection = Collection::findOrFail($id);

        if ($collection->user_id !== Auth::id()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $collection->delete();

        return response()->json(['message' => 'Коллекция удалена']);
    }


}
