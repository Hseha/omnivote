<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the layered login throttling (per-account first,
 * then per-IP) on both sign-in flows. One account's failures must never
 * lock out other accounts on the same IP; only a high failure total across
 * accounts triggers the IP-level block.
 *
 * Uses the same isolated in-memory SQLite schema as DisabledUserLoginTest
 * (the real migration set contains MySQL-only statements).
 */
class LoginRateLimitTest extends TestCase
{
    private const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn () => 'testing');

        DB::purge();

        config([
            'database.default' => 'omnivote_testing',
            'database.connections.omnivote_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);

        DB::setDefaultConnection('omnivote_testing');
        DB::purge('omnivote_testing');

        $this->createSchema();
    }

    public function test_bad_attempts_on_one_admin_account_do_not_throttle_others_on_the_same_ip(): void
    {
        $victim = $this->makeUser(['role' => 'admin']);
        $other = $this->makeUser(['role' => 'admin', 'name' => 'Other Admin']);

        for ($i = 0; $i < 5; $i++) {
            $this->stateful()
                ->postJson('/api/admin/login', ['email' => $victim->email, 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        // The victim is now throttled even with the correct password …
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $victim->email, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // … but a different account on the same IP still signs in normally.
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $other->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.id', $other->id);
    }

    public function test_bad_attempts_on_one_student_account_do_not_throttle_others_on_the_same_ip(): void
    {
        $victim = $this->makeUser(['role' => 'student']);
        $other = $this->makeUser(['role' => 'student']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $victim->email, 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => $victim->email, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->postJson('/api/auth/login', ['email' => $other->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('student.id', $other->id);
    }

    public function test_credential_stuffing_across_many_accounts_triggers_the_ip_block(): void
    {
        $real = $this->makeUser(['role' => 'admin']);

        // 15 failures across 15 different (nonexistent) emails on one IP.
        for ($i = 0; $i < 15; $i++) {
            $this->stateful()
                ->postJson('/api/admin/login', ['email' => "spray-{$i}@example.test", 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        // The 16th attempt — this time with valid credentials — is blocked at the IP level.
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $real->email, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_successful_login_clears_the_throttle_counters(): void
    {
        $user = $this->makeUser(['role' => 'admin']);

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401);

        // A successful login resets the per-account counter, so one later
        // mistake no longer pushes the account over the limit.
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->stateful()
                ->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        // The success above cleared the counter, so the earlier mistake does
        // not count: a full window of 5 fresh failures is needed to reach the
        // cap, and the attempt after that is refused at the limiter even with
        // the correct password.
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    /**
     * The Settings → Security "Max Login Attempts" value must drive the
     * per-account throttle cap, not the hardcoded default of 5.
     */
    public function test_configured_max_login_attempts_changes_the_account_cap(): void
    {
        DB::table('election_settings')->insert([
            'key' => 'admin.settings.security.maxLoginAttempts',
            'value' => '2',
            'updated_at' => now(),
        ]);

        $user = $this->makeUser(['role' => 'admin']);

        // Two wrong attempts is enough to throttle with the configured cap of 2.
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401);
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401);

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    /** Send the request as the first-party SPA so Sanctum starts a session. */
    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => self::PASSWORD,
            'role' => 'student',
            'is_active' => true,
        ], $attributes));
    }

    /** Minimal schema for the models exercised by these flows. */
    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->default('student');
            $table->string('year_level')->nullable();
            $table->string('block_number')->nullable();
            $table->string('department')->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}
