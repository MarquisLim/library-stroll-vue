<?php

use App\Http\Controllers\ArtworkController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StudioController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [GalleryController::class,'index'])->name('gallery.index');
Route::get('/gallery/load-more',[GalleryController::class,'loadMore'])->name('gallery.loadMore');
Route::get('/artworks/{id}', [ArtworkController::class,'show'])->name('artworks.show');
Route::get('/author/{id}/works',[ArtworkController::class,'authorWorks'])->name('author.works');
Route::get('/search/suggestions',[SearchController::class,'suggestions'])->name('search.suggestions');
Route::get('/artworks/{id}/comments',[CommentController::class,'index'])->name('comments.index');
Route::get('/search',[SearchController::class,'index'])->name('search.index');

Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('user.profile.show');
Route::get('/profile/{user}/likes', [ProfileController::class, 'likes'])->name('user.profile.likes');
Route::get('/profile/{user}/collections', [ProfileController::class, 'collections'])->name('user.profile.collections');
Route::get('/collections/{collection}', [CollectionController::class, 'show'])->name('collections.show');

// Авторизованные маршруты
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/studio',[StudioController::class,'index'])->name('studio.index');
    Route::post('/studio/create-empty-draft',[StudioController::class,'createEmptyDraft'])->name('studio.createEmptyDraft');
    Route::post('/studio/upload-file',[StudioController::class,'uploadFile'])->name('studio.uploadFile');
    Route::post('/studio/update-draft/{id}',[StudioController::class,'updateDraft'])->name('studio.updateDraft');
    Route::post('/studio/publish/{id}',[StudioController::class,'publish'])->name('studio.publish');
    Route::delete('/studio/draft/{id}',[StudioController::class,'destroyDraft'])->name('studio.destroyDraft');
    Route::post('/studio/create-collection',[StudioController::class,'createCollection'])->name('studio.createCollection');
    Route::post('/studio/reorder-drafts',[StudioController::class,'reorderDrafts'])->name('studio.reorderDrafts');
    Route::get('/studio/search-tags',[StudioController::class,'searchTags'])->name('studio.searchTags');
    Route::get('/studio/search-collections',[StudioController::class,'searchCollections'])->name('studio.searchCollections');
    Route::get('/collections/media', [CollectionController::class, 'getCollectionsWithMedia'])->name('collections.media');

    Route::post('/artworks/{id}/like',[ArtworkController::class,'like'])->name('artworks.like');
    Route::post('/artworks/{id}/add-to-collection',[ArtworkController::class,'addToCollection'])->name('artworks.addToCollection');
    Route::post('/artworks/{id}/comments',[CommentController::class,'store'])->name('comments.store');
    Route::post('/comments/{id}/reply',[CommentController::class,'reply'])->name('comments.reply');

    Route::post('/collections',[CollectionController::class,'store'])->name('collections.store');
    Route::post('/collections/{id}',[CollectionController::class,'update'])->name('collections.update');
    Route::delete('/collections/{id}',[CollectionController::class,'destroy'])->name('collections.destroy');
});

Route::middleware('auth:sanctum')->get('/test', function () {
    return response()->json(['message' => 'Authenticated!']);
});
