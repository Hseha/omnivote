<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\StudentAuthController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\CandidateController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VoteController;

// Admin SPA (stateful) routes: attach the web session middleware and ensure middleware
Route::prefix('admin')->middleware(['ensureFrontendRequestsAreStateful', 'web'])->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::get('/me', [AdminAuthController::class, 'me'])->middleware('auth');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->middleware('auth');
});

// Student / mobile token-based auth
Route::prefix('auth')->group(function () {
    Route::post('/login', [StudentAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [StudentAuthController::class, 'logout']);
        Route::get('/me', [StudentAuthController::class, 'me']);
    });

    Route::post('/register', [RegistrationController::class, 'register']);
});

// Candidate endpoints (scaffold)
Route::apiResource('candidates', CandidateController::class);

// Candidate application endpoint (registration phase only)
Route::post('/candidate/apply', [CandidateController::class, 'store'])->middleware('auth:sanctum', 'checkPhase:registration');

// Vote submission (voting_open phase only)
Route::post('/vote', [VoteController::class, 'submit'])->middleware('auth:sanctum', 'checkPhase:voting_open');
