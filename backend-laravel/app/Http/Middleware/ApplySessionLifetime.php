<?php

namespace App\Http\Middleware;

use App\Support\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the Settings → Security "Session Timeout" value to the real session
 * lifetime on the fly.
 *
 * session.lifetime is read by the StartSession middleware when the request
 * begins, so this middleware runs globally (prepended ahead of Sanctum's
 * stateful handling) and writes the configured timeout into runtime config.
 * Stateless traffic (public reads, student bearer tokens) carries no session
 * cookie and is skipped entirely, so it never pays for a settings lookup.
 */
class ApplySessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = config('session.cookie');
        if ($cookie && ! $request->cookies->has($cookie)) {
            return $next($request);
        }

        $minutes = AppSettings::security('sessionTimeout', 30);

        // Clamp to the same bounds the Settings screen enforces (5–480).
        config(['session.lifetime' => max(5, min(480, (int) $minutes))]);

        return $next($request);
    }
}
