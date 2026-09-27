<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLoginThrottling;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\TwoFactor;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password as PasswordRules;

class AdminAuthController extends Controller
{
    use HandlesLoginThrottling;

    /** Sanctum token name minted alongside the stateful panel session cookie. */
    private const SESSION_TOKEN_NAME = 'admin-session';

    protected function loginLimiterPrefix(): string
    {
        return 'admin-login';
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // The panel signs in with Sanctum's stateful session cookie, so the
        // request must carry a session: regenerate() below rotates it, and the
        // 2FA handshake parks its pending marker in it. A request that never
        // matched a stateful origin (no Origin/Referer, or a non-browser client)
        // has no session bound, and $request->session() would throw a
        // RuntimeException — a 500 for what is really a client-side mistake.
        if ($unavailable = $this->sessionUnavailable($request)) {
            return $unavailable;
        }

        // Layered throttling: per-account first (brute-force), then per-IP
        // (credential stuffing). One account's failures never block others
        // on the same network.
        $throttled = $this->loginThrottled($request);

        if ($throttled) {
            return $throttled;
        }

        $user = User::where('email', $request->email)->first();

        if ($user && $user->isLocked()) {
            // Indistinguishable from a wrong password on purpose: a distinct 423
            // naming the lockout reason and the exact wait time confirmed that a
            // panel account exists (assessment M-3).
            return $this->backoffResponse($user->backoffSecondsRemaining());
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            if ($user) {
                $user->recordFailedLogin();
            }
            $this->recordLoginFailure($request);

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Panel-capable roles live in config/permissions.php and gate every
        // permission-gated route, so read them from there: a role added to the
        // config must be able to sign in instead of hitting a stale copy.
        if (! in_array($user->role, config('permissions.panel_roles', []), true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Administrators can disable an account (AdminUserController::updateStatus).
        // Credentials that still verify must not mint a session or token.
        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been disabled.'], 403);
        }

        // Accounts with 2FA enabled pause here: the password is confirmed but
        // the session token is only issued after a valid TOTP (or recovery)
        // code is presented on /login/2fa. The pending marker lives in the
        // session so the two requests share one login attempt.
        if ($user->hasTwoFactorEnabled()) {
            // The password half of the login succeeded, so the failure counters
            // (attempts + lockout) are cleared now, not at the code step.
            $user->clearLockout();
            $this->clearLoginThrottle($request);

            $request->session()->regenerate();
            $request->session()->put('two_factor_pending_user_id', $user->id);
            $request->session()->put('two_factor_pending_at', now()->timestamp);

            return response()->json([
                'requires_two_factor' => true,
                'user' => $user,
                'two_factor' => [
                    'enabled' => true,
                    'required' => $this->twoFactorEnforced(),
                ],
            ]);
        }

        return $this->completeLogin($request, $user);
    }

    /**
     * Second step of the admin login: verify the TOTP or one-time recovery
     * code begun by POST /admin/login when the account has 2FA enabled.
     */
    public function completeTwoFactorLogin(Request $request)
    {
        // The pending marker lives in the session, so a session is a
        // precondition here exactly as it is on the first step.
        if ($unavailable = $this->sessionUnavailable($request)) {
            return $unavailable;
        }

        $pendingUserId = $request->session()->get('two_factor_pending_user_id');

        if (! $pendingUserId || $this->twoFactorPendingExpired($request)) {
            return response()->json(['message' => 'Your sign-in has expired. Please sign in again.'], 401);
        }

        $user = User::find($pendingUserId);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['two_factor_pending_user_id', 'two_factor_pending_at']);

            return response()->json(['message' => 'Your sign-in has expired. Please sign in again.'], 401);
        }

        if (! $user->is_active) {
            $request->session()->forget(['two_factor_pending_user_id', 'two_factor_pending_at']);

            return response()->json(['message' => 'Your account has been disabled.'], 403);
        }

        // Step 1's eligibility checks have to still hold at the moment the
        // session is actually minted: a role can change while the 5-minute
        // handshake is open (AdminUserController::updateRole), and this is the
        // request that grants access — not the first one.
        if (! in_array($user->role, config('permissions.panel_roles', []), true)) {
            $this->dropPendingTwoFactor($request);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($this->twoFactorAttemptThrottled($user)) {
            $this->dropPendingTwoFactor($request);

            return response()->json(['message' => 'Too many 2FA attempts. Please sign in again.'], 429)
                ->header('Retry-After', '60');
        }

        // Accepts both second-factor shapes: a 6-digit TOTP or an 11-character
        // recovery code (XXXXX-XXXXX). The cap only bounds the payload;
        // validSecondFactor() decides what is really valid.
        $request->validate([
            'code' => ['required', 'string', 'max:'.TwoFactor::MAX_CODE_INPUT_LENGTH],
        ]);

        $code = trim($request->code);

        if (! $this->validSecondFactor($user, $code)) {
            RateLimiter::hit($this->twoFactorAttemptKey($user), 60);

            return response()->json(['message' => 'Invalid authentication code.'], 422);
        }

        $this->clearTwoFactorThrottle($user);
        $this->dropPendingTwoFactor($request);

        return $this->completeLogin($request, $user);
    }

    /** Grant the session/token for a fully verified panel administrator. */
    private function completeLogin(Request $request, User $user)
    {
        Auth::login($user);
        $request->session()->regenerate();
        $user->clearLockout();
        $this->clearLoginThrottle($request);

        // The panel authenticates with the session cookie only. No API token is
        // minted: the SPA never read it, and issuing a long-lived credential in
        // every login response only widened the attack surface. Any legacy
        // `admin-session` token from an older build is pruned here.
        $user->tokens()->where('name', self::SESSION_TOKEN_NAME)->delete();

        return response()->json([
            'user' => $user,
            'two_factor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'required' => $this->twoFactorEnforced(),
            ],
        ]);
    }

