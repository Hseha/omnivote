<?php

use App\Http\Middleware\ApplySessionLifetime;
use App\Http\Middleware\CheckPhase;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use App\Support\TrustedHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum's stateful API middleware and register aliases.
        // Sanctum's EnsureFrontendRequestsAreStateful automatically adds
        // session/CSRF middleware for requests from configured stateful domains.
        $middleware->statefulApi()
            ->alias([
                'checkPhase' => CheckPhase::class,
                'permission' => EnsurePermission::class,
                'role' => EnsureRole::class,
                'passwordChanged' => EnsurePasswordChanged::class,
            ])
            // Runs before StartSession/Sanctum is set up so the configured
            // Security → Session Timeout lands in runtime session.lifetime.
            ->prepend(ApplySessionLifetime::class)
            // Security response headers (CSP on API responses, nosniff, HSTS,
            // referrer/frame/permissions policy) + X-Powered-By removal, so a
            // deployment that is not fronted by deploy/nginx-omnivote.conf is
            // still hardened (security assessment L-6).
            ->prepend(SecurityHeaders::class)
            // The app is served behind a local reverse proxy (nginx/php-fpm in
            // deploy/), so trusting only loopback keeps X-Forwarded-For usable
            // for per-IP login throttling without letting remote clients spoof
            // it. Only the *forwarded-for* and *forwarded-proto* headers are
            // trusted: a request whose peer is loopback must not be able to
            // rewrite the Host or scheme either (security assessment L-2/L-1).
            // NOTE: nginx rewrites X-Forwarded-For itself
            // (`fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr`) — that line is
            // a security control, not a convenience.
            ->trustProxies(
                at: ['127.0.0.1', '::1'],
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
            )
            // Reject requests whose Host header is not one we serve (security
            // assessment L-1). Without this, asset()/url() output followed the
            // attacker's Host: header. APP_URL/FRONTEND_URL hosts plus the
            // loopback names are always allowed; add any extra name you reach
            // the API by (a Tailscale IP, an alternate DNS name) to
            // TRUSTED_HOSTS in .env — comma-separated, ports are ignored.
            // Inactive in `local` and in tests, exactly like Laravel's default.
            // The patterns are passed as a callable, NOT pre-computed:
            // `withMiddleware()` runs during bootstrap *before* the config
            // repository is bound, so TrustedHosts::patterns() (which reads
            // config('app.url') et al) would fatal here and take the whole
            // application down. TrustHosts::at() accepts `array|callable` and
            // invokes the callable inside handle(), where config exists.
            ->trustHosts(at: [TrustedHosts::class, 'patterns'], subdomains: false)
            // There is no named `login` route (the console is a React SPA), so
            // the framework's guard-redirect default would hit an undefined
            // route and 500. API sessions are always JSON: forcing a null
            // redirect lets the exception handler return the clean 401 JSON
            // from `shouldRenderJsonWhen` below for any api/* request, with or
            // without an Accept: application/json header.
            ->redirectGuestsTo(
                fn (Request $request) => $request->is('api/*') ? null : '/',
            );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
