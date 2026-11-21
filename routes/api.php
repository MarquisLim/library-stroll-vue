<?php

use App\Http\Controllers\Internal\ArtworkVisibilityController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/internal/ai/artwork-visibility', ArtworkVisibilityController::class);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
