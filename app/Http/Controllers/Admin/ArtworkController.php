<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class ArtworkController extends Controller
{
    public function index(Request $r)
    {
        $q = Artwork::query();

        if ($r->status === 'published') $q->where('is_published', true);
        if ($r->status === 'draft')     $q->where('is_published', false);

        if ($r->visibility === 'public')  $q->where('is_private', false);
        if ($r->visibility === 'private') $q->where('is_private', true);

        if (in_array($r->type, ['image','video'])) {
            $q->where('type', $r->type);
        }

        if ($r->filled('search')) {
            $q->where('title', 'like', '%'.$r->search.'%');
        }

        $sort = in_array($r->sort, ['views','likes']) ? $r->sort : 'updated';
        $dir  = $r->dir === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'views' => $q->orderBy('views_count', $dir),
            'likes' => $q->orderBy('likes_count', $dir),
            default => $q->orderBy('updated_at', $dir),
        };

        $artworks = $q->with(['media','tags:id,name','collections:id,name'])
            ->withCount('likes')
            ->withoutGlobalScopes()
            ->paginate(20)
            ->withQueryString()
            ->through(fn($a) => [
                'id'            => $a->id,
                'title'         => $a->title,
                'description'   => $a->description,
                'type'          => $a->type,
                'is_adult'      => (bool)$a->is_adult,
                'has_ai'        => (bool)$a->has_ai,
                'is_published'  => (bool)$a->is_published,
                'is_private'    => (bool)$a->is_private,
                'allow_download'=> (bool)$a->allow_download,
                'allow_comments'=> (bool)$a->allow_comments,
                'views_count'   => $a->views_count,
                'likes_count'   => $a->likes_count,
                'tags'          => $a->tags->map->only(['id','name']),
                'collections'   => $a->collections->map->only(['id','name']),
                'tags_short'    => $a->tags->pluck('name')
                    ->pipe(fn($t) => $t->take(2)->implode(', ')
                        .($t->count()>2 ? ' +' . ($t->count()-2) : '')),
                'thumb_url'     => $a->thumb_url,
                'original_url'  => $a->original_url,
            ]);

        $filters = [
            'visibility' => $r->visibility   ?: 'all',
            'type'       => $r->type         ?: 'all',
            'status'     => $r->status       ?: 'all',
            'sort'       => $r->sort         ?: 'updated',
            'dir'        => $r->dir          ?: 'desc',
            'search'     => $r->search       ?: '',
        ];

        $collections = Collection::orderBy('name')
            ->get(['id','name']);

        return Inertia::render('Studio/Manager', [
            'artworks'    => $artworks,
            'filters'     => $filters,
            'collections' => $collections,
            'isAdminView' => true,
        ]);
    }
}
