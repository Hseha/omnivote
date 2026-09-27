<?php

namespace Tests\Feature;

use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\RegistrarCode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * M-3 — login backoff must not become a denial-of-service tool, and a locked
 * student must be able to recover without a human in the loop.
 *
 * Two distinct problems, both covered here:
 *
 *   1. `423 Locked` with "try again in N minutes" was an account-existence
 *      oracle. 401 `Invalid credentials` carries the same information without
 *      the oracle, and the wait now travels in a `Retry-After` header.
 *   2. The backoff was unbounded in the attacker's favour: someone who knew a
 *      student's handle could keep that student out indefinitely, because the
 *      only way back in was an administrator. Now the window decays, the penalty
 *      is capped, and the student can reset their own password with the
 *      registrar-issued code.
 */
class LoginBackoffRecoveryTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

    protected function setUp(): void
    {
        parent::setUp();

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
        RateLimiter::clear('login-account:'.sha1(strtolower('a.real.student@student.test')));
        RateLimiter::clear('login-ip:127.0.0.1');

        $this->createSchema();
        $this->disableTwoFactorRequirement();
    }

    /* ------------------------------------------------------------------ */
    /* 1. No account-existence oracle                                      */
    /* ------------------------------------------------------------------ */

    public function test_a_backed_off_account_and_an_unknown_account_answer_identically(): void
    {
        $existing = $this->makeUser(['email' => 'a.real.student@student.test']);
        $existing->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(1),
        ])->save();

        $backedOff = $this->postJson('/api/auth/login', [
            'email' => 'a.real.student@student.test',
            'password' => 'wrong-password',
        ]);

        $unknown = $this->postJson('/api/auth/login', [
            'email' => 'nobody.here@student.test',
            'password' => 'wrong-password',
        ]);

        $backedOff->assertStatus(401);
        $unknown->assertStatus(401);

        // Identical bodies: the response reveals nothing about existence.
        $this->assertSame(
            $unknown->json(),
            $backedOff->json(),
            'a throttled account must be indistinguishable from an unknown one'
        );
        $this->assertSame('Invalid credentials', $backedOff->json('message'));

        // The wait is still communicated, but only as a header a real client uses.
        $backedOff->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $backedOff->headers->get('Retry-After'));
        $this->assertNull($unknown->headers->get('Retry-After'));
    }

    public function test_the_backoff_is_exponential_and_capped(): void
    {
        // Below the threshold: no penalty at all.
        $this->assertSame(0, User::backoffMinutesFor(1));
        $this->assertSame(0, User::backoffMinutesFor(4));

        // Then it doubles per additional failure.
        $this->assertSame(1, User::backoffMinutesFor(5));
        $this->assertSame(2, User::backoffMinutesFor(6));
        $this->assertSame(4, User::backoffMinutesFor(7));
        $this->assertSame(8, User::backoffMinutesFor(8));
        $this->assertSame(16, User::backoffMinutesFor(9));
        $this->assertSame(32, User::backoffMinutesFor(10));

        // 1 * 2^6 = 64 would exceed the ceiling, so it clamps there...
        $this->assertSame(User::BACKOFF_MAX_MINUTES, User::backoffMinutesFor(11));

        // ...and stays clamped however long the attacker persists.
        $this->assertSame(User::BACKOFF_MAX_MINUTES, User::backoffMinutesFor(500));
        $this->assertLessThanOrEqual(60, User::backoffMinutesFor(5000));
    }

    public function test_the_backoff_decays_to_zero_once_the_window_elapses(): void
    {
        $user = $this->makeUser();
        $user->forceFill([
            'failed_login_attempts' => 9,
            'locked_until' => now()->subMinute(),
        ])->save();

        $this->assertFalse($user->fresh()->isLocked());
        $this->assertSame(0, $user->fresh()->effectiveFailedAttempts(), 'an elapsed window must not keep penalising the student');

        // The next failure therefore starts from 1, not from 10.
        $user->fresh()->recordFailedLogin();
        $this->assertSame(1, (int) $user->fresh()->failed_login_attempts);
    }

    public function test_a_successful_login_clears_the_backoff(): void
    {
        $user = $this->makeUser(['password' => 'correct-horse-battery']);

        $user->recordFailedLogin();
        $user->recordFailedLogin();
        $this->assertSame(2, (int) $user->fresh()->failed_login_attempts);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertStatus(200);

        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts);
        $this->assertNull($user->fresh()->locked_until);
    }

    /* ------------------------------------------------------------------ */
    /* 2. Self-service recovery                                            */
    /* ------------------------------------------------------------------ */

    public function test_a_student_can_reset_their_password_with_a_registrar_code(): void
    {
        $user = $this->makeUser([
            'student_id' => '2026-0001',
            'password' => 'temporary-credential',
            'must_change_password' => true,
        ]);
        $user->forceFill(['failed_login_attempts' => 7, 'locked_until' => now()->addMinutes(30)])->save();

        $import = $this->registrarImport('2026-0001', 'Ana Reyes');
        $code = RegistrarCode::issue($import);

        $this->postJson('/api/auth/password/reset-with-code', [
            'student_id' => '2026-0001',
            'code' => $code,
            'password' => 'a-brand-new-password1',
            'password_confirmation' => 'a-brand-new-password1',
        ])->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password1', $user->password));
        $this->assertFalse($user->must_change_password, 'a self-chosen password needs no rotation');
        $this->assertSame(0, (int) $user->failed_login_attempts, 'recovery must clear the backoff');
        $this->assertNull($user->locked_until);
        $this->assertSame(0, $user->tokens()->count(), 'recovery must invalidate existing sessions');

        // The code is single-use.
        $this->assertNull($import->fresh()->activation_code_hash);
        $this->assertNotNull($import->fresh()->activation_code_used_at);
    }

    public function test_a_used_or_wrong_code_does_not_reset_the_password(): void
    {
        $user = $this->makeUser(['student_id' => '2026-0002', 'password' => 'temporary-credential']);
        $import = $this->registrarImport('2026-0002', 'Ben Cruz');
        RegistrarCode::issue($import);

        $this->postJson('/api/auth/password/reset-with-code', [
            'student_id' => '2026-0002',
            'code' => 'WRONG1',
            'password' => 'a-brand-new-password1',
            'password_confirmation' => 'a-brand-new-password1',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('temporary-credential', $user->fresh()->password));
        $this->assertNull($import->fresh()->activation_code_used_at);
    }

    public function test_an_unknown_student_id_answers_generically(): void
    {
        $this->postJson('/api/auth/password/reset-with-code', [
            'student_id' => '1999-9999',
            'code' => 'ABC23456',
            'password' => 'a-brand-new-password1',
            'password_confirmation' => 'a-brand-new-password1',
        ])->assertStatus(200)
            ->assertJsonPath('message', 'If that student ID and code are valid, the password has been reset.');

        $this->assertSame(0, User::where('student_id', '1999-9999')->count());
    }

    public function test_a_code_cannot_be_replayed_after_a_successful_reset(): void
    {
        $user = $this->makeUser(['student_id' => '2026-0003', 'password' => 'temporary-credential']);
        $import = $this->registrarImport('2026-0003', 'Cara Diaz');
        $code = RegistrarCode::issue($import);

        $payload = [
            'student_id' => '2026-0003',
            'code' => $code,
            'password' => 'a-brand-new-password1',
            'password_confirmation' => 'a-brand-new-password1',
        ];

        $this->postJson('/api/auth/password/reset-with-code', $payload)->assertStatus(200);
        // Replay with the same code must change nothing.
        $this->postJson('/api/auth/password/reset-with-code', array_merge($payload, [
            'password' => 'an-attacker-password9',
            'password_confirmation' => 'an-attacker-password9',
        ]))->assertStatus(200);

        $this->assertTrue(Hash::check('a-brand-new-password1', $user->fresh()->password));
    }

    public function test_a_reset_is_rejected_when_the_password_fails_the_policy(): void
    {
        $user = $this->makeUser(['student_id' => '2026-0004', 'password' => 'temporary-credential']);
        $import = $this->registrarImport('2026-0004', 'Dan Eco');
        $code = RegistrarCode::issue($import);

        $this->postJson('/api/auth/password/reset-with-code', [
            'student_id' => '2026-0004',
            'code' => $code,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('temporary-credential', $user->fresh()->password));
    }

    /* ------------------------------------------------------------------ */
    /* 3. Admin escape hatch                                               */
    /* ------------------------------------------------------------------ */

    public function test_an_admin_can_bulk_unlock_stuck_accounts(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $stuckA = $this->makeStuck(12, 45);
        $stuckB = $this->makeStuck(30, 20);

        // One failure, no active window: a real account with a real history that
        // an operator did NOT lock. The bulk action must leave it alone.
        $notStuck = $this->makeUser();
        $notStuck->forceFill([
            'failed_login_attempts' => 1,
            'locked_until' => null,
        ])->save();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/users/bulk-unlock')
            ->assertStatus(200)
            ->assertJsonPath('unlocked_count', 2);

        $unlocked = collect($response->json('unlocked_user_ids'))->all();
        $this->assertEqualsCanonicalizing([$stuckA->id, $stuckB->id], $unlocked);

        foreach ([$stuckA, $stuckB] as $user) {
            $this->assertSame(0, (int) $user->fresh()->failed_login_attempts);
            $this->assertNull($user->fresh()->locked_until);
        }

        // An account that was not backing off is left untouched rather than "reset".
        $this->assertSame(1, (int) $notStuck->fresh()->failed_login_attempts);
        $this->assertNull($notStuck->fresh()->locked_until);
    }

    public function test_bulk_unlock_can_be_narrowed_to_one_role(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $stuckStudent = $this->makeStuck(9, 30, ['role' => 'student']);
        $stuckTeacher = $this->makeStuck(9, 30, ['role' => 'teacher']);

        $this->actingAs($admin)
            ->postJson('/api/admin/users/bulk-unlock', ['roles' => ['student']])
            ->assertStatus(200)
            ->assertJsonPath('unlocked_count', 1);

        $this->assertNull($stuckStudent->fresh()->locked_until);
        $this->assertNotNull($stuckTeacher->fresh()->locked_until, 'a role filter must not touch other roles');
    }

    public function test_bulk_unlock_requires_authentication(): void
    {
        $this->postJson('/api/admin/users/bulk-unlock')->assertStatus(401);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function registrarImport(string $studentId, string $name): RegistrarImport
    {
        return RegistrarImport::create([
            'student_id' => $studentId,
            'full_name' => $name,
        ]);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret-password',
            'role' => 'student',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * `failed_login_attempts` and `locked_until` are deliberately absent from
     * `User::$fillable` (the model is `guarded = ['*']`), so they can only be set
     * from inside the app. Creating them here requires forceFill, which is also a
     * useful guard: if a future change ever makes them mass-assignable again, this
     * test stops representing the real path.
     */
    private function makeStuck(int $attempts, int $minutes, array $attributes = []): User
    {
        $user = $this->makeUser($attributes);

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => now()->addMinutes($minutes),
        ])->save();

        return $user->fresh();
    }

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
            $table->boolean('must_change_password')->default(false);
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

        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique();
            $table->string('full_name');
            $table->string('course')->nullable();
            $table->string('year_level')->nullable();
            $table->string('activation_code_hash')->nullable();
            $table->timestamp('activation_code_issued_at')->nullable();
            $table->timestamp('activation_code_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('type');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}
