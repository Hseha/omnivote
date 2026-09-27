<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end forgot-password flow (POST /api/admin/password/email and
 * /api/admin/password/reset). The broker stores a short-lived token in the
 * password_reset_tokens table, the email (log mailer here) carries the link,
 * and a valid token flips the password AND revokes every existing session.
 */
class PasswordResetTest extends TestCase
{
    private const OLD_PASSWORD = 'old-password';
    private const NEW_PASSWORD = 'new-password-123';

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
            'mail.default' => 'log',
            'mail.mailers.log' => ['transport' => 'log'],
            // Let the flow test reset immediately after minting the token
            // instead of waiting out the 60s reset throttle.
            'auth.passwords.users.throttle' => 0,
        ]);

        DB::setDefaultConnection('omnivote_testing');
        DB::purge('omnivote_testing');

        $this->createSchema();
    }

    public function test_email_endpoint_is_generic_for_unknown_and_student_emails(): void
    {
        $this->stateful()
            ->postJson('/api/admin/password/email', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a reset link has been sent.');

        $student = $this->makeUser(['role' => 'student']);
        $this->stateful()
            ->postJson('/api/admin/password/email', ['email' => $student->email])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a reset link has been sent.');

        // No token should ever be minted for either.
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_full_reset_flow_changes_the_password_and_revokes_sessions(): void
    {
        Notification::fake();

        $user = $this->makeUser(['role' => 'admin']);
        // Seed an existing session/token that must die on reset.
        $user->tokens()->create(['name' => 'old session', 'token' => hash('sha256', Str::random(40))]);

        $this->stateful()
            ->postJson('/api/admin/password/email', ['email' => $user->email])
            ->assertOk();

        Notification::assertSentTo($user, PasswordResetLink::class);

        // The panic the email carries is the RAW token (the row in
        // password_reset_tokens stores only a hash of it, so it cannot be
        // replayed from the database).
        $token = Notification::sent($user, PasswordResetLink::class)->first()->token;
        $this->assertNotNull($token);
        // A token should still be minted and stored (hashed) for the account.
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->assertNotSame($token, DB::table('password_reset_tokens')->value('token'), 'only a hash of the token is stored');

        $this->stateful()
            ->postJson('/api/admin/password/reset', [
                'email' => $user->email,
                'token' => $token,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset. Please sign in with your new password.');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
        $this->assertSame(0, DB::table('password_reset_tokens')->count(), 'resetting the password deletes the token');
        $this->assertSame(0, $fresh->tokens()->count(), 'reset must revoke every existing session/token');
    }

    public function test_reset_rejects_garbage_tokens(): void
    {
        $user = $this->makeUser(['role' => 'admin']);

        $this->stateful()
            ->postJson('/api/admin/password/reset', [
                'email' => $user->email,
                'token' => 'bogus-token',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This reset link is invalid or has expired. Please request a new one.');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_reset_requires_a_minimum_length_password(): void
    {
        $user = $this->makeUser(['role' => 'admin']);

        $this->stateful()
            ->postJson('/api/admin/password/reset', [
                'email' => $user->email,
                'token' => 'any',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
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
            'password' => self::OLD_PASSWORD,
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

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
}