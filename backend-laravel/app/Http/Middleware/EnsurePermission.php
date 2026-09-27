<?php

namespace App\Http\Middleware;

use App\Support\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // A disabled account must be locked out of every panel route, not just
        // /me — updateStatus() revokes bearer tokens, but the stateful web
        // session cookie survives, so otherwise a disabled admin keeps the
        // panel open until the cookie expires.
        if ($user && ! $user->is_active) {
            return response()->json(['message' => 'Your account has been disabled.'], 403);
        }

        // Since the "2FA Required" toggle landed in Settings → Security, any
        // panel staff who are not yet enrolled get escorted to the enforcement
        // screen instead of gaining access to every permission-gated route.
        if ($user && in_array($user?->role, config('permissions.panel_roles', []), true)
            && $this->twoFactorEnforced() && ! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication is required before you can continue.',
                'two_factor_required' => true,
            ], 403);
        }

        $permissions = config("permissions.roles.{$user?->role}", []);
        $allowed = in_array($user?->role, config('permissions.panel_roles', []), true)
            && in_array($permission, $permissions, true);

        if (! $user || ! $allowed) {
            return response()->json(['message' => 'You are not authorized to perform this action.'], 403);
        }

        return $next($request);
    }

    private function twoFactorEnforced(): bool
    {
        // Secure-by-default: panel staff must enroll in 2FA unless an admin
        // explicitly turns the requirement off in Settings → Security.
        return (bool) AppSettings::security('twoFactorRequired', true);
    }
}
