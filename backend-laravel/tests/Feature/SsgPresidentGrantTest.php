<?php

namespace Tests\Feature;

use App\Mail\AdminNotificationMail;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * SSG President award flow:
 *
 *   POST /api/admin/users/{user}/grant-ssg — promote a certified election
 *        winner to the single-seat ssg_president role.
 *
 * Guardrails covered here: the results_published gate (no award before the
 * admin publishes results), single-seat demotion, token revocation, the
 * credentials handoff (response payload + emailed handoff that never leaks a
 * password hash), the temporary-password mint for accounts without one, and
 * the mobile login path that lets a promoted President still sign in.
 */
class SsgPresidentGrantTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

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
        $this->disableTwoFactorRequirement();
    }

    public function test_grant_is_rejected_before_results_are_published(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);

        $this->actingAs($admin)->postJson("/api/admin/users/{$winner->id}/grant-ssg")
            ->assertStatus(422)
            ->assertJsonPath('message', 'SSG President access can only be granted after the election results are published. Current status: not published.');

        $this->assertSame('student', $winner->fresh()->role);
    }

    public function test_grant_requires_a_certified_winner_record(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($admin)->postJson("/api/admin/users/{$student->id}/grant-ssg")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This user has no certified election winner record. Only certified winners can be granted SSG President access.');

        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_grant_promotes_winner_demotes_previous_holder_and_keeps_existing_password(): void
    {
        Mail::fake();
        $this->publishResults();

        $admin = $this->makeUser(['role' => 'admin']);
        $previousHolder = $this->makeUser(['role' => 'ssg_president']);
        $previousHolderToken = $previousHolder->createToken('mobile')->plainTextToken;

        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);
        $winnerToken = $winner->createToken('mobile')->plainTextToken;

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/users/{$winner->id}/grant-ssg")
            ->assertOk()
            ->assertJsonPath('data.role', 'ssg_president')
            ->assertJsonPath('credentials.email', $winner->email)
            ->assertJsonPath('credentials.role', 'ssg_president');

        // No password existed to rotate, so no temporary one was minted.
        $this->assertArrayNotHasKey('temporary_password', $response->json('credentials'));

        // Single seat: the previous holder is demoted and both parties lose
        // their mobile tokens so the promotion forces a fresh sign-in.
        $this->assertSame('student', $previousHolder->fresh()->role);
        $this->assertSame(0, $previousHolder->tokens()->count());
        $this->assertSame(0, $winner->tokens()->count());
        $this->assertSame([['id' => $previousHolder->id, 'name' => $previousHolder->name, 'email' => $previousHolder->email]], $response->json('replaced'));

        // The winner keeps their existing password and is not forced to rotate.
        $winner->refresh();
        $this->assertFalse((bool) $winner->must_change_password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-password', $winner->password));

        // The emailed handoff names the previous role and never contains a
        // password hash — only the "unchanged" wording for this branch.
        Mail::assertSent(AdminNotificationMail::class, function (AdminNotificationMail $mail) use ($winner) {
            return $mail->title === 'Your OmniVote SSG President account'
                && str_contains($mail->body, "promoted from 'student' to 'ssg_president'")
                && str_contains($mail->body, 'the one you already use')
                && ! str_contains($mail->body, $winner->password);
        });
    }

    public function test_grant_mints_a_temporary_password_for_an_account_without_one(): void
    {
        Mail::fake();
        $this->publishResults();

        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);

        // Simulate an account that never received a registrar password.
        DB::table('users')->where('id', $winner->id)->update(['password' => '']);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/users/{$winner->id}/grant-ssg")
            ->assertOk()
            ->assertJsonPath('data.role', 'ssg_president');

        $temporaryPassword = $response->json('credentials.temporary_password');
        $this->assertNotEmpty($temporaryPassword);

        // The database stores only the hash; the plaintext exists solely in
        // the response and the email, and the account must rotate on sign-in.
        $winner->refresh();
        $this->assertNotSame($temporaryPassword, $winner->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($temporaryPassword, $winner->password));
        $this->assertTrue((bool) $winner->must_change_password);

        Mail::assertSent(AdminNotificationMail::class, function (AdminNotificationMail $mail) use ($temporaryPassword, $winner) {
            return str_contains($mail->body, $temporaryPassword)
                && str_contains($mail->body, 'promoted from \'student\'')
                && ! str_contains($mail->body, $winner->password);
        });

        // The minted credential works on the mobile login path end to end.
        $this->postJson('/api/auth/login', [
            'email' => $winner->email,
            'password' => $temporaryPassword,
        ])
            ->assertOk()
            ->assertJsonPath('student.role', 'ssg_president')
            ->assertJsonPath('must_change_password', true);
    }

    public function test_ssg_president_can_still_sign_in_on_mobile(): void
    {
        $president = $this->makeUser(['role' => 'ssg_president']);

        $this->postJson('/api/auth/login', [
            'email' => $president->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('student.role', 'ssg_president')
            ->assertJsonPath('must_change_password', false);

        $this->assertNotEmpty($president->fresh()->tokens()->pluck('id'));
    }

    public function test_disabled_ssg_president_cannot_mint_a_mobile_token(): void
    {
        $president = $this->makeUser(['role' => 'ssg_president', 'is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => $president->email,
            'password' => 'secret-password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account has been disabled.');

        $this->assertSame(0, $president->tokens()->count());
    }

    // --- helpers ----------------------------------------------------------

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

    private function certify(User $user): Candidate
    {
        $candidate = new Candidate();
        $candidate->forceFill([
            'user_id' => $user->id,
            'certified_winner' => true,
        ])->save();

        return $candidate;
    }

    private function publishResults(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'results_published'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** Minimal schema for the models exercised by this flow. */
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
            $table->string('course')->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('voted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
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

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('certified_winner')->default(false);
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
