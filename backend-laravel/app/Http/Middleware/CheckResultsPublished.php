<?php

namespace App\Http\Middleware;

use App\Support\SsgPresidentGrant;
use Closure;
use Illuminate\Http\Request;

/**
 * Keeps raw tallies off the student app until Finalize Winners runs.
 *
 * The panel reads results through /api/admin/results, which never passes
 * through this gate; only the student route (GET /api/results) is wrapped.
 * The flag itself is raised by ResultsPublisher::publish() during finalize
 * and cleared on an election reset, so visibility follows the same lever
 * the admin already uses to certify winners.
 */
class CheckResultsPublished
{
    public function handle(Request $request, Closure $next)
    {
        if (! SsgPresidentGrant::resultsPublished()) {
            return response()->json([
                'success' => false,
                'code' => 'results_not_published',
                'message' => 'Election results have not been published yet.',
            ], 403);
        }

        return $next($request);
    }
}
