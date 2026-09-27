<?php

namespace App\Support;

/**
 * Generator for the one-time credentials handed to a user when an account is
 * provisioned or reset.
 *
 * SECURITY: the value must come from a CSPRNG only. It must never be derived
 * from public registrar data — a student ID (`2024-01101`) or a name-derived
 * login handle is printed on class lists and ID cards, so using either as the
 * password lets anyone who has the class list impersonate the voter and cast
 * their ballot (security assessment C-1).
 *
 * Every value satisfies `Password::defaults()` (min 8, letters, numbers) and
 * additionally contains a symbol. Visually ambiguous characters (0/O, 1/l/I)
 * are excluded because these credentials are read aloud and typed by hand, and
 * characters that break CSV/quoting (`"`, `'`, `\`, `,`) are excluded because
 * the value is distributed through the registrar credentials export.
 */
final class TemporaryPassword
{
    /** Uppercase, minus O. */
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Lowercase, minus l. */
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';

    /** Digits, minus 0 and 1. */
    private const DIGITS = '23456789';

    /** Symbols safe for CSV, URLs, shell and hand-writing. */
    private const SYMBOLS = '!@#$%^&*-_=+?';

    public static function generate(int $length = 12): string
    {
        $length = max(12, $length);
        $sets = [self::UPPER, self::LOWER, self::DIGITS, self::SYMBOLS];

        // One character from each class first so the result always satisfies the
        // password policy regardless of what the shuffle below produces.
        $password = '';
        foreach ($sets as $set) {
            $password .= $set[random_int(0, strlen($set) - 1)];
        }

        $all = implode('', $sets);
        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle so the guaranteed characters are not in a predictable slot.
        for ($i = strlen($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return $password;
    }
}
