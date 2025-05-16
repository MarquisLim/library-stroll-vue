<?php

use App\Http\Controllers\ArtworkController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReadMarkerController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\User\BlockController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public — без авторизации
|--------------------------------------------------------------------------
*/
Route::get('/',                            [HomeController::class,       'index'])->name('home');
Route::get('/gallery',                         [GalleryController::class,    'index'])->name('gallery.index');
Route::get('/gallery/load-more',               [GalleryController::class,    'loadMore'])->name('gallery.loadMore');
Route::get('/artworks/{artwork}',              [ArtworkController::class,    'show'])->name('artworks.show');
Route::get('/artworks/{artwork}/comments',     [CommentController::class,    'index'])->name('comments.index');
Route::get('/artworks/{artwork}/similar',      [ArtworkController::class,    'similar'])->name('artworks.similar');
Route::get('/author/{user}/works',             [ArtworkController::class,    'authorWorks'])->name('author.works');
Route::get('/search',                          [SearchController::class,     'index'])->name('search.index');
Route::get('/search/suggestions',              [SearchController::class,     'suggestions'])->name('search.suggestions');
Route::get('/collections/{collection}',        [CollectionController::class, 'show'])->name('collections.show');
Route::get('/profile/{user}',                  [ProfileController::class,    'show'])->name('user.profile.show');
Route::get('/profile/{user}/likes',            [ProfileController::class,    'likes'])->name('user.profile.likes');
Route::get('/profile/{user}/collections',      [ProfileController::class,    'collections'])->name('user.profile.collections');
Route::get('/terms', function () {
    return Inertia::render('Legal/TermsOfService');})->name('terms');
Route::get('/privacy', function () {
    return Inertia::render('Legal/PrivacyPolicy');})->name('privacy');

/*
|--------------------------------------------------------------------------
| Authenticated & Verified (Jetstream + Sanctum + email verified)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->group(function () {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Studio: черновики, загрузки, публикация…
        Route::prefix('studio')->name('studio.')->group(function(){
            Route::get('/',                     [StudioController::class, 'index'])->name('index');
            Route::get('/manager',              [StudioController::class, 'manager'])->name('manager');
            Route::get('/collections',          [StudioController::class, 'collectionsManager'])->name('collections');
            Route::post('/create-empty-draft',  [StudioController::class, 'createEmptyDraft'])->name('createEmptyDraft');
            Route::post('/upload-file',         [StudioController::class, 'uploadFile'])->name('uploadFile');
            Route::post('/update-draft/{id}',   [StudioController::class, 'updateDraft'])->name('updateDraft');
            Route::post('/publish/{id}',        [StudioController::class, 'publish'])->name('publish');
            Route::patch('/artworks/{artwork}', [StudioController::class, 'updateArtwork'])->name('artworks.update');
            Route::delete('/draft/{id}',        [StudioController::class, 'destroyDraft'])->name('destroyDraft');
            Route::delete('/artworks/{artwork}',[StudioController::class, 'destroyArtwork'])->name('artworks.destroy');
            Route::post('/reorder-drafts',      [StudioController::class, 'reorderDrafts'])->name('reorderDrafts');
            Route::get('/search-tags',          [StudioController::class, 'searchTags'])->name('searchTags');
            Route::get('/search-collections',   [StudioController::class, 'searchCollections'])->name('searchCollections');
        });

        // Artwork interactions
        Route::prefix('artworks')->name('artworks.')->group(function(){
            Route::post('{artwork}/like',              [ArtworkController::class, 'like'])->name('like');
            Route::post('{artwork}/add-to-collection', [ArtworkController::class, 'addToCollection'])->name('addToCollection');
            Route::post('{artwork}/comments',          [CommentController::class, 'store'])->name('comments.store');
            Route::get('{artwork}/comments',           [CommentController::class, 'index'])->name('comments.index');
            Route::post('comments/{comment}/reply',    [CommentController::class, 'reply'])->name('comments.reply');
            Route::get('{artwork}/similar',            [ArtworkController::class, 'similar'])->name('similar');
        });

        // Collections CRUD
        Route::prefix('collections')->name('collections.')->group(function(){
            Route::get('media',    [CollectionController::class, 'getCollectionsWithMedia'])->name('media');
            Route::post('/',        [CollectionController::class, 'store'])->name('store');
            Route::post('{collection}', [CollectionController::class, 'update'])->name('update');
            Route::delete('{collection}', [CollectionController::class, 'destroy'])->name('destroy');
        });

        // User search & block/unblock
        Route::prefix('users')->name('users.')->group(function(){
            Route::get('search',         [UserController::class,  'search'])->name('search');
            Route::post('{user}/block',   [BlockController::class, 'block'])->name('block');
            Route::post('{user}/unblock', [BlockController::class, 'unblock'])->name('unblock');
        });

        // Messenger & Chat
        Route::prefix('messenger')->name('messenger.')->group(function(){
            Route::get('{conversation?}',                [ConversationController::class, 'index'])->name('index');
            Route::post('conversations',                 [ConversationController::class, 'store'])->name('conversations.store');
            Route::get('conversations/{conversation}',   [ConversationController::class, 'show'])->name('conversations.show');
            Route::delete('conversations/{conversation}',[ConversationController::class, 'destroy'])->name('conversations.destroy');
            Route::patch('conversations/{conversation}/read',[ReadMarkerController::class,'update'])->name('conversations.read');

            Route::get('conversations/{conversation}/messages',[MessageController::class,'index'])->name('messages.index');
            Route::post('conversations/{conversation}/messages',[MessageController::class,'store'])->name('messages.store');

            Route::post('messages/{message}/reaction',      [ReactionController::class, 'toggle'])->name('messages.reaction');
            Route::post('attachments',                      [AttachmentController::class,'store'])->name('attachments.store');
        });

        // Admin only
        Route::middleware('role:Admin|SuperAdmin')
            ->prefix('admin')->name('admin.')->group(function(){
                Route::get('users',         [\App\Http\Controllers\Admin\UserController::class,'index'])->name('users');
                Route::post('users',        [\App\Http\Controllers\Admin\UserController::class,'store'])->name('users.store');
                Route::post('users/{user}', [\App\Http\Controllers\Admin\UserController::class,'update'])->name('users.update');
                Route::delete('users/{user}',[\App\Http\Controllers\Admin\UserController::class,'destroy'])->name('users.destroy');
            });
    });
