<?php

namespace Tests\Feature;

use App\Mail\AdminNotificationMail;
use App\Models\Candidate;
use App\Models\SsgPresidentInvite;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * SSG President award + secure credential handoff flow:
 *
 *   POST /api/admin/users/{user}/grant-ssg   — promote a certified winner
 *   POST /api/ssg/setup-password             — redeem the one-time setup link
 *
 * Guardrails covered here: the results_published gate (no award before the
 * admin publishes results), single-seat demotion, token revocation, and the
 * credential handoff — which never mints, emails, or returns a password.
 * Instead the grant issues a one-time, expiring, single-use invite and delivers
 * it through the President's in-app notification feed; only the token's hash is
 * stored. The President exchanges the link for a password of their own choosing,
 * and the mobile login path keeps working throughout.
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

    public function test_grant_promotes_winner_demotes_previous_holder_and_never_discloses_a_credential(): void
    {
        Mail::fake();
        $this->publishResults();

        $admin = $this->makeUser(['role' => 'admin']);
        $previousHolder = $this->makeUser(['role' => 'ssg_president']);
        $previousHolder->createToken('mobile');

        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);
        $originalHash = $winner->password;

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/users/{$winner->id}/grant-ssg")
            ->assertOk()
            ->assertJsonPath('data.role', 'ssg_president')
            ->assertJsonPath('data.ssg_setup_pending', true)
            ->assertJsonPath('credentials.email', $winner->email)
            ->assertJsonPath('credentials.role', 'ssg_president')
            ->assertJsonPath('invite.sent', true)
            ->assertJsonPath('invite.channel', 'in_app');

        // The credential itself is never part of the response.
        $this->assertArrayNotHasKey('temporary_password', $response->json('credentials'));
        $this->assertNull($response->json('credentials.temporary_password'));

        // Single seat: the previous holder is demoted and both parties lose
        // their mobile tokens so the promotion forces a fresh sign-in.
        $this->assertSame('student', $previousHolder->fresh()->role);
        $this->assertFalse((bool) $previousHolder->fresh()->ssg_setup_pending);
        $this->assertSame(0, $previousHolder->tokens()->count());
        $this->assertSame(0, $winner->tokens()->count());
        $this->assertSame([['id' => $previousHolder->id, 'name' => $previousHolder->name, 'email' => $previousHolder->email]], $response->json('replaced'));

        // The winner keeps their existing password — it is not rotated, and the
        // account is not forced through must_change_password (that gate would
        // block the very notification feed carrying the invite).
        $winner->refresh();
        $this->assertFalse((bool) $winner->must_change_password);
        $this->assertTrue((bool) $winner->ssg_setup_pending);
        $this->assertSame($originalHash, $winner->password);
        $this->assertTrue(Hash::check('secret-password', $winner->password));

        // A single-use invite row was minted, and NO email was sent (there is no
        // deliverable mailbox for a name-derived handle).
        $this->assertSame(1, SsgPresidentInvite::query()->where('user_id', $winner->id)->whereNull('used_at')->count());
        Mail::assertNothingSent();
    }

    public function test_grant_delivers_a_one_time_setup_link_through_the_notification_feed(): void
    {
        $this->publishResults();

        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);

        $this->actingAs($admin)->postJson("/api/admin/users/{$winner->id}/grant-ssg")->assertOk();

        $notification = UserNotification::query()
            ->where('user_id', $winner->id)
            ->where('link', 'like', '/ssg-setup?token=%')
            ->first();

        $this->assertNotNull($notification, 'The setup link should be delivered to the President in-app.');

        // The plaintext token in the link must match a stored invite hash.
        $token = Str::after($notification->link, 'token=');
        $this->assertNotNull(SsgPresidentInvite::findValid($token));
    }

    public function test_setup_password_redeems_the_invite_and_activates_the_dashboard(): void
    {
        $this->publishResults();

        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);

        $this->actingAs($admin)->postJson("/api/admin/users/{$winner->id}/grant-ssg")->assertOk();
        $winner = $winner->fresh();

        $token = $this->latestSetupToken($winner);
        $this->assertNotNull($token);

        // Standing in as the promoted President on the mobile app (bearer token),
        // holding both the invite link and their existing sign-in.
        $this->actingAs($winner, 'sanctum')
            ->postJson('/api/ssg/setup-password', [
                'token' => $token,
                'password' => 'Brandnew-Pass1',
                'password_confirmation' => 'Brandnew-Pass1',
            ])
            ->assertOk();

        $winner->refresh();
        $this->assertTrue(Hash::check('Brandnew-Pass1', $winner->password));
        $this->assertFalse((bool) $winner->ssg_setup_pending);

        // The invite is burned: it cannot be redeemed a second time.
        $this->assertNull(SsgPresidentInvite::findValid($token));

        // And the freshly chosen credential actually works at login.
        $this->postJson('/api/auth/login', [
            'email' => $winner->email,
            'password' => 'Brandnew-Pass1',
        ])->assertOk()->assertJsonPath('student.role', 'ssg_president');
    }

    public function test_setup_rejects_an_expired_invite(): void
    {
        $this->publishResults();

        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);

        $this->actingAs($admin)->postJson("/api/admin/users/{$winner->id}/grant-ssg")->assertOk();
        $winner = $winner->fresh();
        $token = $this->latestSetupToken($winner);

        // Push the invite past its expiry window.
        SsgPresidentInvite::query()->where('user_id', $winner->id)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($winner, 'sanctum')
            ->postJson('/api/ssg/setup-password', [
                'token' => $token,
                'password' => 'Brandnew-Pass1',
                'password_confirmation' => 'Brandnew-Pass1',
            ])
            ->assertStatus(422);

        $this->assertTrue((bool) $winner->fresh()->ssg_setup_pending);
    }

    public function test_setup_rejects_an_invalid_token(): void
    {
        $president = $this->makeUser(['role' => 'ssg_president', 'ssg_setup_pending' => true]);

        $this->actingAs($president, 'sanctum')
            ->postJson('/api/ssg/setup-password', [
                'token' => 'not-a-real-token',
                'password' => 'Brandnew-Pass1',
                'password_confirmation' => 'Brandnew-Pass1',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('secret-password', $president->fresh()->password));
    }

    public function test_setup_rejects_another_users_token(): void
    {
        $this->publishResults();

        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $this->certify($winner);

        $this->actingAs($admin)->postJson("/api/admin/users/{$winner->id}/grant-ssg")->assertOk();
        $token = $this->latestSetupToken($winner);

        // A different President tries to redeem someone else's link.
        $intruder = $this->makeUser(['role' => 'ssg_president', 'ssg_setup_pending' => true]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/ssg/setup-password', [
                'token' => $token,
                'password' => 'Brandnew-Pass1',
                'password_confirmation' => 'Brandnew-Pass1',
            ])
            ->assertStatus(422);

        // Neither account changed, and the invite is still unspent.
        $this->assertTrue(Hash::check('secret-password', $intruder->fresh()->password));
        $this->assertNotNull(SsgPresidentInvite::findValid($token));
    }

    public function test_setup_requires_the_ssg_president_role(): void
    {
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/ssg/setup-password', [
                'token' => 'irrelevant',
                'password' => 'Brandnew-Pass1',
                'password_confirmation' => 'Brandnew-Pass1',
            ])
            ->assertStatus(403);
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

    /** Pull the plaintext setup token out of the President's latest invite. */
    private function latestSetupToken(User $user): ?string
    {
        $link = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('link', 'like', '/ssg-setup?token=%')
            ->latest('id')
            ->value('link');

        return $link ? Str::after($link, 'token=') : null;
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
            $table->boolean('ssg_setup_pending')->default(false);
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

        Schema::create('ssg_president_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 50)->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}
