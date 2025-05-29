<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\Like;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $uid = $u->id;

        /* ───── агрегированные показатели ───── */
        $totalArtworks  = $u->artworks()->count();
        $totalViews     = $u->artworks()->sum('views_count');
        $totalLikes     = Like::whereHas('artwork', fn ($q) => $q->where('user_id', $u->id))->count();
        $totalComments  = Comment::whereHasMorph(
            'commentable',
            [Artwork::class],
            fn ($q) => $q->where('user_id', $u->id)
        )->count();

        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        $decorate = fn($q) => $q
            ->with(['user:id,name,profile_photo_path','collections:id']) // нужны для селектора
            ->withCount('likes')
            ->get()
            ->map(function($art) use ($uid){
                $art->liked_by_user  = $art->likes->where('user_id',$uid)->isNotEmpty();
                $art->in_collections = $art->collections->pluck('id');
                return $art;
            });

        $topArtwork  = $decorate(
            $u->artworks()->orderByDesc('likes_count')->limit(1)
        )->first();

        $recentWorks = $decorate(
            $u->artworks()->where('is_published',true)->latest()->take(8)
        );

        $drafts      = $decorate(
            $u->artworks()->where('is_published',false)->latest('updated_at')->take(8)
        );

        $likesDays    = in_array($request->integer('likes',    7), [7, 30, 365]) ? $request->integer('likes',    7) : 7;
        $commentsDays = in_array($request->integer('comments', 7), [7, 30, 365]) ? $request->integer('comments', 7) : 7;

        $makeSeries = function (int $days, \Closure $builder) {
            $from     = now()->startOfDay()->subDays($days - 1);
            $skeleton = collect(\Carbon\CarbonPeriod::create($from, now()))
                ->mapWithKeys(fn ($d) => [$d->format('Y-m-d') => 0]);
            return $skeleton->merge($builder($from))->sortKeys();
        };

        $likes = $makeSeries($likesDays, function ($from) use ($uid) {
            return Like::selectRaw('DATE(created_at) d, COUNT(*) c')
                ->whereHas('artwork', fn ($q) => $q->where('user_id', $uid))
                ->where('created_at', '>=', $from)
                ->groupBy('d')->pluck('c', 'd');
        });

        $comments = $makeSeries($commentsDays, function ($from) use ($uid) {
            return Comment::selectRaw('DATE(created_at) d, COUNT(*) c')
                ->whereHasMorph('commentable', [Artwork::class],
                    fn ($q) => $q->where('user_id', $uid))
                ->where('created_at', '>=', $from)
                ->groupBy('d')->pluck('c', 'd');
        });

        return Inertia::render('Dashboard/Index', [
            'stats'   => [
                'artworks'  => $totalArtworks,
                'views'     => $totalViews,
                'likes'     => $totalLikes,
                'comments'  => $totalComments,
            ],
            'topArtwork' => $topArtwork,
            'likesChart' => $likes,
            'commentsChart'  => $comments,
            'likesDays'     => $likesDays,
            'commentsDays'  => $commentsDays,
            'recentWorks' => $recentWorks,
            'drafts'      => $drafts,
            'collections' => $collections,
        ])->withViewData(['layout' => 'DashboardLayout']);
    }
}
