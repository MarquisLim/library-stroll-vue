<?php

use App\Http\Controllers\ArtworkController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\StudioController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
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

    Route::get('/gallery',[GalleryController::class,'index'])->name('gallery.index');

    Route::get('/artworks/{id}', [ArtworkController::class,'show'])->name('artworks.show');

// Маршрут для добавления в коллекцию
    Route::post('/artworks/{id}/add-to-collection',[ArtworkController::class,'addToCollection'])->name('artworks.addToCollection');

// Лайк работы
    Route::post('/artworks/{id}/like',[ArtworkController::class,'like'])->name('artworks.like');

// Комментарии
    Route::post('/artworks/{id}/comments',[CommentController::class,'store'])->name('comments.store');
    Route::post('/comments/{id}/reply',[CommentController::class,'reply'])->name('comments.reply');

// Маршрут для Infinite scroll галереи (подгрузка)
    Route::get('/gallery/load-more',[GalleryController::class,'loadMore'])->name('gallery.loadMore');
//    // Галерея
//    Route::get('/gallery',[GalleryController::class,'index'])->name('gallery.index');
//    Route::get('/artworks/{id}',[ArtworkController::class,'show'])->name('artworks.show');
//
//    // Профиль
//    Route::get('/profile/{user}',[ProfileController::class,'show'])->name('profile.show');
//    Route::post('/profile/{user}/update',[ProfileController::class,'update'])->name('profile.update');
//
//    // Коллекции
    Route::post('/collections',[CollectionController::class,'store'])->name('collections.store');
    Route::post('/collections/{id}',[CollectionController::class,'update'])->name('collections.update');
    Route::delete('/collections/{id}',[CollectionController::class,'destroy'])->name('collections.destroy');
//
//    // Теги (поиск, автодополнение)
//    Route::get('/tags/search',[TagController::class,'search'])->name('tags.search');
//
//    // Комментарии
//    Route::post('/artworks/{artwork}/comments',[CommentController::class,'store'])->name('comments.store');
//    Route::delete('/comments/{id}',[CommentController::class,'destroy'])->name('comments.destroy');
//
//    // Предпочтения
//    Route::post('/preferences',[PreferenceController::class,'store'])->name('preferences.store');
//    Route::delete('/preferences/{id}',[PreferenceController::class,'destroy'])->name('preferences.destroy');
});
