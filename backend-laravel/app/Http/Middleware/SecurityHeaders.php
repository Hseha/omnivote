<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers (security assessment L-6).
 *
 * The production vhost in deploy/nginx-omnivote.conf already sets the full
 * header set, but those headers exist only in nginx — a dev instance, a
 * `php artisan serve` run, a second proxy or a CDN edge all ship without them.
 * Setting them here makes the guarantee travel with the application.
 *
 * The policy is deliberately strict because this is a JSON API: `default-src
 * 'none'` blocks everything a browser could load from a response, and
 * `frame-ancestors`/`form-action` block clickjacking and form hijacking even if
 * an HTML error page is ever rendered. HSTS is only sent over HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // PHP emits X-Powered-By from the SAPI (expose_php=On), and nginx's
        // `server_tokens off` does not remove it. Strip it here so the header is
        // gone regardless of php.ini (L-6); deploy/README.md also documents
        // setting expose_php=Off in the FPM pool.
        header_remove('X-Powered-By');

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        // The React console is served from its own origin, so API responses can
        // stay locked down completely. Applied to API + CSRF endpoints only, so
        // any future server-rendered page is not accidentally blanked.
        if ($request->is('api/*') || $request->is('sanctum/*')) {
            $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
        }

        // Only claim HSTS on a connection that really was HTTPS, otherwise a
        // plain-HTTP dev host would pin the browser to a scheme it cannot serve.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            // Never fight the reverse proxy: if nginx already set it, keep its
            // value (it is the same policy, and nginx sees the real scheme).
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
