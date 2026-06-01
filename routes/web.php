<?php

use App\Http\Controllers\Admin\ArtworkController as AdminArtworkController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ArtworkController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Messenger\AttachmentController;
use App\Http\Controllers\Messenger\ConversationController;
use App\Http\Controllers\Messenger\GroupAvatarController;
use App\Http\Controllers\Messenger\MessageController;
use App\Http\Controllers\Messenger\ReactionController;
use App\Http\Controllers\Messenger\ReadMarkerController;
use App\Http\Controllers\Moderation\ComplaintController as ModerationComplaintController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\User\BlockController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/gallery', [GalleryController::class, 'index'])->name('gallery.index');
Route::get('/gallery/load-more', [GalleryController::class, 'loadMore'])->name('gallery.loadMore');
Route::get('/artworks/{artwork}', [ArtworkController::class, 'show'])->name('artworks.show');
Route::get('/artworks/{artwork}/comments', [CommentController::class, 'index'])->name('comments.index');
Route::get('/artworks/{artwork}/similar', [ArtworkController::class, 'similar'])->name('artworks.similar');
Route::get('/author/{user}/works', [ArtworkController::class, 'authorWorks'])->name('author.works');
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->name('search.suggestions');
Route::get('/collections/{collection}', [CollectionController::class, 'show'])->name('collections.show');
Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('user.profile.show');
Route::get('/profile/{user}/likes', [ProfileController::class, 'likes'])->name('user.profile.likes');
Route::get('/profile/{user}/collections', [ProfileController::class, 'collections'])->name('user.profile.collections');
Route::get('/terms', function () {
    return Inertia::render('Legal/TermsOfService');
})->name('terms');
Route::get('/privacy', function () {
    return Inertia::render('Legal/PrivacyPolicy');
})->name('privacy');


Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->group(function () {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread', [NotificationController::class, 'unread']);
        Route::post('/notifications/mark-read', [NotificationController::class, 'markAllRead']);
        Route::post(  '/notifications/{id}/mark-read',[NotificationController::class, 'markRead']);

        // Studio
        Route::prefix('studio')->name('studio.')->group(function () {
            Route::get('/', [StudioController::class, 'index'])->name('index');
            Route::get('/manager', [StudioController::class, 'manager'])->name('manager');
            Route::get('/collections', [StudioController::class, 'collectionsManager'])->name('collections');
            Route::post('/create-empty-draft', [StudioController::class, 'createEmptyDraft'])->name('createEmptyDraft');
            Route::post('/upload-file', [StudioController::class, 'uploadFile'])->name('uploadFile');
            Route::post('/update-draft/{id}', [StudioController::class, 'updateDraft'])->name('updateDraft');
            Route::post('/publish/{id}', [StudioController::class, 'publish'])->name('publish');
            Route::patch('/artworks/{artwork}', [StudioController::class, 'updateArtwork'])->name('artworks.update');
            Route::delete('/draft/{id}', [StudioController::class, 'destroyDraft'])->name('destroyDraft');
            Route::delete('/artworks/{artwork}', [StudioController::class, 'destroyArtwork'])->name('artworks.destroy');
            Route::post('/reorder-drafts', [StudioController::class, 'reorderDrafts'])->name('reorderDrafts');
            Route::get('/search-tags', [StudioController::class, 'searchTags'])->name('searchTags');
            Route::get('/search-collections', [StudioController::class, 'searchCollections'])->name('searchCollections');
        });

        // Artwork interactions
        Route::prefix('artworks')->name('artworks.')->group(function () {
            Route::post('{artwork}/like', [ArtworkController::class, 'like'])->name('like');
            Route::post('{artwork}/add-to-collection', [ArtworkController::class, 'addToCollection'])->name('addToCollection');
            Route::post('{artwork}/comments', [CommentController::class, 'store'])->name('comments.store');
            Route::post('comments/{comment}/reply', [CommentController::class, 'reply'])->name('comments.reply');
        });

        // Complaints
        Route::prefix('complaints')->group(function () {
            Route::post('artwork/{artwork}', [ComplaintController::class, 'storeArtwork'])->name('complaints.artworks.store');
            Route::post('comment/{comment}', [ComplaintController::class, 'storeComment'])->name('complaints.comments.store');
            Route::post('profile/{user}', [ComplaintController::class, 'storeProfile'])->name('complaints.users.store');
        });

        // Collections CRUD
        Route::prefix('collections')->name('collections.')->group(function () {
            Route::get('media', [CollectionController::class, 'getCollectionsWithMedia'])->name('media');
            Route::post('create', [CollectionController::class, 'store'])->name('store');
            Route::post('{collection}', [CollectionController::class, 'update'])->name('update');
            Route::delete('{collection}', [CollectionController::class, 'destroy'])->name('destroy');
        });

        // User search & block/unblock
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('search', [UserController::class, 'search'])->name('search');
            Route::post('{user}/block', [BlockController::class, 'block'])->name('block');
            Route::post('{user}/unblock', [BlockController::class, 'unblock'])->name('unblock');
        });

        // Messenger
        Route::prefix('messenger')->name('messenger.')->group(function () {
            Route::get('{conversation?}', [ConversationController::class, 'index'])->name('index');
            Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
            Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
            Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
            Route::patch('conversations/{conversation}/read', [ReadMarkerController::class, 'update'])->name('conversations.read');

            Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->name('messages.index');
            Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->name('messages.store');

            Route::post('messages/{message}/reaction', [ReactionController::class, 'toggle'])->name('messages.reaction');
            Route::post('attachments', [AttachmentController::class, 'store'])->name('attachments.store');
            Route::patch('conversations/{conversation}/update', [ConversationController::class, 'update'])->name('conversations.update');
            Route::patch('conversations/{conversation}/avatar', [GroupAvatarController::class, 'update'])->name('conversations.avatar.update');
            Route::post('conversations/{conversation}/add-user', [ConversationController::class, 'addUser'])->name('conversations.addUser');
            Route::post('conversations/{conversation}/remove-user', [ConversationController::class, 'removeUser'])->name('conversations.removeUser');
            Route::post('conversations/{conversation}/leave', [ConversationController::class, 'leaveGroup'])->name('conversations.leave');
        });

        // Admin only
        Route::middleware('role:Admin|SuperAdmin')
            ->prefix('admin')->name('admin.')->group(function () {
                Route::get('users', [AdminUserController::class, 'index'])->name('users');
                Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
                Route::post('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
                Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
                Route::get('artworks', [AdminArtworkController::class, 'index'])->name('artworks.manager');
            });

        Route::middleware(['role:Moderator|Admin|SuperAdmin'])
            ->prefix('moderation')
            ->name('moderation.')
            ->group(function () {
                Route::get('complaints', [ModerationComplaintController::class, 'index'])->name('complaints.index');
                Route::get('complaints/{complaint}', [ModerationComplaintController::class, 'show'])->name('complaints.show');
                Route::post('complaints/{complaint}/review', [ModerationComplaintController::class, 'review'])->name('complaints.review');
            });
    });

Route::middleware(['web', 'auth:web', config('jetstream.auth_session')])
    ->get('/user/profile', [UserProfileController::class, 'show'])
    ->name('profile.show');
