<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadArtworkRequest;
use App\Jobs\ProcessVideoPreviewJob;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\X264;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

        return ['drafts' => $drafts, 'collections' => $collections];
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

    public function uploadFile(UploadArtworkRequest $request)
    {
        $artwork = Artwork::firstOrNew([
            'id' => $request->draftId,
            'user_id' => auth()->id(),
        ], ['is_published'=>false]);
        $artwork->save();
        $artwork->clearMediaCollection('artworks');

        $media = $artwork
            ->addMedia($request->file('file'))
            ->usingFileName(Str::random(10).'.'.$request->file('file')->getClientOriginalExtension())
            ->toMediaCollection('artworks');

        $artwork->type = explode('/', $media->mime_type)[0];
        $artwork->save();

        if ($artwork->type === 'video') {
            ProcessVideoPreviewJob::dispatch($media);
        }

        return response()->json([
            'message'=>'Файл загружен',
            'artwork'=>$artwork->load('media','tags','collections')->append(['thumb_url','preview_url','video_duration_formatted']),
        ]);
    }

    public function updateDraft(Request $request, $id)
    {
        $draft= Artwork::where('user_id',Auth::id())->findOrFail($id);
        $draft->title=$request->title;
        $draft->description=$request->description;
        $draft->is_adult=$request->is_adult ? true:false;
        $draft->has_ai=$request->has_ai ? true:false;
        $draft->is_private=$request->is_private ? true:false;
        $draft->allow_download=$request->allow_download ? true:false;
        $draft->allow_comments=$request->allow_comments ? true:false;
        $draft->save();

        // Теги
        $tagsArr=array_map('trim',$request->tags??[]);
        $tagIds=[];
        foreach($tagsArr as $tName){
            if($tName!==''){
                $tag=Tag::firstOrCreate(['name'=>$tName]);
                $tagIds[]=$tag->id;
            }
        }
        $draft->tags()->sync($tagIds);

        // Коллекции
        $collections=$request->collections ?? [];
        $all=Collection::firstOrCreate(['user_id'=>Auth::id(),'name'=>'Все'],['is_private'=>false]);
        if(!in_array($all->id,$collections)) $collections[]=$all->id;
        $draft->collections()->sync($collections);

        $draft->load('media','tags','collections');
        return response()->json(['message'=>'Draft updated','artwork'=>$draft]);
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
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'is_private' => 'boolean'
        ]);

        $data['user_id'] = Auth::id();
        $collection = Collection::create($data);

        // Принудительно назначаем пустой список артов и счетчик 0
        $collection->setRelation('artworks', collect([]));
        $collection->artworks_count = 0;

        return response()->json(['collection' => $collection]);
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
