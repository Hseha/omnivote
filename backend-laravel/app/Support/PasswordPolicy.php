<?php

namespace App\Support;

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
 */
class PasswordPolicy
{
    /**
     * Rules for a password field, without `confirmed`.
     *
     * @return array<int, string>
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
        $rules = ['required', 'string', "min:{$minLength}", 'regex:/[a-z]/'];

        if ($requireUpper) {
            $rules[] = 'regex:/[A-Z]/';
        }
        if ($requireNumber) {
            $rules[] = 'regex:/[0-9]/';
        }
        if ($requireSpecial) {
            $rules[] = 'regex:/[^a-zA-Z0-9]/';
        }

        return $rules;
    }

    /**
     * Rules for a password field that must match a `{field}_confirmation` input.
     *
     * @return array<int, string>
     */
    public static function confirmedRules(): array
    {
        return array_merge(['required', 'string', 'confirmed'], array_slice(self::rules(), 2));
    }
}
