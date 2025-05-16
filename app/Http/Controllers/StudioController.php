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
use Inertia\Inertia;

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
        $art = Artwork::where('user_id',Auth::id())->findOrFail($id);

        if(!$art->getFirstMediaUrl('artworks'))
            return response()->json(['error'=>'Сначала загрузите файл'],422);

        if(trim($art->title)==='')
            return response()->json(['error'=>'Добавьте название'],422);

        $art->is_published = true;
        $art->save();

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

    public function manager(Request $r)
    {
        $u  = $r->user();
        $q  = Artwork::where('user_id',$u->id);

        /* ---------- фильтры ---------- */
        /* статус: published | draft | all */
        if ($r->status === 'published') $q->where('is_published',true);
        if ($r->status === 'draft')     $q->where('is_published',false);

        /* видимость */
        if ($r->visibility === 'public')  $q->where('is_private',false);
        if ($r->visibility === 'private') $q->where('is_private',true);

        /* тип */
        if ($r->type === 'image' || $r->type === 'video')
            $q->where('type',$r->type);

        /* поиск */
        if ($r->filled('search'))
            $q->where('title','like','%'.$r->search.'%');

        /* ---------- сортировка ---------- */
        $sort = in_array($r->sort,['views','likes']) ? $r->sort : 'updated';
        $dir  = $r->dir === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'views' => $q->orderBy('views_count',$dir),
            'likes' => $q->orderBy('likes_count',$dir),
            default => $q->orderBy('updated_at',$dir),
        };

        /* ---------- пагинация ---------- */
        $artworks = $q->with(['media','tags:id,name', 'collections:id,name'])
            ->withCount('likes')
            ->paginate(20)
            ->withQueryString()
            ->through(function($a){
                $tags = $a->tags->pluck('name');
                return [
                    'id'            => $a->id,
                    'title'         => $a->title,
                    'description'   => $a->description,
                    'type'          => $a->type,
                    'is_private'    => (bool)$a->is_private,
                    'is_adult'      => (bool)$a->is_adult,
                    'has_ai'        => (bool)$a->has_ai,
                    'is_published'  => (bool)$a->is_published,
                    'allow_download'=> (bool)$a->allow_download,
                    'allow_comments'=> (bool)$a->allow_comments,
                    'views_count'   => $a->views_count,
                    'likes_count'   => $a->likes_count,
                    'tags'          => $a->tags      ->map->only(['id','name']),
                    'collections'   => $a->collections->map->only(['id','name']),
                    'tags_short'    => $tags->take(2)->implode(', ')
                        .($tags->count()>2 ? ' +' . ($tags->count()-2) : ''),
                    'thumb_url'     => $a->thumb_url,
                    'original_url'     => $a->original_url,
                ];
            });

        return inertia('Studio/Manager', [
            'artworks' => $artworks,
            'filters'  => $r->only('visibility','type','search','status','sort','dir')
                + ['visibility'=>'all','type'=>'all','status'=>'all','sort'=>'updated','dir'=>'desc'],
            'collections'=> Collection::where('user_id',$u->id)->orderBy('name')->get(['id','name']),
        ]);
    }

    public function collectionsManager(Request $request)
    {
        $user = $request->user();

        // фильтры
        $filters = $request->validate([
            'visibility' => 'nullable|in:all,public,private',
            'search'     => 'nullable|string|max:100',
            'sort'       => 'nullable|in:updated,items',
            'dir'        => 'nullable|in:asc,desc',
            'page'       => 'nullable|integer',
        ]);

        $query = Collection::where('user_id', $user->id)
            ->withCount('artworks')
            ->with(['artworks.media' => fn($q) => $q->take(1)]);

        if (($filters['visibility'] ?? 'all') !== 'all') {
            $query->where('is_private', $filters['visibility'] === 'private');
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        // сортировка
        $sort = $filters['sort'] ?? 'updated';
        $dir  = $filters['dir']  ?? 'desc';
        if ($sort === 'items') {
            $query->orderBy('artworks_count', $dir);
        } else {
            $query->orderBy('updated_at', $dir);
        }

        $collections = $query->paginate(10)->through(function ($c) {
            $thumb = '';
            if ($c->artworks->isNotEmpty()) {
                $media = $c->artworks->first()->getFirstMedia('artworks');
                $thumb = $media?->getFullUrl('thumb') ?? $media?->getFullUrl();
            }
            return [
                'id'            => $c->id,
                'name'          => $c->name,
                'is_private'    => (bool) $c->is_private,
                'artworks_count'=> $c->artworks_count,
                'thumb'         => $thumb,
                'updated_at'    => $c->updated_at,
            ];
        });

        return Inertia::render('Studio/CollectionManager', [
            'collections' => $collections,
            'filters'     => [
                'visibility' => $filters['visibility'] ?? 'all',
                'search'     => $filters['search'] ?? '',
                'sort'       => $sort,
                'dir'        => $dir,
            ],
        ]);
    }

}
