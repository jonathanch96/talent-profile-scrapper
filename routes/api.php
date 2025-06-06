<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TalentController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Talent API routes - Protected with API token and rate limiting
Route::middleware(['api.token'])->group(function () {
    // Read operations - higher rate limits
    Route::middleware('throttle:talent-api')->group(function () {
        Route::get('talents', [TalentController::class, 'index']);
        Route::get('talents/{username}', [TalentController::class, 'show']);
    });

    // Write operations - stricter rate limits
    Route::middleware('throttle:talent-api-write')->group(function () {
        Route::put('talents/{username}', [TalentController::class, 'update']);
        Route::delete('talents/{username}', [TalentController::class, 'destroy']);
    });
});
