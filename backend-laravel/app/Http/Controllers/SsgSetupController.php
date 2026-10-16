<?php

namespace App\Http\Controllers;

use App\Models\SsgPresidentInvite;
use App\Support\AuditLog;
use App\Support\Notifier;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
