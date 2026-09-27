<?php

namespace App\Http\Controllers\Concerns;

use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Layered login throttling for the two guard types (admin SPA session +
 * student mobile token).
 *
 * Two independent limiter keys, both checked before credentials are
 * verified:
 *
 *   1. Per-account (keyed by a normalized/hashed email). A specific
 *      account is blocked after `loginAccountMaxAttempts()` failures so
 *      brute-forcing one account cannot lock out other people.
 *   2. Per-IP (anti credential-stuffing). An IP is blocked only after a
 *      higher number of failures TOTAL across accounts, so genuine users
 *      sharing a school/office IP never trip it while still stopping bots
 *      that spray passwords at many accounts.
 *
 * Both keys decay independently (per-account faster than per-IP) and are
 * cleared together on a successful sign-in.
 */
trait HandlesLoginThrottling
{
    /** Max failed attempts before a single account is throttled. */
    protected function loginAccountMaxAttempts(): int
    {
        // Rendered from the Settings → Security value (default 5) so a banned
        // threshold chosen in the panel is actually enforced at sign-in.
        $configured = AppSettings::security('maxLoginAttempts', 5);

        return max(1, min(20, (int) $configured));
    }

    /** Decay window (seconds) for per-account failure counters. */
    protected function loginAccountDecaySeconds(): int
    {
        return 900; // 15 minutes
    }

    /** Max failed attempts per IP across all accounts before blocking. */
    protected function loginIpMaxAttempts(): int
    {
        return 15;
    }

    /** Decay window (seconds) for per-IP failure counters. */
    protected function loginIpDecaySeconds(): int
    {
        return 1800; // 30 minutes
    }

    /**
     * Guard-specific key prefix, e.g. 'admin-login' or 'login', so the
     * admin and student limiter counters never share keys.
     */
    abstract protected function loginLimiterPrefix(): string;

    private function accountKey(Request $request): string
    {
        $email = strtolower(trim((string) $request->input('email')));

        return $this->loginLimiterPrefix().'-account:'.sha1($email);
    }

    private function ipKey(Request $request): string
    {
        return $this->loginLimiterPrefix().'-ip:'.$request->ip();
    }

    /**
     * Returns a 429 response when either limiter is exhausted, otherwise
     * null so the caller can proceed with credential verification.
     */
    protected function loginThrottled(Request $request): ?JsonResponse
    {
        $account = $this->accountKey($request);
        $ip = $this->ipKey($request);

        if (RateLimiter::tooManyAttempts($account, $this->loginAccountMaxAttempts())) {
            return $this->tooManyResponse(RateLimiter::availableIn($account));
        }

        if (RateLimiter::tooManyAttempts($ip, $this->loginIpMaxAttempts())) {
            return $this->tooManyResponse(RateLimiter::availableIn($ip));
        }

        return null;
    }

    /** Count a failed attempt against both the account and IP keys. */
    protected function recordLoginFailure(Request $request): void
    {
        RateLimiter::hit($this->accountKey($request), $this->loginAccountDecaySeconds());
        RateLimiter::hit($this->ipKey($request), $this->loginIpDecaySeconds());
    }

    /** Reset both keys after a successful sign-in. */
    protected function clearLoginThrottle(Request $request): void
    {
        RateLimiter::clear($this->accountKey($request));
        RateLimiter::clear($this->ipKey($request));
    }

    private function tooManyResponse(int $retryAfter): JsonResponse
    {
        return response()->json(['message' => 'Too many attempts. Try again later.'], 429)
            ->header('Retry-After', (string) $retryAfter);
    }

    /**
     * Response used when an *account-level* backoff is in force.
     *
     * SECURITY (assessment M-3): the lockout path used to answer `423 Locked`
     * with a message naming the reason and the exact remaining minutes, while a
     * non-existent account got `401 Invalid credentials`. That difference is an
     * account-existence oracle: anyone could enumerate the registry and learn
     * which handles are real, and could then lock those specific voters out.
     *
     * The backoff therefore answers with the SAME status and body as a wrong
     * password, and communicates the wait only through a `Retry-After` header.
     * A legitimate client reads the header; an enumerator learns nothing from
     * the response itself.
     */
    protected function backoffResponse(int $retryAfterSeconds): JsonResponse
    {
        return response()->json(['message' => 'Invalid credentials'], 401)
            ->header('Retry-After', (string) max(1, $retryAfterSeconds));
    }
}
