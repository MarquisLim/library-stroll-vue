<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CollectionController extends Controller
{
    public function show(Collection $collection)
    {
        $userId = Auth::id();

        $collection->load([
            'user',
            'artworks' => function ($query) {
                $query->with([
                    'user',
                    'media',
                    'likes',
                    'collections'
                ])->withCount('likes'); // Добавляем подсчет лайков
            }
        ]);

        $collection->artworks = $collection->artworks->map(function($art) use ($userId) {
            $art->liked_by_user = $userId ? $art->likes->where('user_id',$userId)->isNotEmpty():false;
            $art->in_collections = $userId ? $art->collections->pluck('id')->toArray():[];
            return $art;
        });

        // Загрузим коллекции текущего пользователя, чтобы были видны в селекторе
        $userCollections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return inertia('Collections/Show', [
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'created_at' => $collection->created_at,
                'artworks_count' => $collection->artworks->count(),
            ],
            'author' => $collection->user->only('id','name','profile_photo_url'),
            'artworks' => $collection->artworks,
            'userCollections' => $userCollections // передаем в props
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

    public function create(Request $request)
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

        // Проверяем права (коллекцию может редактировать только её владелец)
        if ($collection->user_id !== Auth::id()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'is_private' => 'boolean',
        ]);

        $collection->update($data);

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
