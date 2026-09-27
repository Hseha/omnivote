<?php

namespace App\Http\Middleware;

use App\Models\Phase;
use Closure;
use Illuminate\Http\Request;

class CheckPhase
{
    public function handle(Request $request, Closure $next, ?string $requiredPhase = null)
    {
        $phase = Phase::current()?->name;

        if ($requiredPhase && $phase !== $requiredPhase) {
            return response()->json([
                'success' => false,
                'message' => 'Action not allowed in current election phase',
                'phase' => $phase,
            ], 403);
        }

        return $next($request);
    }
}
