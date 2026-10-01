<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Provisions the admin-console logins a development machine needs — the three
 * roles in config('permissions.panel_roles') — from database/data/users.json.
 *
 * The fixture deliberately carries no passwords. It used to, in plain text, so
 * every clone of this repository contained working administrator credentials
 * (those values are still in git history: treat them as burned, and rotate any
 * environment that was seeded from them). A password now comes from whoever runs
 * this seeder, resolved per entry by resolvePassword(), in this order:
 *
 *   1. a "password" key on the entry itself, for a throwaway local override;
 *   2. SEED_DEV_PASSWORD from the environment, so a team can share one known dev
 *      login without committing it;
 *   3. otherwise a generated 20-character password, printed once (the same
 *      show-it-once pattern deploy/setup-server.sh uses for the database
 *      password).
 *
 * Idempotent: an email that already exists is left entirely alone, so a
 * generated password can never silently replace one a developer is using.
 *
 * Skipped in production — see run(). That guard, not the password handling, is
 * what actually protects a live database: these are convenience *panel*
 * accounts and production must create its own through the admin console.
 */
class UserSeeder extends Seeder
{
    /** Keys a fixture entry must provide. */
    private const REQUIRED_KEYS = ['name', 'email', 'role'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'UserSeeder skipped: it provisions convenience panel logins and must not run against '
                .'a production database. Create real accounts through the admin console.'
            );

            return;
        }

        $issued = [];
        $existing = 0;

        foreach ($this->fixture() as $index => $user) {
            $this->assertEntryIsUsable($user, $index);

            // Keyed on email, the login identity. An existing account keeps its
            // password, role and flags exactly as they are.
            if (User::where('email', $user['email'])->exists()) {
                $existing++;

                continue;
            }

            $password = $this->resolvePassword($user);

            User::create([
                'name' => $user['name'],
                'email' => $user['email'],
                'password' => $password['value'],
                'role' => $user['role'],
            ]);

            $issued[$user['email']] = $password;
        }

        $this->report($issued, $existing);
    }

    /**
     * Resolve the password for one fixture entry.
     *
     * Public, and free of database access, so the precedence rules above can be
     * asserted directly (tests/Unit/SeederPasswordResolutionTest.php) without
     * touching a database.
     *
     * @param  array<string, mixed>  $user
     * @return array{value: string, generated: bool}
     */
    public function resolvePassword(array $user): array
    {
        $explicit = $user['password'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return ['value' => $explicit, 'generated' => false];
        }

        // env() is read here rather than through config() on purpose: this is a
        // CLI-only developer knob, and caching it would freeze one machine's
        // shared password into every environment's config cache.
        $shared = env('SEED_DEV_PASSWORD');

        if (is_string($shared) && $shared !== '') {
            return ['value' => $shared, 'generated' => false];
        }

        // Letters and digits: strong enough that the generated value is not the
        // weak link, and still copy-pasteable from the console.
        return ['value' => Str::password(20, true), 'generated' => true];
    }

    /**
     * Read the JSON fixture, failing with an actionable message rather than a
     * framework exception (this runs on developer machines, where "file not
     * found" is the common case after checking out an older branch).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fixture(): array
    {
        $path = database_path('data/users.json');

        if (! File::exists($path)) {
            throw new RuntimeException(
                "$path is missing. It must be a JSON array with one object per panel login, "
                .'each holding "name", "email" and "role" (and optionally "password").'
            );
        }

        try {
            $users = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("$path is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($users) || $users === [] || ! array_is_list($users)) {
            throw new RuntimeException("$path must be a non-empty JSON array of user objects.");
        }

        return $users;
    }

    /**
     * Reject a fixture entry that would create an account nobody can use.
     *
     * The role check matters most: config('permissions.panel_roles') is what
     * AdminAuthController verifies before it issues a panel token, so a typo like
     * "ssg-president" would otherwise produce a user row that can never sign in.
     *
     * @param  array<string, mixed>  $user
     */
    private function assertEntryIsUsable(array $user, int $index): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            $value = $user[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("users.json entry #$index is missing a non-empty \"$key\".");
            }
        }

        $panelRoles = config('permissions.panel_roles', []);

        if (! in_array($user['role'], $panelRoles, true)) {
            throw new RuntimeException(
                "users.json entry #$index has role \"{$user['role']}\", which is not a panel role ("
                .implode(', ', $panelRoles).'); only a panel role can sign in to the admin console.'
            );
        }

        if (array_key_exists('password', $user) && ! is_string($user['password'])) {
            throw new RuntimeException("users.json entry #$index has a non-string \"password\"; remove the key to have one generated.");
        }
    }

    /**
     * Print what was created — and, where relevant, the only readable copy of a
     * password that will ever exist (the column stores a hash).
     *
     * @param  array<string, array{value: string, generated: bool}>  $issued
     */
    private function report(array $issued, int $existing): void
    {
        $skipped = $existing > 0 ? ", $existing already present and untouched" : '';

        if ($issued === []) {
            $this->command?->info("UserSeeder: no new panel logins{$skipped}.");

            return;
        }

        $this->command?->info('UserSeeder: created '.count($issued)." panel login(s){$skipped}.");

        $generated = false;

        foreach ($issued as $email => $password) {
            $note = $password['generated'] ? 'generated' : 'from fixture/env';
            $this->command?->line("  $email  {$password['value']}  ($note)");
            $generated = $generated || $password['generated'];
        }

        if ($generated) {
            $this->command?->warn(
                'Generated passwords are shown once, here, and stored only as a hash — copy them now. '
                .'Set SEED_DEV_PASSWORD, or add a "password" key to an entry, to choose your own.'
            );
        }
    }
}