    /**
     * A 400 response when the request carries no session, or null when a session
     * is available and the sign-in handler may use it.
     *
     * The panel authenticates with Sanctum's stateful cookie: the session is
     * what holds the rotated id and the 2FA pending marker. Reaching
     * `$request->session()` without one throws, so both entry points check here
     * first and answer with an actionable client error instead of a 500.
     */
    private function sessionUnavailable(Request $request): ?JsonResponse
    {
        if ($request->hasSession()) {
            return null;
        }

        return response()->json([
            'message' => 'Your session could not be started. Please sign in from the admin console with cookies enabled.',
        ], 400);
    }

    private function twoFactorPendingExpired(Request $request): bool
    {
        $startedAt = (int) $request->session()->get('two_factor_pending_at', 0);

        return $startedAt === 0 || now()->timestamp > $startedAt + User::TWO_FACTOR_VERIFY_TTL;
    }

    private function twoFactorAttemptKey(User $user): string
    {
        return 'admin-2fa:'.$user->id;
    }

    private function twoFactorAttemptThrottled(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->twoFactorAttemptKey($user), User::TWO_FACTOR_MAX_ATTEMPTS);
    }

    private function clearTwoFactorThrottle(User $user): void
    {
        RateLimiter::clear($this->twoFactorAttemptKey($user));
    }

    private function dropPendingTwoFactor(Request $request): void
    {
        $request->session()->forget(['two_factor_pending_user_id', 'two_factor_pending_at']);
    }

    /** A 6-digit TOTP fails first; a recovery code is consumed on success. */
    private function validSecondFactor(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (is_string($secret) && TwoFactor::verify($secret, $code)) {
            return true;
        }

        $index = TwoFactor::findRecoveryIndex($user->two_factor_recovery_codes, $code);
        if ($index === null) {
            return false;
        }

        $codes = $user->two_factor_recovery_codes;
        unset($codes[$index]);
        $user->two_factor_recovery_codes = array_values($codes);
        $user->save();

        return true;
    }

    public function me(Request $request)
    {
        // Read the guard that actually authorised this request instead of the
        // default one, so the identity here always matches the middleware that
        // let the call through (the student endpoint does the same).
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // An account disabled after sign-in must fail its next authenticated call
        // so the SPA can clear the session and bounce back to the login screen.
        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been disabled.'], 403);
        }

        // The same rule has to cover the panel role: an administrator can move an
        // account off a panel role (AdminUserController::updateRole) while its
        // session cookie is still valid, and sign-in plus every panel route
        // already refuse that state. Without this check /me kept answering 200
        // "signed in" and the SPA rendered its shell for a role it has no views
        // for, so every panel call 403'd with no way out. The answer is 401
        // rather than 403 because the session is no longer a panel session —
        // that is the signal this SPA's interceptor already acts on by clearing
        // its state and returning to the login screen.
        if (! in_array($user->role, config('permissions.panel_roles', []), true)) {
            return response()->json([
                'message' => 'Your panel access has been revoked. Please sign in again.',
            ], 401);
        }

        return response()->json([
            'user' => $user,
            'two_factor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'required' => $this->twoFactorEnforced(),
            ],
        ]);
    }

    /**
     * Set the caller's own panel avatar, from Settings → Profile (PATCH
     * /admin/me/avatar). Accepts the same shapes the SPA's picker produces:
     * an http(s) URL (DiceBear pixel-art preset) or an image data URL (an
     * uploaded photo cropped square and exported as PNG client-side), each
     * with a strict size cap so nobody can park a multi-megabyte blob on the
     * users row. Passing null (or an empty string) clears a custom avatar,
     * which drops the account back to the initials/DiceBear fallback.
     *
     * Auth-only like the other self-service endpoints (2FA enrollment uses
     * the same placement): the caller edits their own row only, after the
     * same eligibility checks me() applies, so a disabled or de-roled
     * account cannot reach it.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been disabled.'], 403);
        }

        if (! in_array($user->role, config('permissions.panel_roles', []), true)) {
            return response()->json(['message' => 'You are not authorized to access the admin panel.'], 403);
        }

        $validated = $request->validate([
            'avatar_url' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isAcceptableAvatar((string) $value)) {
                    $fail('The avatar must be an http(s) URL or an image data URL under '.self::avatarMaxKilobytes().' KB.');
                }
            }],
        ]);

        $avatar = $validated['avatar_url'] ?? null;
        $user->avatar_url = is_string($avatar) && trim($avatar) !== '' ? trim($avatar) : null;
        $user->save();

        return response()->json([
            'message' => $user->avatar_url === null ? 'Avatar cleared.' : 'Avatar saved.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Whether an avatar_url value is acceptable: an http(s) URL (the SPA's
     * DiceBear pixel-art presets) or an image data URL (an uploaded photo
     * cropped and exported as PNG client-side), each under a strict byte cap
     * so the users row cannot become a file dump.
     */
    private static function isAcceptableAvatar(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return true; // Cleared on the write path; never stored as-is.
        }

        $maxBytes = self::avatarMaxKilobytes() * 1024;

        if (strlen($value) > $maxBytes) {
            return false;
        }

        if (str_starts_with(strtolower($value), 'http://') || str_starts_with(strtolower($value), 'https://')) {
            return strlen($value) <= self::AVATAR_HTTP_MAX_LENGTH;
        }

        return (bool) preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$#', $value);
    }

    /** Max avatar payload in kilobytes (covers a 160×160 PNG data URL). */
    private static function avatarMaxKilobytes(): int
    {
        return 100;
    }

    /** Max length for plain http(s) avatar URLs. */
    private const AVATAR_HTTP_MAX_LENGTH = 2048;

    private function twoFactorEnforced(): bool
    {
        // Secure-by-default: panel staff must enroll in 2FA unless an admin
        // explicitly turns the requirement off in Settings → Security.
        return (bool) AppSettings::security('twoFactorRequired', true);
    }

    /**
     * Step 1 of the forgot-password flow (POST /admin/password/email): sends a
     * signed reset link for any ACTIVE panel account with the given email, via
     * the Password broker (60-minute token, 60s resend throttle) + the SPA
     * reset screens. The response is deliberately generic so the endpoint can
     * never be used to fingerprint which emails have panel accounts.
     */
    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $user = User::query()
            ->where('email', strtolower(trim($validated['email'])))
            ->whereIn('role', config('permissions.panel_roles', []))
            ->first();

        if (! $user || ! $user->is_active) {
            return response()->json([
                'message' => 'If an account exists for that email, a reset link has been sent.',
            ]);
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        // A 429 *only here* undid the generic response above: an unknown address
        // always gets 200, while hitting the broker's resend ceiling proves the
        // address belongs to an active panel account — so the endpoint stayed a
        // verified-address oracle for anyone willing to make a few requests
        // (security assessment L-9). The limit is still enforced; the reply just
        // stops advertising which account it was enforced against. The per-IP
        // `throttle:password-reset` middleware on this route (M-2) is what
        // actually protects the mail queue and SMTP cost.
        if ($status === Password::RESET_THROTTLED) {
            Log::info('Password reset link throttled.', ['email' => $user->email]);
        }

        return response()->json([
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);
    }

    /**
     * Step 2 of the forgot-password flow (POST /admin/password/reset):
     * validates the token from the emailed link and the new password, resets
     * it via the Password broker, then revokes every existing session/token so
     * old devices cannot keep using the previous password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRules::defaults()],
        ]);

        $validated['email'] = strtolower(trim($validated['email']));

        $status = Password::broker()->reset($validated, function (User $user, string $password): void {
            $user->forceFill(['password' => $password])->save();

            // Old sessions/tokens die with the old password: any device that
            // signed in earlier must re-authenticate with the new credentials.
            $user->tokens()->delete();
        });

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Too many password reset attempts. Please wait a moment and try again.',
            ], 429);
        }

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset. Please sign in with your new password.',
        ]);
    }

    public function logout(Request $request)
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $user->tokens()->where('name', self::SESSION_TOKEN_NAME)->delete();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
