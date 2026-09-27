<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to one or more account roles.
 *
 * The student/mobile endpoints sit behind `auth:sanctum`, which only proves the
 * caller is *some* authenticated account — a panel session or an admin token
 * would otherwise satisfy it. This gate keeps voting/ballot/candidacy flows
 * scoped to actual students.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}