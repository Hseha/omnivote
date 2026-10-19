<?php

use App\Http\Controllers\AdminAuditLogController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminCandidateController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminStudentController;
use App\Http\Controllers\AdminTwoFactorController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BallotController;
use App\Http\Controllers\CandidacyAvailabilityController;
use App\Http\Controllers\CandidacyController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\ElectionController;
use App\Http\Controllers\ElectionRecordsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProblemReportController;
use App\Http\Controllers\RegistrarImportController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SsgConsoleAuthController;
use App\Http\Controllers\SsgOfficerController;
use App\Http\Controllers\SsgSetupController;
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
        // Live monitoring panels: hourly vote velocity (anonymous vote_ledger
        // aggregate) + latest operational / security audit stream. Same
        // permission gate as the overview it sits beside.
        Route::get('/dashboard/analytics', [AdminDashboardController::class, 'analytics'])
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
        // Delete a rejected candidate (trash-bin action on the React screen).
        Route::delete('/candidates/{candidate}', [AdminCandidateController::class, 'destroy'])
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
        // Comprehensive election reset: clears the schedule, deactivates the
        // phase, un-certifies candidates, clears votes and the vote ledger.
        // Guarded by `election.reset` (admin only) and requires an explicit
        // `confirm: "reset_election"` body field so it cannot be triggered by
        // a stray save click.
        Route::post('/election/reset', [ElectionController::class, 'reset'])
            ->middleware('permission:election.reset');

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
        // Election Records (full per-cycle roster: every candidate, winners
        // and non-winners, all parties — preserved across clean-slate resets).
        Route::get('/election-records', [ElectionRecordsController::class, 'index'])
            ->middleware('permission:results.view');

        // Configuration (React Settings screen)
        Route::get('/settings', [SettingsController::class, 'index'])
            ->middleware('permission:settings.view');
        Route::get('/settings/{section}', [SettingsController::class, 'show'])
            ->middleware('permission:settings.view');
        Route::patch('/settings/{section}', [SettingsController::class, 'update'])
            ->middleware('permission:settings.update');

        // Audit trail (React Audit Log screen). Admin only; the listing is a
        // read-only projection of `audit_logs` for sensitive admin actions and
        // sign-in events.
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
            ->middleware('permission:audit.view');

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
        // Manual single-student provisioning (User Management → "Add Student").
        // Separate controller so it can live beside the staff-creation flow
        // without widening AdminUserController's staff-only create endpoint.
        Route::post('/users/students', [AdminStudentController::class, 'store'])
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
        Route::post('/users/{user}/grant-ssg', [AdminUserController::class, 'grantSsg'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/{user}/archive', [AdminUserController::class, 'archive'])
            ->middleware('permission:manage_accounts');
        Route::post('/users/{user}/unarchive', [AdminUserController::class, 'unarchive'])
            ->middleware('permission:manage_accounts');
        // Permanent delete is declared after /users/courses so the literal
        // segment keeps winning over the {user} placeholder.
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])
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

