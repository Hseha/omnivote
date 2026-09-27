<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Read administrator-saved settings for the backend runtime layers.
 *
 * The Settings screen persists values to the `election_settings` key/value
 * store under the `admin.settings.{section}.{key}` prefix (see
 * SettingsController). Every backend consumer (login throttling, password
 * policy, session lifetime) reads through this class so the stored policy is
 * actually enforced instead of being inert UI. Missing rows fall back to the
 * passed default so nothing breaks before an administrator saves a value.
 */
class AppSettings
{
    /**
     * Read one stored security setting, decoded with the same semantics the
     * Settings screen uses (booleans 'true'/'false', numbers as ints).
     */
    public static function security(string $key, mixed $default = null): mixed
    {
        return self::section('security', $key, $default);
    }

    /**
     * Read one stored backup setting (auto interval, retention, encryption).
     */
    public static function backup(string $key, mixed $default = null): mixed
    {
        return self::section('backup', $key, $default);
    }

    /**
     * Read one stored voting-window setting (e.g. `maxVotesPerVoter`). Returns
     * the configured value or `$default` when the admin has not saved it yet.
     */
    public static function voting(string $key, mixed $default = null): mixed
    {
        return self::section('voting', $key, $default);
    }

    /**
     * Read one stored notification setting (channel toggles, event gates).
     */
    public static function notifications(string $key, mixed $default = null): mixed
    {
        return self::section('notifications', $key, $default);
    }

    /**
     * Read one stored setting from any admin screens section, decoded with the
     * same semantics the Settings screen uses (booleans 'true'/'false', numbers
     * as ints).
     */
    private static function section(string $section, string $key, mixed $default = null): mixed
    {
        try {
            $value = DB::table('election_settings')
                ->where('key', 'admin.settings.'.$section.'.'.$key)
                ->value('value');
        } catch (\Throwable) {
            // Connection/table unavailable (e.g. early tests, fresh installs).
            return $default;
        }

        if ($value === null) {
            return $default;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }
}
