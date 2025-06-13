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
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StudioController extends Controller
{
    /* --- Данные --- */
    public function initialData()
    {
        $drafts = Artwork::where('user_id',Auth::id())
            ->where('is_published',false)
            ->with('media','tags','collections')
            ->orderBy('order_column')
            ->get();

        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function ($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return ['drafts' => $drafts, 'collections' => $collections];
    }

    /* --- Главная страница Студии --- */
    public function index()
    {
        return inertia('Studio/StudioIndex', $this->initialData());
    }

    /* --- Пустой черновик --- */
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

    /* --- Загрузка файла --- */
    public function uploadFile(UploadArtworkRequest $request)
    {
        $artwork = Artwork::firstOrNew([
            'id' => $request->draftId,
            'user_id' => auth()->id(),
        ], ['is_published'=>false, 'allow_comments'=>true]);
        $artwork->save();

        $artwork->clearMediaCollection('artworks');
        $artwork->clearMediaCollection('preview');

        $media = $artwork
            ->addMedia($request->file('file'))
            ->usingFileName(Str::random(10).'.'.$request->file('file')->getClientOriginalExtension())
            ->toMediaCollection('artworks');

        $artwork->type = explode('/', $media->mime_type)[0];
        $artwork->save();

        /* --- Условие для превью видео --- */
        if ($artwork->type === 'video') {
            ProcessVideoPreviewJob::dispatch($media);
        }

        return response()->json([
            'message'=>'Файл загружен',
            'artwork'=>$artwork->load('media','tags','collections')->append(['thumb_url','preview_url','video_duration_formatted']),
        ]);
    }

    /* --- Обновления черновика --- */
    public function updateDraft(Request $request, $id)
    {
        $user = Auth::user();

        $draft = Artwork::when(! $user->hasAnyRole(['Admin', 'SuperAdmin']), function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->findOrFail($id);
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
        $draft->collections()->sync($collections);

        $draft->load('media','tags','collections');
        return response()->json(['message'=>'Черновик обновлен','artwork'=>$draft]);
    }

    /* --- Публикация --- */
    public function publish(Request $request, $id)
    {
        $user = Auth::user();

        $art = Artwork::when(! $user->hasAnyRole(['Admin', 'SuperAdmin']), function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->findOrFail($id);

        if(!$art->getFirstMediaUrl('artworks'))
            return response()->json(['error'=>'Сначала загрузите файл'],422);

        if(trim($art->title)==='')
            return response()->json(['error'=>'Добавьте название'],422);

        $art->is_published = true;
        $art->published_at = now();
        $art->save();

        return response()->json([
            'message'=>'Арт опубликован'
        ]);
    }

    /* --- Удаление черновика --- */
    public function destroyDraft(Request $request, $id)
    {
        $user = Auth::user();

        $art = Artwork::when(! $user->hasAnyRole(['Admin', 'SuperAdmin']), function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->findOrFail($id);
        $art->delete();
        return response()->json(['message'=>'Черновик удален']);
    }

    /* --- Обновление списка --- */
    public function reorderDrafts(Request $request)
    {
        $order = $request->draft_order ?? [];
        foreach($order as $index => $artworkId){
            Artwork::where('id',$artworkId)->where('user_id',Auth::id())->update(['order_column'=>$index]);
        }
        return response()->json(['message'=>'Порядок обновлен']);
    }

    /* --- Поиск тегов --- */
    public function searchTags(Request $request)
    {
        $query = $request->query('query','');
        $tags = Tag::where('name','like','%'.$query.'%')->limit(10)->get(['id','name']);
        return response()->json(['tags'=>$tags]);
    }

    /* --- Поиск коллекций --- */
    public function searchCollections(Request $request)
    {
        $query = $request->query('query','');
        $collections = Collection::where('user_id',Auth::id())
            ->where('name','like','%'.$query.'%')
            ->limit(10)->get(['id','name','is_private']);
        return response()->json(['collections'=>$collections]);
    }

    /* --- Менеджер артворков --- */
    public function manager(Request $r)
    {
        $u  = $r->user();
        $q  = Artwork::where('user_id',$u->id);

        /* ---------- фильтры ---------- */
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
        $sort = in_array($r->sort,['views','likes', 'updated']) ? $r->sort : 'created';
        $dir  = $r->dir === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'views'     => $q->orderBy('views_count',$dir),
            'likes'     => $q->orderBy('likes_count',$dir),
            'updated'   => $q->orderBy('updated_at',$dir),
            default => $q->orderBy('created_at',$dir),
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
                    'updated_date'     => $a->updated_at,
                ];
            });

        return inertia('Studio/Manager', [
            'artworks' => $artworks,
            'filters'  => $r->only('visibility','type','search','status','sort','dir')
                + ['visibility'=>'all','type'=>'all','status'=>'all','sort'=>'created','dir'=>'desc'],
            'collections'=> Collection::where('user_id',$u->id)->orderBy('name')->get(['id','name']),
        ]);
    }

    /* --- Менеджер коллекции --- */
    public function collectionsManager(Request $request)
    {
        $user = $request->user();

        $filters = $request->validate([
            'visibility' => ['nullable', 'in:all,public,private'],
            'search'     => ['nullable', 'string', 'max:100'],
            'sort'       => ['nullable', Rule::in(['created', 'items', 'updated'])],
            'dir'        => ['nullable', 'in:asc,desc'],
            'page'       => ['nullable', 'integer'],
        ]);

        $query = Collection::where('user_id', $user->id)
            ->withCount('artworks')
            ->with(['artworks.media' => fn($q) => $q->take(1)]);

        $visibility = $filters['visibility'] ?? 'all';
        if ($visibility !== 'all') {
            $query->where('is_private', $visibility === 'private');
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        $sort = $filters['sort'] ?? 'created';
        $dir  = $filters['dir']  ?? 'desc';

        match ($sort) {
            'items'   => $query->orderBy('artworks_count', $dir),
            'updated' => $query->orderBy('updated_at',      $dir),
            default   => $query->orderBy('created_at',      $dir),
        };

        $collections = $query
            ->paginate(10)
            ->withQueryString()
            ->through(function ($c) {
                $thumb = '';
                if ($c->artworks->isNotEmpty()) {
                    $media = $c->artworks->first()->getFirstMedia('artworks');
                    $thumb = $media?->getFullUrl('thumb') ?? $media?->getFullUrl();
                }

                return [
                    'id'              => $c->id,
                    'name'            => $c->name,
                    'is_private'      => (bool) $c->is_private,
                    'artworks_count'  => $c->artworks_count,
                    'thumb'           => $thumb,
                    'updated_date'    => $c->updated_at,
                ];
            });

        return Inertia::render('Studio/CollectionManager', [
            'collections' => $collections,
            'filters'     => [
                'visibility' => $filters['visibility'] ?? 'all',
                'search'     => $filters['search']     ?? '',
                'sort'       => $sort,
                'dir'        => $dir,
            ],
        ]);
    }
}
