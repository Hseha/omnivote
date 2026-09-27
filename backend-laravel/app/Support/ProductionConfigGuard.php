<?php

namespace App\Support;

use RuntimeException;

/**
 * Refuses to serve a production request that is configured unsafely (security
 * assessment L-7).
 *
 * The same weak settings were reachable in every environment at once: debug mode
 * on (stack traces with file paths, query bindings and env disclosure through
 * /_ignition/health-check), session cookies without the Secure flag, and an
 * http:// APP_URL that password-reset links then inherit. Each one is a one-line
 * .env fix, but silently shipping them is what made them durable. Failing the
 * request with a precise message converts a quiet misconfiguration into a loud
 * one, and keeps a debug-enabled admin panel from ever coming up.
 *
 * Deliberately HTTP-only: console commands (deploy scripts, migrations, queue
 * workers) still run, so a bad .env cannot lock an operator out of the machine
 * they are trying to fix.
 */
final class ProductionConfigGuard
{
    /** @return list<string> every problem found; empty when the config is safe */
    public static function problems(): array
    {
        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false in production: stack traces expose file paths, SQL bindings and environment values.';
        }

        if (! config('session.secure')) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true in production: session cookies would be sent over plain HTTP.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL must use https:// in production: password-reset links and generated URLs inherit it.';
        }

        if (empty(config('app.key'))) {
            $problems[] = 'APP_KEY is missing: run `php artisan key:generate --force`.';
        }

        return $problems;
    }

    public static function assertSafe(): void
    {
        $problems = self::problems();

        if ($problems !== []) {
            throw new RuntimeException(
                "Insecure production configuration:\n - ".implode("\n - ", $problems)
            );
        }
    }
}
