<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AppSettings;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service TOTP enrollment for panel staff (React Settings → Security /
 * forced-enrollment interstitial).
 *
 *   POST /admin/2fa/prepare  — { password }   start enrollment, get QR data
 *   POST /admin/2fa/confirm  — { code }       activate a prepared enrollment
 *   POST /admin/2fa/disable  — { password, code }  tear down 2FA
 *   GET  /admin/2fa/status   — current enrollment state for the Settings UI
 *
 * The pending shared secret lives in the session between prepare and confirm so
 * the plaintext is never round-tripped through the network a second time.
 */
class AdminTwoFactorController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            'recovery_codes_remaining' => $user->hasTwoFactorEnabled()
                ? count($user->two_factor_recovery_codes ?? [])
                : 0,
            'required' => (bool) AppSettings::security('twoFactorRequired', true),
        ]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled for your account.'], 422);
        }

        // Re-confirm the password: a hijacked session must not let an attacker
        // enroll codes they control (which would lock the real owner out).
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Your password is incorrect.'], 422);
        }

        $secret = TwoFactor::generateSecret();
        $this->storePendingSecret($request, $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => TwoFactor::otpauthUri($user->email, $secret, config('app.name', 'OmniVote')),
            'account' => $user->email,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled for your account.'], 422);
        }

        $secret = $this->pullPendingSecret($request);
        if ($secret === null) {
            return response()->json(['message' => 'Start the setup again to generate a fresh QR code.'], 422);
        }

        $request->validate(['code' => ['required', 'string', 'max:'.TwoFactor::MAX_CODE_INPUT_LENGTH]]);

        if (! TwoFactor::verify($secret, (string) $request->code)) {
            // A wrong code invalidates this attempt; the admin clicks "restart"
            // to get a fresh secret rather than brute-forcing a probe loop.
            return response()->json(['message' => 'Invalid authentication code.'], 422);
        }

        $recoveryCodes = TwoFactor::generateRecoveryCodes();
        $user->two_factor_secret = $secret;
        $user->two_factor_enabled = true;
        $user->two_factor_recovery_codes = TwoFactor::hashRecoveryCodes($recoveryCodes);
        $user->save();

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        if ((bool) AppSettings::security('twoFactorRequired', true)) {
            return response()->json(['message' => 'Two-factor authentication is enforced and cannot be disabled.'], 422);
        }

        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is not enabled for your account.'], 422);
        }

        // The length cap must clear a recovery code (XXXXX-XXXXX) as well as a
        // 6-digit TOTP, since both are accepted below.
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:'.TwoFactor::MAX_CODE_INPUT_LENGTH],
        ]);

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Your password is incorrect.'], 422);
        }

        // A live TOTP worth proving possession; a recovery code also satisfies
        // this (and is consumed) so a lost device isn't a permanent lockout.
        $code = trim((string) $request->code);
        $secretOk = is_string($user->two_factor_secret) && TwoFactor::verify($user->two_factor_secret, $code);

        $recoveryIndex = $secretOk ? null : TwoFactor::findRecoveryIndex($user->two_factor_recovery_codes, $code);

        if (! $secretOk && $recoveryIndex === null) {
            return response()->json(['message' => 'Invalid authentication code.'], 422);
        }

        if ($recoveryIndex !== null) {
            $codes = $user->two_factor_recovery_codes;
            unset($codes[$recoveryIndex]);
            $user->two_factor_recovery_codes = array_values($codes);
        }

        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->two_factor_recovery_codes = [];
        $user->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    private function storePendingSecret(Request $request, string $secret): void
    {
        $request->session()->put('two_factor_pending_secret', $secret);
    }

    private function pullPendingSecret(Request $request): ?string
    {
        $secret = $request->session()->pull('two_factor_pending_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
