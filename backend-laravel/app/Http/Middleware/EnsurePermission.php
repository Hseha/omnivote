<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $permissions = config("permissions.roles.{$user?->role}", []);
        $allowed = $permissions === '*' || in_array($permission, $permissions, true);

        if (! $user || ! $allowed) {
            return response()->json(['message' => 'You are not authorized to perform this action.'], 403);
        }

        return $next($request);
    }
}