// ------------------------------------------------------------------------
// SSG President web console (stateful, ungated by nginx)
//
// A dedicated /api/ssg/console surface for the /ssg/ mount of the same React
// bundle. nginx keeps the staff /admin/ and /api/admin/ surface behind its HTTP
// Basic pre-auth but leaves this prefix open, because the elected student
// President has no staff Basic credential. The gate that matters is server-side:
// only `ssg_president` may hold a session here (SsgConsoleAuthController
// narrows the panel role set), every data route keeps the permission middleware
// — and therefore the 2FA enrollment gate — and `ssg.setup` blocks the console
// until the one-time setup link has been redeemed.
// ------------------------------------------------------------------------
Route::prefix('ssg/console')->group(function () {
    // Public: redeem the one-time setup link. The single-use token IS the
    // credential (no session yet), so this is throttled like a password reset.
    Route::post('/setup', [SsgSetupController::class, 'setupConsole'])
        ->middleware('throttle:ssg-setup');

    Route::post('/login', [SsgConsoleAuthController::class, 'login']);
    Route::post('/login/2fa', [SsgConsoleAuthController::class, 'completeTwoFactorLogin']);

    Route::get('/me', [SsgConsoleAuthController::class, 'me'])->middleware('auth');
    Route::patch('/me/avatar', [SsgConsoleAuthController::class, 'updateAvatar'])->middleware('auth');
    Route::post('/logout', [SsgConsoleAuthController::class, 'logout'])->middleware('auth');

    // Self-service 2FA enrollment, identical to the staff console.
    Route::get('/2fa/status', [AdminTwoFactorController::class, 'status'])->middleware('auth');
    Route::post('/2fa/prepare', [AdminTwoFactorController::class, 'prepare'])
        ->middleware(['auth', 'throttle:sensitive']);
    Route::post('/2fa/confirm', [AdminTwoFactorController::class, 'confirm'])->middleware('auth');
    Route::post('/2fa/disable', [AdminTwoFactorController::class, 'disable'])->middleware('auth');

    Route::middleware(['auth', 'ssg.setup'])->group(function () {
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
    //
    // `ssg_president` is included alongside `student`: a promoted President
    // signs in through this same mobile login and carries a Sanctum bearer
    // token, so `/me` (cold-start restore), `/logout` and `/password/change`
    // (a promoted account may carry a registrar-issued temporary credential)
    // must resolve for that role too. Without this, a President's token is
    // discarded on every cold start and the app appears permanently signed out.
    Route::middleware(['auth:sanctum', 'role:student,candidate,ssg_president', 'passwordChanged'])->group(function () {
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
// The seated `ssg_president` keeps the full student experience (dashboard,
// candidacy status, results, notifications, profile), so it is included in the
// role gate alongside students and candidates. Voting/ballot routes below are
// additionally phase-gated (`checkPhase:voting_open`), so a President cannot
// cast a ballot once the polls have closed to seat them.
//
// `passwordChanged` blocks every flow below while the account still carries a
// registrar-issued temporary credential (security assessment C-1).
// ------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:student,candidate,ssg_president', 'passwordChanged'])->group(function () {
    // Dashboard card
    Route::get('/registration/me', [ElectionController::class, 'registrationMe']);

    // Candidacy application (registration phase only) + status
    Route::get('/candidacy/me', [CandidacyController::class, 'me']);
    // Per-party seat availability for the apply screen (see
    // docs/HANDOFF_CANDIDACY_SEAT_CAP.md §1.2).
    Route::get('/candidacy/availability', [CandidacyAvailabilityController::class, 'index']);

    // Student-submitted problem / bug report (e.g. from the candidacy apply
    // screen). Written to every active staff member's in-app notification
    // feed so the root cause can be investigated. Reuses user_notifications.
    Route::post('/reports', [ProblemReportController::class, 'store']);

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

    // Results (published after polls close AND after Finalize Winners raises
    // the results_published flag — students never see provisional tallies)
    // + anonymous receipt verification
    Route::get('/results', [ResultsController::class, 'index'])
        ->middleware(['checkPhase:voting_closed', 'resultsPublished']);
    // Receipt verification is an hash-comparison oracle, so it gets its own
    // ceiling (security assessment M-2). Keyed by the *account* rather than the
    // IP because a whole voting hall shares one source address, and a
    // per-IP limit there would punish ordinary voters for verifying receipts.
    Route::post('/results/verify', [ResultsController::class, 'verify'])
        ->middleware('throttle:receipt-verify');
});

// ------------------------------------------------------------------------
// In-app notification center (Flutter bell + badge)
//
// Shared by students and the SSG President: a promoted President must be able
// to read the one-time console-setup link delivered to their feed (see
// AdminUserController::grantSsg), and they have no mailbox to receive it any
// other way. Scoped to the caller; no permission middleware — every account
// sees only their own rows. The President keeps the full student feature set
// (see the student group above); the voting/ballot routes stay phase-gated so
// no one can cast a ballot once the polls have closed.
// ------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:student,candidate,ssg_president', 'passwordChanged'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'markRead']);
});

// ------------------------------------------------------------------------
// SSG President (Flutter client)
//
// The President signs in through the mobile login above and carries a Sanctum
// bearer token, so the panel's `/api/admin/ssg/officers` route (session-guarded
// by the `auth` web guard) is unreachable from the app. This mirrors that
// projection for the app.
//
// Guarded by `role:ssg_president` rather than `permission:officers.view`: the
// permission middleware carries the panel's two-factor enrollment gate, and the
// Flutter app has no 2FA enrollment path, so a permission-gated route would
// 403 a President who is legitimately signed in. Announcement writes stay
// panel-only; the app's announcements feed is the public `GET /announcements`.
// ------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:ssg_president', 'passwordChanged'])->group(function () {
    Route::get('/ssg/officers', [SsgOfficerController::class, 'index']);

    // Redeem the one-time setup link carried in the President's notification
    // feed and set their own password. Authenticated + token-bound (see
    // SsgSetupController); throttled because it sets a credential.
    Route::post('/ssg/setup-password', [SsgSetupController::class, 'setupPassword'])
        ->middleware('throttle:ssg-setup');
});
