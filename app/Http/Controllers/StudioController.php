<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class StudioController extends Controller
{
    public function initialData()
    {
        $drafts = Artwork::where('user_id',Auth::id())
            ->where('is_published',false)
            ->with('media','tags','collections')
            ->orderBy('order_column')
            ->get();

        $collections = Collection::where('user_id',Auth::id())->get();

        return ['drafts'=>$drafts,'collections'=>$collections];
    }

    // Начальная загрузка страницы через Inertia
    public function index()
    {
        return inertia('Studio/StudioIndex', $this->initialData());
    }

    public function createEmptyDraft(Request $request)
    {
        $artwork = new Artwork();
        $artwork->user_id = Auth::id();
        $artwork->is_published = false;
        $artwork->save();

        $allCollection = Collection::firstOrCreate([
            'user_id'=>Auth::id(),
            'name'=>'Все'
        ],['is_private'=>false]);
        $artwork->collections()->attach($allCollection->id);

        return response()->json([
            'message'=>'Пустой черновик создан',
            'artwork'=>$artwork->load('media','tags','collections')
        ]);
    }

    public function uploadFile(Request $request)
    {
        $request->validate([
            'file'=>'required|file|max:20480|mimes:jpg,jpeg,png,gif,mp4,mov,avi,webm'
        ],[
            'file.mimes'=>'Неверный формат файла (jpg,jpeg,png,gif,mp4,mov,avi,webm)',
            'file.max'=>'Слишком большой файл. Максимум 20 МБ.',
        ]);

        if($request->draftId){
            $artwork = Artwork::where('user_id',Auth::id())->findOrFail($request->draftId);
            $artwork->clearMediaCollection('artworks');
        } else {
            $artwork = new Artwork();
            $artwork->user_id = Auth::id();
            $artwork->is_published = false;
            $artwork->save();

            $allCollection = Collection::firstOrCreate([
                'user_id'=>Auth::id(),
                'name'=>'Все'
            ], ['is_private'=>false]);
            $artwork->collections()->attach($allCollection->id);
        }

        $artwork->addMedia($request->file('file'))
            ->usingFileName(Str::random(10).'.'.$request->file('file')->getClientOriginalExtension())
            ->toMediaCollection('artworks');

        return response()->json([
            'message'=>'Файл загружен',
            'artwork'=>$artwork->load('media','tags','collections')
        ]);
    }

    public function updateDraft(Request $request, $id)
    {
        $artwork = Artwork::where('user_id', Auth::id())->findOrFail($id);

        $artwork->update([
            'title'=>$request->title,
            'description'=>$request->description,
            'is_adult'=>$request->is_adult ? true : false,
            'has_ai'=>$request->has_ai ? true : false,
            'is_private'=>$request->is_private ? true : false,
            'allow_download'=>$request->allow_download ? true : false,
            'allow_comments'=>$request->allow_comments ? true : false,
        ]);

        $tags = $request->tags ?? [];
        $tagIds = [];
        foreach($tags as $t){
            $tag = Tag::firstOrCreate(['name'=>$t]);
            $tagIds[] = $tag->id;
        }
        $artwork->tags()->sync($tagIds);

        $collectionIds = $request->collections ?? [];
        $allCollection = Collection::firstOrCreate([
            'user_id'=>Auth::id(),
            'name'=>'Все'
        ],['is_private'=>false]);

        if(!in_array($allCollection->id,$collectionIds)){
            $collectionIds[]=$allCollection->id;
        }
        $artwork->collections()->sync($collectionIds);

        return response()->json([
            'message'=>'Черновик обновлен',
            'artwork'=>$artwork->load('media','tags','collections')
        ]);
    }

    public function publish(Request $request, $id)
    {
        $artwork = Artwork::where('user_id', Auth::id())->findOrFail($id);
        if(!$artwork->getFirstMediaUrl('artworks')){
            return response()->json(['error'=>'Загрузите файл прежде чем публиковать.'],422);
        }
        $artwork->is_published = true;
        $artwork->save();

        return response()->json([
            'message'=>'Арт опубликован'
        ]);
    }

    public function destroyDraft(Request $request, $id)
    {
        $artwork = Artwork::where('user_id',Auth::id())->findOrFail($id);
        $artwork->delete();
        return response()->json(['message'=>'Черновик удален']);
    }

    public function createCollection(Request $request)
    {
        $request->validate([
            'name'=>'required|string|max:255'
        ]);

        $col = new Collection();
        $col->user_id = Auth::id();
        $col->name = $request->name;
        $col->is_private = $request->is_private ?? false;
        $col->save();

        return response()->json([
            'message'=>'Коллекция создана',
            'collection'=>$col
        ]);
    }

    public function reorderDrafts(Request $request)
    {
        $order = $request->draft_order ?? [];
        foreach($order as $index => $artworkId){
            Artwork::where('id',$artworkId)->where('user_id',Auth::id())->update(['order_column'=>$index]);
        }
        return response()->json(['message'=>'Порядок обновлен']);
    }

    public function searchTags(Request $request)
    {
        $query = $request->query('query','');
        $tags = Tag::where('name','like','%'.$query.'%')->limit(10)->get(['id','name']);
        return response()->json(['tags'=>$tags]);
    }

    public function searchCollections(Request $request)
    {
        $query = $request->query('query','');
        $collections = Collection::where('user_id',Auth::id())
            ->where('name','like','%'.$query.'%')
            ->limit(10)->get(['id','name','is_private']);
        return response()->json(['collections'=>$collections]);
    }
}
