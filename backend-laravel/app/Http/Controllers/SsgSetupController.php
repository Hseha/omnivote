<?php

namespace App\Http\Controllers;

use App\Models\SsgPresidentInvite;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Notifier;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Redeems a one-time SSG President setup link.
 *
 * When a certified winner is granted the `ssg_president` seat,
 * AdminUserController::grantSsg does NOT transmit a password. It mints a
 * one-time, expiring token (SsgPresidentInvite) and delivers it through the
 * President's in-app notification feed. This endpoint lets the authenticated
 * President exchange that token for a password of their own choosing — the
 * "secure link" half of the handoff.
 *
 * SECURITY:
 *   - The token is single-use and time-limited, so a leaked invite cannot be
 *     replayed (SsgPresidentInvite::findValid enforces both).
 *   - The route is authenticated AND bound to `role:ssg_president`, so a stolen
 *     token alone is useless without a live session on that same account.
 *   - The token must resolve to the caller's own account, so one President can
 *     never redeem another's invite.
 *   - Every other session is revoked after the change (mirrors the student
 *     change-password flow) so a token minted before the rotation cannot
 *     outlive it.
 */
class SsgSetupController extends Controller
{
    /**
     * POST /api/ssg/setup-password
     */
    public function setupPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            // Same self-messaging policy every other password-setting path uses,
            // so an admin-raised Security profile cannot be bypassed here.
            'password' => PasswordPolicy::confirmedRules(),
        ]);

        $user = $request->user();

        $invite = SsgPresidentInvite::findValid($validated['token']);

        // Invalid, already-used, or expired link — a single generic message so
        // the response is not an oracle for which of those it was.
        if (! $invite || $invite->user_id !== $user->id) {
            return response()->json([
                'message' => 'This setup link is invalid or has expired. Ask an administrator to send a new one.',
            ], 422);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'ssg_setup_pending' => false,
            // A promoted account may have arrived here carrying a registrar
            // temporary credential; setting their own password clears it.
            'must_change_password' => false,
        ])->save();

        // Keep the caller's current session so the app transitions straight into
        // the dashboard, but drop every other token (other devices / stale
        // sessions from before the promotion).
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current, fn ($query) => $query->where('id', '!=', $current->id))
            ->delete();

        $invite->redeem();

        AuditLog::record(
            $request,
            'ssg_password_set',
            "{$user->name} activated their SSG President account via a one-time setup link.",
            'user',
            $user->id,
            $user->id,
        );

        Notifier::toAdminUsersInApp(
            'success',
            'SSG President account activated',
            "{$user->name} set their SSG President password and can now access the governance dashboard.",
        );

        return response()->json([
            'message' => 'Password set. Your SSG President dashboard is now active.',
        ]);
    }

    /**
     * POST /api/ssg/console/setup
     *
     * Redeems the one-time setup link on the web console: the President picks
     * the username and password they will sign in with. Unauthenticated — the
     * single-use, expiring, high-entropy token IS the credential — which is why
     * it mirrors the password-reset flow rather than the session-gated
     * {@see setupPassword()} above.
     *
     * SECURITY: the token must resolve to a still-seated `ssg_president`; every
     * existing session/token is then revoked so the pre-handoff mobile token
     * cannot outlive the rotation; and the invite is burned so the link cannot
     * be replayed.
     */
    public function setupConsole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => PasswordPolicy::confirmedRules(),
        ]);

        $invite = SsgPresidentInvite::findValid($validated['token']);
        $user = $invite?->user;

        // Invalid/expired/used link, or the account is no longer the seated
        // President (a successor has taken the seat) — one generic answer so the
        // endpoint is not an oracle for which case it was.
        if (! $invite || ! $user || $user->role !== 'ssg_president') {
            return response()->json([
                'message' => 'This setup link is invalid or has expired. Ask an administrator to send a new one.',
            ], 422);
        }

        $username = mb_strtolower(trim($validated['username']));
        $this->assertUsernameAvailable($username, $user->id);

        $user->forceFill([
            'username' => $username,
            'password' => $validated['password'],
            'ssg_setup_pending' => false,
            // A promoted account may have arrived carrying a registrar temporary
            // credential; choosing a console password clears that gate too.
            'must_change_password' => false,
        ])->save();

        // No live session to preserve here, so every credential is revoked. The
        // mobile token minted during the promotion must be replaced by one
        // signed in with the newly chosen password.
        $user->tokens()->delete();

        $invite->redeem();

        AuditLog::record(
            $request,
            'ssg_console_setup',
            "{$user->name} activated their SSG President console account via a one-time setup link.",
            'user',
            $user->id,
            $user->id,
        );

        Notifier::toAdminUsersInApp(
            'success',
            'SSG President console activated',
            "{$user->name} set their SSG President username and password and can now sign in to the governance console.",
        );

        return response()->json([
            'message' => 'Your account is ready. Sign in with your new username and password.',
            'username' => $username,
        ]);
    }

    /**
     * Reject a username already used as anyone else's username or email
     * address. Excluding the caller's own row lets a President keep a handle
     * identical to their existing email without a false collision.
     */
    private function assertUsernameAvailable(string $username, int $exceptUserId): void
    {
        $taken = User::query()
            ->where('id', '!=', $exceptUserId)
            ->where(function ($query) use ($username): void {
                $query->where('username', $username)
                    ->orWhereRaw('LOWER(email) = ?', [$username]);
            })
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'username' => 'That username is already taken. Please choose another.',
            ]);
        }
    }
}
