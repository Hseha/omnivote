<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Phase;

class CheckPhase
{
    public function handle(Request $request, Closure $next, string $requiredPhase = null)
    {
        $phase = Phase::current()?->name ?? 'registration';

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
