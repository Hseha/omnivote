<?php

namespace App\Providers;

use App\Support\ProductionConfigGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One password policy for every credential-setting path (registration,
        // admin reset, student change-password). `uncompromised()` is
        // deliberately omitted: it calls the HaveIBeenPwned API, and the
        // election host is expected to run on a closed school network.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Refuse to serve HTTP traffic on a production install that is still
        // configured like a dev box (debug traces on, unsecured session cookies,
        // http:// APP_URL). Console stays usable so the operator can fix the
        // .env that tripped this (security assessment L-7).
        if (! $this->app->runningInConsole() && $this->app->environment('production')) {
            ProductionConfigGuard::assertSafe();
        }

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinutes(15, 5)->by($request->ip());
        });

        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinutes(15, 5)->by($request->ip());
        });

        // Unauthenticated reads (voting instructions, announcements, branding,
        // election status, candidates, results) previously had no limit at all,
        // so they doubled as an unauthenticated DB-flooding surface (security
        // assessment M-2).
        //
        // The bucket is keyed per IP *and* path, and the ceiling is per IP:
        // a school building shares one address, and the consoles poll several of
        // these endpoints on a timer, so a single shared budget would lock a
        // whole room out. 300/min per endpoint per building stops floods and
        // runaway refresh loops while leaving a normal election night untouched.
        RateLimiter::for('api-public', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip().'|'.$request->path());
        });

        // Anything that costs money, sends mail, leaks election state, or is a
        // password-guessing surface gets a named bucket so its ceiling is stated
        // in one place instead of inline in the route file: 2FA enrolment, the
        // receipt-verification oracle, and the password-reset mail/reset pair.
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->path());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Receipt verification compares a caller-supplied token against an
        // HMAC, so it is an offline-guessing oracle if left unlimited. Keyed per
        // account (the route is student-authenticated), because a voting hall
        // shares one source address and voters legitimately check receipts.
        RateLimiter::for('receipt-verify', function (Request $request) {
            return Limit::perMinute(20)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }
}
