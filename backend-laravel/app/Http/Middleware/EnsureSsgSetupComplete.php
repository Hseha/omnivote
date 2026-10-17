<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the SSG President console until the one-time setup link has been
 * redeemed (username + password chosen, `ssg_setup_pending` cleared).
 *
 * A promoted winner keeps their existing student credential, so without this
 * gate they could sign into the console with the password they already had and
 * skip the credential handoff entirely. While pending, every console data route
 * answers 403 with `setup_required: true`, which the SPA turns into the setup
 * screen (reachable from the link in the President's notification feed).
 */
class EnsureSsgSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (bool) $user->ssg_setup_pending) {
            return response()->json([
                'message' => 'Finish setting up your SSG President account before using the console.',
                'setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
