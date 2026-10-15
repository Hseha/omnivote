<?php

namespace App\Support;

use Closure;

/**
 * The student password policy, rendered from the Security settings an
 * administrator saves (Settings → Security).
 *
 * Self-registration, change-password and reset-with-code all build their rules
 * here so the toggles (`passwordMinLength`, `passwordRequireUpper`,
 * `passwordRequireNumber`, `passwordRequireSpecial`) cannot be enforced on one
 * path and silently ignored on another. Before this helper existed the
 * registration request honoured the settings but the change/reset endpoints
 * only applied `Password::defaults()`, so an admin could raise the policy and
 * students could still set weaker passwords.
 *
 * Each requirement is expressed as a self-messaging closure rather than a bare
 * `regex:` so a student is told exactly what is missing ("Your password must
 * include an uppercase letter."). A failed regex renders Laravel's opaque
 * default — "The password field format is invalid." — which reads like a bug on
 * the forced first-login change-password screen.
 */
class PasswordPolicy
{
    /**
     * Rules for a password field, without `confirmed`.
     *
     * @return array<int, string|Closure>
     */
    public static function rules(): array
    {
        $minLength = max(6, min(64, (int) AppSettings::security('passwordMinLength', 8)));
        $requireSpecial = (bool) AppSettings::security('passwordRequireSpecial', false);
        $requireNumber = (bool) AppSettings::security('passwordRequireNumber', true);
        $requireUpper = (bool) AppSettings::security('passwordRequireUpper', true);

        // Base policy: length floor + a lowercase letter, then the toggled
        // complexity requirements layer on top. These defaults (min 8, upper,
        // number, no special) mirror the policy enforced before an admin ever
        // saves a Security profile.
        $rules = [
            'required',
            'string',
            self::requirement(
                fn (string $value): bool => mb_strlen($value) >= $minLength,
                "Your password must be at least {$minLength} characters.",
            ),
            self::requirement(
                fn (string $value): bool => (bool) preg_match('/[a-z]/', $value),
                'Your password must include a lowercase letter.',
            ),
        ];

        if ($requireUpper) {
            $rules[] = self::requirement(
                fn (string $value): bool => (bool) preg_match('/[A-Z]/', $value),
                'Your password must include an uppercase letter.',
            );
        }

        if ($requireNumber) {
            $rules[] = self::requirement(
                fn (string $value): bool => (bool) preg_match('/[0-9]/', $value),
                'Your password must include a number.',
            );
        }

        if ($requireSpecial) {
            $rules[] = self::requirement(
                fn (string $value): bool => (bool) preg_match('/[^A-Za-z0-9]/', $value),
                'Your password must include a special character.',
            );
        }

        return $rules;
    }

    /**
     * Rules for a password field that must match a `{field}_confirmation` input.
     *
     * @return array<int, string|Closure>
     */
    public static function confirmedRules(): array
    {
        return array_merge(['required', 'string', 'confirmed'], array_slice(self::rules(), 2));
    }

    /**
     * Wrap a predicate in a validation rule that reports a single, plain-language
     * message when it fails — the whole point of this helper, so the policy is
     * enforced with an understandable error on every endpoint that shares it.
     */
    private static function requirement(Closure $check, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($check, $message): void {
            if (! $check((string) $value)) {
                $fail($message);
            }
        };
    }
}
