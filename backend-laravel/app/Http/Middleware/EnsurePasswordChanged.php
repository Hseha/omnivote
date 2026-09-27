<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of `must_change_password` (security assessment C-1).
 *
 * Registrar-provisioned accounts are created with a one-time temporary
 * credential and flagged `must_change_password`. Until the student replaces it,
 * the account must not be usable — otherwise an attacker who obtained the
 * credential (or a leaked initial value) holds a fully privileged token and can
 * cast a ballot.
 *
 * The Flutter client already routes flagged accounts to the change-password
 * screen, but a client-side check is not a control: any HTTP client skips it.
 * This middleware is the actual gate.
 *
 * Applied to the student route group; the routes needed to *clear* the flag
 * opt out explicitly with `->withoutMiddleware(EnsurePasswordChanged::class)`
 * (auth/me, auth/logout, auth/password/change), so every new student route is
 * blocked by default until someone deliberately exempts it.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (bool) $user->must_change_password) {
            return response()->json([
                'message' => 'You must set a new password before using the app.',
                // Machine-readable so the client can route straight to the
                // change-password screen instead of showing a generic error.
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
