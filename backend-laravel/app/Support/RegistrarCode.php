<?php

namespace App\Support;

use App\Models\RegistrarImport;
use Illuminate\Support\Facades\Hash;

/**
 * Registrar-issued one-time codes, stored hashed against `registrar_imports`.
 *
 * SECURITY: this closes two assessment findings that both came down to a
 * missing out-of-band secret.
 *
 *   M-5 — `POST /api/auth/register` only required that a `student_id` appeared in
 *         the eligibility feed plus any unused email, so anyone who knew a
 *         classmate's ID could provision that identity with their own email and
 *         then vote as them. Requiring a code the registrar hands out in person
 *         means knowing the ID is no longer sufficient.
 *
 *   M-3 — students sign in with a name-derived handle and have no mailbox, so a
 *         self-service *emailed* reset is impossible. A code the registrar
 *         releases is the only real recovery path, and it lets a locked-out
 *         voter recover without an administrator.
 *
 * The plaintext is shown once (in the import response / a regenerate call) and
 * never stored: the database keeps a bcrypt hash, exactly like a password.
 * Comparison is therefore constant-time by construction — `Hash::check` — so
 * there is no timing oracle on the code.
 */
final class RegistrarCode
{
    /**
     * A fresh code: 8 unambiguous characters, no vowels-adjacent confusables.
     *
     * Short enough to read down a registrar's desk and dictate over the phone,
     * long enough that 32^8 is not brute-forceable through the public endpoint
     * (which is additionally IP-throttled).
     */
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /**
     * Issue (or re-issue) the code for a registrar row and return the plaintext.
     *
     * Re-issuing replaces any previous code, so a leaked sheet can be
     * invalidated by regenerating.
     */
    public static function issue(RegistrarImport $import): string
    {
        $code = self::generate();

        $import->forceFill([
            'activation_code_hash' => Hash::make($code),
            'activation_code_issued_at' => now(),
            'activation_code_used_at' => null,
        ])->save();

        return $code;
    }

    /**
     * Whether the supplied code matches the row's current code.
     *
     * A row with no code, or an already-redeemed code, never matches.
     */
    public static function matches(RegistrarImport $import, ?string $code): bool
    {
        $hash = $import->activation_code_hash;

        if (blank($hash) || blank($code) || $import->activation_code_used_at !== null) {
            return false;
        }

        return Hash::check(strtoupper(trim($code)), $hash);
    }

    /**
     * Burn the code after a successful redemption so it is strictly single-use.
     */
    public static function redeem(RegistrarImport $import): void
    {
        $import->forceFill([
            'activation_code_used_at' => now(),
            // Keep the hash so a redeemed code can never be re-checked, but the
            // used-at marker above is what actually blocks reuse.
            'activation_code_hash' => null,
        ])->save();
    }
}
