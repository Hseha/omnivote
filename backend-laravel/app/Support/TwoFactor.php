<?php

namespace App\Support;

use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor primitives shared by the auth + self-management flows.
 *
 * Wraps pragmarx/google2fa (RFC 6238) and adds the pieces that library does
 * not ship: the otpauth:// challenge URI used to render a QR code client-side,
 * and the one-time recovery codes that let a user back into an account when
 * their authenticator app is unavailable.
 */
class TwoFactor
{
    /**
     * Longest `code` input the login / self-service 2FA endpoints accept.
     *
     * A code is either a 6-digit TOTP or an 11-character recovery code
     * (XXXXX-XXXXX). Request validation must use this constant rather than a
     * hand-picked number: a cap below the generated recovery-code length
     * rejects every recovery code with a 422 before the code is ever checked,
     * making the "lost your device" path unreachable.
     */
    public const MAX_CODE_INPUT_LENGTH = 20;

    /** Exact length of a generated recovery code: XXXXX-XXXXX. */
    public const RECOVERY_CODE_LENGTH = 11;

    private static Google2FA $google;

    private static function google(): Google2FA
    {
        return self::$google ??= new Google2FA;
    }

    /**
     * Generate a fresh random base32 shared secret for a new enrollment.
     */
    public static function generateSecret(): string
    {
        return self::google()->generateSecretKey();
    }

    /**
     * Build the otpauth:// challenge URI the authenticator app scans.
     */
    public static function otpauthUri(string $account, string $secret, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        // Keep the query minimal and deterministic; Google Authenticator is
        // happy without period/digits when they equal the RFC 6238 defaults.
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
        ]);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Verify a 6-digit code against the secret, tolerating a small clock
     * skew on either side (30 s per step).
     */
    public static function verify(string $secret, string $code): bool
    {
        try {
            return self::google()->verifyKey($secret, trim($code), 1);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate a set of one-time recovery codes (format: XXXXX-XXXXX).
     */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::randomBlock(5).'-'.self::randomBlock(5);
        }

        return $codes;
    }

    /**
     * Prepare recovery codes for storage: bcrypt-hash each so a database leak
     * cannot be replayed, keyed by the same index the plaintext sits in.
     */
    public static function hashRecoveryCodes(array $codes): array
    {
        return array_map(
            fn (string $code) => password_hash($code, PASSWORD_BCRYPT),
            $codes,
        );
    }

    /**
     * Look up a supplied recovery code against hashed store entries; returns
     * the matched index or null. Used to consume a code exactly once. Matching
     * ignores letter case and surrounding whitespace so a retyped/pasted code
     * still works.
     */
    public static function findRecoveryIndex(?array $hashed, string $input): ?int
    {
        if (empty($hashed)) {
            return null;
        }

        // Codes are generated uppercase (XXXXX-XXXXX) and hashed that way, so
        // normalise the candidate before comparison: people retype or paste
        // them in lowercase, or with stray surrounding whitespace.
        $candidate = strtoupper(trim($input));

        if ($candidate === '') {
            return null;
        }

        foreach ($hashed as $index => $hash) {
            if (is_string($hash) && password_verify($candidate, $hash)) {
                return $index;
            }
        }

        return null;
    }

    private static function randomBlock(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $block = '';
        for ($i = 0; $i < $length; $i++) {
            $block .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $block;
    }
}
