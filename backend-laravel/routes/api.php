<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminCandidateController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminTwoFactorController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BallotController;
use App\Http\Controllers\CandidacyController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\ElectionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\RegistrarImportController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SsgOfficerController;
use App\Http\Controllers\StudentAuthController;
use App\Http\Controllers\VoteController;
use App\Http\Middleware\EnsurePasswordChanged;
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
    Route::post('/login/2fa', [AdminAuthController::class, 'completeTwoFactorLogin']);

    // Forgot-password flow (public: the caller has no session yet).
    //
    // Throttled (security assessment M-2/L-9): the in-controller throttle in
    // sendPasswordResetLink keys on the *email*, so one source address could
    // still spray a different address per request — and the reset handler
    // reached Password::reset() with no rate limit at all, i.e. an unauthenticated
    // token-guessing surface. Both now sit behind a per-IP ceiling; the mail
    // endpoint additionally keeps its per-address limit because sending mail costs.
    Route::post('/password/email', [AdminAuthController::class, 'sendPasswordResetLink'])
        ->middleware('throttle:password-reset');
    Route::post('/password/reset', [AdminAuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset');

    Route::get('/me', [AdminAuthController::class, 'me'])->middleware('auth');
    Route::patch('/me/avatar', [AdminAuthController::class, 'updateAvatar'])->middleware('auth');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->middleware('auth');

    // Self-service 2FA enrollment (auth-only so staff blocked from permission
    // routes by the enforcement gate can still set their account up).
    Route::get('/2fa/status', [AdminTwoFactorController::class, 'status'])->middleware('auth');
    // `prepare` mints a fresh secret + provisioning URI and costs two DB writes,
    // and nothing limited it (security assessment M-2). Capping it at 5/min also
    // stops it being used to invalidate an in-progress enrolment repeatedly.
    Route::post('/2fa/prepare', [AdminTwoFactorController::class, 'prepare'])
        ->middleware(['auth', 'throttle:sensitive']);
    Route::post('/2fa/confirm', [AdminTwoFactorController::class, 'confirm'])->middleware('auth');
    Route::post('/2fa/disable', [AdminTwoFactorController::class, 'disable'])->middleware('auth');

    Route::middleware('auth')->group(function () {
        // Dashboard
        Route::get('/dashboard-overview', [AdminDashboardController::class, 'overview'])
            ->middleware('permission:dashboard.view');

        // Candidate review (React Candidates screen)
        Route::get('/candidates', [AdminCandidateController::class, 'index'])
            ->middleware('permission:candidates.view');
        Route::post('/candidates', [AdminCandidateController::class, 'store'])
            ->middleware('permission:candidates.review');
        Route::post('/parties', [AdminCandidateController::class, 'storeParty'])
            ->middleware('permission:candidates.review');
        Route::patch('/candidates/{candidate}', [AdminCandidateController::class, 'update'])
            ->middleware('permission:candidates.review');

        // Registrar CSV import (React Student Registry screen)
        Route::post('/registrar/import', [RegistrarImportController::class, 'import'])
            ->middleware('permission:registrar.import');
        Route::get('/registrar/imports', [RegistrarImportController::class, 'index'])
            ->middleware('permission:registrar.import');
        // Registrar-issued activation / account-recovery codes (M-3, M-5).
        // Declared before the `{import}` wildcard paths so "issue-codes" is not
        // swallowed as an id.
        Route::post('/registrar/imports/issue-codes', [RegistrarImportController::class, 'issueCodes'])
            ->middleware('permission:registrar.import');
        Route::post('/registrar/imports/{import}/issue-code', [RegistrarImportController::class, 'issueCode'])
            ->middleware('permission:registrar.import');

        // Election configuration (React Election Setup screen)
        Route::get('/election/config', [ElectionController::class, 'config'])
            ->middleware('permission:election.view_config');
        Route::put('/election/config', [ElectionController::class, 'updateConfig'])
            ->middleware('permission:election.update_config');

        // Ballot position editing (React Election Setup screen)
        Route::patch('/positions/{position}', [PositionController::class, 'update'])
            ->middleware('permission:election.update_config');

        // Results (React Results screen)
        Route::get('/results', [AdminDashboardController::class, 'results'])
            ->middleware('permission:results.view');
        Route::post('/results/finalize', [ResultsController::class, 'finalize'])
            ->middleware('permission:results.finalize');
        Route::get('/results/archive', [ResultsController::class, 'archivedWinners'])
            ->middleware('permission:results.view');
        Route::post('/results/archive-term', [ResultsController::class, 'archiveTerm'])
            ->middleware('permission:results.finalize');

        // Configuration (React Settings screen)
        Route::get('/settings', [SettingsController::class, 'index'])
            ->middleware('permission:settings.view');
        Route::get('/settings/{section}', [SettingsController::class, 'show'])
            ->middleware('permission:settings.view');
        Route::patch('/settings/{section}', [SettingsController::class, 'update'])
            ->middleware('permission:settings.update');

        // Backup & restore (React Settings → Backup & Restore)
        Route::get('/backups', [BackupController::class, 'index'])
            ->middleware('permission:settings.view');
        Route::post('/backups', [BackupController::class, 'store'])
            ->middleware('permission:settings.update');
        Route::post('/backups/restore', [BackupController::class, 'restore'])
            ->middleware('permission:settings.update');
        Route::get('/backups/{file}/download', [BackupController::class, 'download'])
            ->middleware('permission:settings.view');
        Route::delete('/backups/{file}', [BackupController::class, 'destroy'])
            ->middleware('permission:settings.update');

        // In-app notification center (React shell bell + dashboard feed)
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->middleware('permission:dashboard.view');
        Route::post('/notifications/read', [NotificationController::class, 'markRead'])
            ->middleware('permission:dashboard.view');
        // Broadcast composer (Settings → Notifications). Reuses the
        // announcements permission set — writing a school-wide notice is the
        // same level of trust as authoring an announcement.
        Route::post('/notifications/broadcast', [NotificationController::class, 'broadcast'])
            ->middleware('permission:announcements.create');

        // User access management (React User Management screen)
        Route::get('/users', [AdminUserController::class, 'index'])
            ->middleware('permission:manage_accounts');
        Route::get('/users/export', [AdminUserController::class, 'export'])
            ->middleware('permission:manage_accounts');
        Route::get('/users/certified-winners', [AdminUserController::class, 'certifiedWinners'])
            ->middleware('permission:manage_accounts');
        Route::post('/users', [AdminUserController::class, 'store'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/departments', [AdminUserController::class, 'storeDepartment'])
            ->middleware('permission:manage_accounts');
        Route::patch('/users/departments', [AdminUserController::class, 'renameDepartment'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/courses', [AdminUserController::class, 'storeCourse'])
            ->middleware('permission:manage_accounts');
        Route::patch('/users/courses', [AdminUserController::class, 'renameCourse'])
            ->middleware('permission:manage_accounts');
        Route::delete('/users/courses', [AdminUserController::class, 'deleteCourse'])
            ->middleware('permission:manage_accounts');
        Route::patch('/users/{user}/role', [AdminUserController::class, 'updateRole'])
            ->middleware('permission:manage_accounts');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])
            ->middleware('permission:manage_accounts');
        Route::patch('/users/{user}/email', [AdminUserController::class, 'updateEmail'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/{user}/password-reset', [AdminUserController::class, 'resetPassword'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/{user}/unlock', [AdminUserController::class, 'unlock'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/bulk-unlock', [AdminUserController::class, 'bulkUnlock'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/{user}/archive', [AdminUserController::class, 'archive'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/{user}/unarchive', [AdminUserController::class, 'unarchive'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/{user}/grant-ssg', [AdminUserController::class, 'grantSsg'])
            ->middleware('permission:manage_accounts');

        // Certified-winner ratification (drives the SSG President grant flow).
        Route::post('/candidates/{candidate}/certify', [AdminCandidateController::class, 'certify'])
            ->middleware('permission:election.update_config');
        Route::delete('/candidates/{candidate}/certify', [AdminCandidateController::class, 'revokeCertification'])
            ->middleware('permission:election.update_config');
        Route::post('/candidates/{candidate}/resolve-tie', [AdminCandidateController::class, 'resolveTie'])
            ->middleware('permission:election.update_config');

        Route::get('/ssg/officers', [SsgOfficerController::class, 'index'])
            ->middleware('permission:officers.view');
        Route::get('/ssg/announcements', [AnnouncementController::class, 'index'])
            ->middleware('permission:announcements.view');
        Route::post('/ssg/announcements', [AnnouncementController::class, 'store'])
            ->middleware('permission:announcements.create');
        Route::put('/ssg/announcements/{announcement}', [AnnouncementController::class, 'update'])
            ->middleware('permission:announcements.edit');
        Route::delete('/ssg/announcements/{announcement}', [AnnouncementController::class, 'destroy'])
            ->middleware('permission:announcements.edit');
    });
});

Route::middleware('throttle:api-public')->get('/announcements', [AnnouncementController::class, 'publicIndex']);

// ------------------------------------------------------------------------
// Public branding (login screen + global chrome render before auth, so the
// shell fetches site name / colors / favicon from here rather than the
// permission-gated /admin/settings endpoints).
// ------------------------------------------------------------------------
Route::middleware('throttle:api-public')->get('/branding', [SettingsController::class, 'branding']);

// ------------------------------------------------------------------------
// Election lifecycle (public read used by BOTH clients)
// ------------------------------------------------------------------------
Route::middleware('throttle:api-public')->get('/election/status', [ElectionController::class, 'status']);

// ------------------------------------------------------------------------
// Public reads: positions + approved candidates
//
// Throttled (security assessment M-2): these were the only unauthenticated
// routes with no ceiling at all, so they doubled as an unauthenticated DB-flooding
// surface. `/announcements`, `/branding`, `/election/status` and the results
// group below carry the same `api-public` bucket.
// ------------------------------------------------------------------------
Route::middleware('throttle:api-public')->group(function () {
    Route::get('/positions', [PositionController::class, 'index']);
    Route::get('/candidates', [CandidateController::class, 'index']);
    Route::get('/candidates/{candidate}', [CandidateController::class, 'show']);
    Route::get('/departments', [CandidateController::class, 'departments']);
    Route::get('/parties', [CandidateController::class, 'parties']);
});

// ------------------------------------------------------------------------
// Student / mobile token-based auth
// ------------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('/login', [StudentAuthController::class, 'login']);

    // Self-registration is only available during the registration phase.
    Route::post('/register', [RegistrationController::class, 'register'])
        ->middleware('checkPhase:registration');

    // Student self-service account recovery (security assessment M-3).
    //
    // Students have no mailbox — their handle is a name-derived slug — so a
    // broker reset link has nowhere to go. This redeems the registrar-issued
    // activation code instead, which is what lets a locked-out voter recover
    // without an administrator touching the account. Throttled per IP because
    // the code space, while large, is finite.
    Route::post('/password/reset-with-code', [StudentAuthController::class, 'resetWithCode'])
        ->middleware('throttle:password-reset');

    // The temporary-credential gate is on by default: an account flagged
    // `must_change_password` can only reach the three routes needed to clear
    // the flag (security assessment C-1). Those opt out explicitly.
    Route::middleware(['auth:sanctum', 'role:student', 'passwordChanged'])->group(function () {
        Route::post('/logout', [StudentAuthController::class, 'logout'])
            ->withoutMiddleware(EnsurePasswordChanged::class);
        Route::get('/me', [StudentAuthController::class, 'me'])
            ->withoutMiddleware(EnsurePasswordChanged::class);
        Route::post('/password/change', [StudentAuthController::class, 'changePassword'])
            ->withoutMiddleware(EnsurePasswordChanged::class);
    });
});

// ------------------------------------------------------------------------
// Student-authenticated flows (Flutter client)
//
// `passwordChanged` blocks every flow below while the account still carries a
// registrar-issued temporary credential (security assessment C-1).
// ------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:student', 'passwordChanged'])->group(function () {
    // Dashboard card
    Route::get('/registration/me', [ElectionController::class, 'registrationMe']);

    // In-app notification center (Flutter bell + badge). Scoped to the caller;
    // no permission middleware — every student sees only their own rows.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'markRead']);

    // Candidacy application (registration phase only) + status
    Route::get('/candidacy/me', [CandidacyController::class, 'me']);
    Route::post('/candidate/apply', [CandidateController::class, 'store'])
        ->middleware('checkPhase:registration');

    // Editing the campaign and withdrawing/forfeiting a candidacy are allowed
    // through voting (until polls close) so an approved candidate can still
    // change their mind. The phase gate for these lives in the controller:
    // `registration` and `voting_open` only.
    Route::put('/candidate/apply', [CandidateController::class, 'update']);
    Route::post('/candidate/withdraw', [CandidateController::class, 'withdraw']);

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
    // Receipt verification is an hash-comparison oracle, so it gets its own
    // ceiling (security assessment M-2). Keyed by the *account* rather than the
    // IP because a whole voting hall shares one source address, and a
    // per-IP limit there would punish ordinary voters for verifying receipts.
    Route::post('/results/verify', [ResultsController::class, 'verify'])
        ->middleware('throttle:receipt-verify');
});
