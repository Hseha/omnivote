<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminCandidateController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\BallotController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CandidacyController;
use App\Http\Controllers\ElectionController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\RegistrarImportController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\StudentAuthController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Admin routes use stateful Sanctum sessions (cookie + CSRF, consumed by the
| React SPA). Student routes use Sanctum bearer tokens (consumed by Flutter).
| Phase gates: registration / voting_open / voting_closed.
|
*/

// ------------------------------------------------------------------------
// Admin SPA (stateful) routes
//
// NOTE: bootstrap/app.php calls $middleware->statefulApi(), which already
// prepends Sanctum's EnsureFrontendRequestsAreStateful (cookie decrypt +
// session start + CSRF validation) to every /api route for first-party
// origins. Adding 'web'/'ensureFrontendRequestsAreStateful' here as well
// would run the session middleware twice and split-brain the session
// cookie vs the CSRF cookie, so the group stays unwrapped.
// ------------------------------------------------------------------------

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::get('/me', [AdminAuthController::class, 'me'])->middleware('auth');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->middleware('auth');

    Route::middleware('auth')->group(function () {
        // Dashboard
        Route::get('/dashboard-overview', [AdminDashboardController::class, 'overview']);

        // Candidate review (React Candidates screen)
        Route::get('/candidates', [AdminCandidateController::class, 'index']);
        Route::patch('/candidates/{candidate}', [AdminCandidateController::class, 'update']);

        // Registrar CSV import (React Student Registry screen)
        Route::post('/registrar/import', [RegistrarImportController::class, 'import']);
        Route::get('/registrar/imports', [RegistrarImportController::class, 'index']);

        // Election configuration (React Election Setup screen)
        Route::get('/election/config', [ElectionController::class, 'config']);
        Route::put('/election/config', [ElectionController::class, 'updateConfig']);

        // Results (React Results screen)
        Route::get('/results', [AdminDashboardController::class, 'results']);
    });
});

// ------------------------------------------------------------------------
// Election lifecycle (public read used by BOTH clients)
// ------------------------------------------------------------------------
Route::get('/election/status', [ElectionController::class, 'status']);

// ------------------------------------------------------------------------
// Public reads: positions + approved candidates
// ------------------------------------------------------------------------
Route::get('/positions', [PositionController::class, 'index']);
Route::get('/candidates', [CandidateController::class, 'index']);
Route::get('/candidates/{candidate}', [CandidateController::class, 'show']);

// ------------------------------------------------------------------------
// Student / mobile token-based auth
// ------------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('/login', [StudentAuthController::class, 'login']);

    // Self-registration is only available during the registration phase.
    Route::post('/register', [RegistrationController::class, 'register'])
        ->middleware('checkPhase:registration');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [StudentAuthController::class, 'logout']);
        Route::get('/me', [StudentAuthController::class, 'me']);
    });
});

// ------------------------------------------------------------------------
// Student-authenticated flows (Flutter client)
// ------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    // Dashboard card
    Route::get('/registration/me', [ElectionController::class, 'registrationMe']);

    // Candidacy application (registration phase only) + status
    Route::get('/candidacy/me', [CandidacyController::class, 'me']);
    Route::post('/candidate/apply', [CandidateController::class, 'store'])
        ->middleware('checkPhase:registration');

    // Ballot draft + submission (voting_open only)
    Route::get('/ballot/me', [BallotController::class, 'me']);
    Route::put('/ballot/me', [BallotController::class, 'saveDraft'])
        ->middleware('checkPhase:voting_open');
    Route::post('/ballot/me/submit', [BallotController::class, 'submit'])
        ->middleware('checkPhase:voting_open');
    Route::post('/vote', [VoteController::class, 'submit'])
        ->middleware('checkPhase:voting_open');

    // Results (published after polls close) + anonymous receipt verification
    Route::get('/results', [ResultsController::class, 'index'])
        ->middleware('checkPhase:voting_closed');
    Route::post('/results/verify', [ResultsController::class, 'verify']);
});
