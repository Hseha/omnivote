<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The two-factor login handshake and self-service enrollment.
 *
 *   login opts out:          password + 2FA disabled  -> full session
 *   login opts in:           password + 2FA enabled   -> requires_two_factor
 *   /login/2fa with a code:  valid TOTP or recovery code -> full session
 *   Security → 2FA Required: unenrolled staff blocked from panel routes
 *
 * Uses the same isolated in-memory SQLite schema as the other auth tests,
 * extended with the two-factor columns added by the 2026_09_21 migration.
 */
class TwoFactorLoginTest extends TestCase
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

    public function test_login_pauses_for_two_factor_when_enabled(): void
    {
        $user = $this->twoFactorUser();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('requires_two_factor', true)
            ->assertJsonPath('two_factor.enabled', true)
            ->assertJsonMissingPath('token');

        // No session or token exists until the code is verified.
        $this->assertGuest();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_completes_with_a_valid_totp_code(): void
    {
        $user = $this->twoFactorUser();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => $code])
            ->assertOk()
            ->assertJsonStructure(['user', 'two_factor'])
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);
        // The panel is cookie-session only; no API token is minted.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_rejects_a_wrong_code(): void
    {
        $user = $this->twoFactorUser();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid authentication code.');

        $this->assertGuest();
    }

    public function test_login_completes_with_a_recovery_code_and_consumes_it(): void
    {
        $user = $this->twoFactorUser();
        // Store the whole generated pair: one code is spent below, so exactly
        // one must survive for the assertion further down.
        $codes = TwoFactor::generateRecoveryCodes(2);
        $recovery = $codes[0];

        $user->two_factor_recovery_codes = TwoFactor::hashRecoveryCodes($codes);
        $user->save();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => $recovery])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        // The used recovery code is gone; the same one can't be replayed.
        $codes = collect($user->fresh()->two_factor_recovery_codes);
        $this->assertCount(1, $codes);
        $this->assertNull(TwoFactor::findRecoveryIndex($codes->all(), $recovery));
    }

    public function test_login_2fa_returns_401_without_a_pending_handshake(): void
    {
        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => '123456'])
            ->assertStatus(401);
    }

    /**
     * The panel signs in with Sanctum's stateful session cookie, so a request
     * that never matched a stateful origin has no session to hold the 2FA
     * pending marker (or the rotated id). It must answer with a client error:
     * `$request->session()` used to throw a RuntimeException and return 500.
     */
    public function test_sign_in_requires_a_stateful_session_and_never_500s(): void
    {
        $user = $this->makeUser(['role' => 'admin']);

        // No Origin/Referer header: Sanctum never starts a session.
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Your session could not be started. Please sign in from the admin console with cookies enabled.');

        $this->postJson('/api/admin/login/2fa', ['code' => '123456'])
            ->assertStatus(400);

        // Nothing was granted: no session, no token.
        $this->assertGuest();
        $this->assertSame(0, $user->tokens()->count());
    }

    /** Recovery codes are retyped by hand, so case must not decide the outcome. */
    public function test_login_completes_with_a_lowercase_recovery_code(): void
    {
        $user = $this->twoFactorUser();
        $codes = TwoFactor::generateRecoveryCodes(2);
        $user->two_factor_recovery_codes = TwoFactor::hashRecoveryCodes($codes);
        $user->save();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => strtolower($codes[0])])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Settings → Profile avatar picker (PATCH /admin/me/avatar): an http(s)
     * preset URL or an uploaded PNG data URL persists on the caller's row and
     * shows up in the same /admin/me payload the Header renders.
     */
    public function test_avatar_update_accepts_preset_url_and_data_url(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $this->actingAs($user);

        $url = 'https://api.dicebear.com/7.x/pixel-art/svg?seed=Admin&backgroundColor=2563eb';
        $this->stateful()
            ->patchJson('/api/admin/me/avatar', ['avatar_url' => $url])
            ->assertOk()
            ->assertJsonPath('message', 'Avatar saved.')
            ->assertJsonPath('user.avatar_url', $url);

        $this->assertSame($url, $user->fresh()->avatar_url);

        $dataUrl = 'data:image/png;base64,'.base64_encode('fake-png-bytes');
        $this->stateful()
            ->patchJson('/api/admin/me/avatar', ['avatar_url' => $dataUrl])
            ->assertOk()
            ->assertJsonPath('user.avatar_url', $dataUrl);

        $this->stateful()
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', $dataUrl);
    }

    /**
     * The avatar column must not become a file dump: an oversized data URL is
     * rejected (422) without touching the stored value.
     */
    public function test_avatar_update_rejects_oversized_payloads(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $this->actingAs($user);

        $this->stateful()
            ->patchJson('/api/admin/me/avatar', ['avatar_url' => 'data:image/png;base64,'.str_repeat('A', 1024 * 100)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar_url');

        $this->assertNull($user->fresh()->avatar_url);
    }

    /**
     * …and it must not become an XSS/status-page sink either: javascript:,
     * plain text, and non-image data URLs are rejected (422).
     */
    public function test_avatar_update_rejects_non_image_urls(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $this->actingAs($user);

        foreach (['javascript:alert(1)', 'not a url', 'data:text/plain;base64,aGk='] as $bad) {
            $this->stateful()
                ->patchJson('/api/admin/me/avatar', ['avatar_url' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('avatar_url');
        }

        $this->assertNull($user->fresh()->avatar_url);
    }

    /**
     * Self-service means the caller's own row only, and only while the account
     * may use the panel: a disabled account and a de-roled session are refused
     * (and the stored avatar is untouched), exactly like /admin/me.
     */
    public function test_avatar_update_refuses_ineligible_callers(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $this->actingAs($user);

        $user->is_active = false;
        $user->save();

        $this->stateful()
            ->patchJson('/api/admin/me/avatar', ['avatar_url' => 'https://example.com/a.svg'])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);

        $user->is_active = true;
        $user->role = 'student';
        $user->save();

        $this->stateful()
            ->patchJson('/api/admin/me/avatar', ['avatar_url' => 'https://example.com/a.svg'])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'You are not authorized to access the admin panel.']);

        $this->assertNull($user->fresh()->avatar_url);
    }

    /**
     * Clearing sends null (or an empty string); the row goes back to null so
     * the header falls back to initials/DiceBear like a fresh account.
     */
    public function test_avatar_update_can_be_cleared(): void
    {
        $user = $this->makeUser(['role' => 'admin', 'avatar_url' => 'https://example.com/old.svg']);
        $this->actingAs($user);

        $this->stateful()
            ->patchJson('/api/admin/me/avatar', ['avatar_url' => null])
            ->assertOk()
            ->assertJsonPath('message', 'Avatar cleared.')
            ->assertJsonPath('user.avatar_url', null);

        $this->assertNull($user->fresh()->avatar_url);

        $user->avatar_url = 'https://example.com/old.svg';
        $user->save();

        $this->stateful()
            ->patchJson('/api/admin/me/avatar', ['avatar_url' => '  '])
            ->assertOk()
            ->assertJsonPath('message', 'Avatar cleared.');

        $this->assertNull($user->fresh()->avatar_url);
    }

    /**
     * Step 1 accepts the password, but the session is only minted on step 2.
     * A role change in between (AdminUserController::updateRole) must therefore
     * be re-checked there, or the handshake would hand a panel session to an
     * account that is no longer panel staff.
     */
    public function test_two_factor_step_refuses_an_account_whose_role_left_the_panel(): void
    {
        $user = $this->twoFactorUser();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('requires_two_factor', true);

        $user->role = 'student';
        $user->save();

        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => $code])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Forbidden']);

        $this->assertGuest();
        $this->assertSame(0, $user->tokens()->count());

        // The pending marker was dropped with it, so retrying cannot slip past.
        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => $code])
            ->assertStatus(401);
    }

    /**
     * The panel signs in with the session cookie and never mints an API token,
     * so repeated sign-ins leave no live bearer credential behind at all.
     */
    public function test_repeated_sign_ins_mint_no_session_token(): void
    {
        $user = $this->makeUser(['role' => 'admin']);

        for ($i = 0; $i < 3; $i++) {
            $this->stateful()
                ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
                ->assertOk()
                ->assertJsonStructure(['user', 'two_factor']);
        }

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_two_factor_handshake_expires_after_the_ttl(): void
    {
        $user = $this->twoFactorUser();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        // Backdate the pending marker past the 5-minute TTL.
        $this->travelTo(now()->addMinutes(6));

        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->stateful()
            ->postJson('/api/admin/login/2fa', ['code' => $code])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Your sign-in has expired. Please sign in again.');
    }

    public function test_enrollment_prepare_confirm_and_disable_round_trip(): void
    {
        // Disabling is only allowed when the requirement is off (it defaults
        // on); this test exercises the enrollment/disable mechanics.
        $this->setTwoFactorRequired(false);

        $user = $this->makeUser(['role' => 'admin']);
        $this->actingAs($user);

        // Prepare needs the real password.
        $this->stateful()
            ->postJson('/api/admin/2fa/prepare', ['password' => 'wrong'])
            ->assertStatus(422);

        $prepared = $this->stateful()
            ->postJson('/api/admin/2fa/prepare', ['password' => self::PASSWORD])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('secret', $prepared);
        $this->assertStringStartsWith('otpauth://totp/', $prepared['otpauth_uri']);

        // Confirm with a live code produced from the prepared secret.
        $code = app(Google2FA::class)->getCurrentOtp($prepared['secret']);
        $confirmed = $this->stateful()
            ->postJson('/api/admin/2fa/confirm', ['code' => $code])
            ->assertOk()
            ->json();

        $this->assertIsArray($confirmed['recovery_codes']);
        $this->assertNotEmpty($confirmed['recovery_codes']);

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());

        // Disable requires password + a live code, then clears everything.
        $this->stateful()
            ->postJson('/api/admin/2fa/disable', ['password' => self::PASSWORD, 'code' => '000000'])
            ->assertStatus(422);

        $currentCode = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
        $this->stateful()
            ->postJson('/api/admin/2fa/disable', ['password' => self::PASSWORD, 'code' => $currentCode])
            ->assertOk();

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    /**
     * Disabling 2FA accepts a one-time recovery code as well as a live TOTP, so
     * the code-length cap must clear an 11-character code (regression: a max of
     * 10 rejected every recovery code with a 422 before it was ever verified).
     */
    public function test_two_factor_can_be_disabled_with_a_recovery_code(): void
    {
        $this->setTwoFactorRequired(false);

        $user = $this->twoFactorUser();
        $codes = TwoFactor::generateRecoveryCodes(2);
        $user->two_factor_recovery_codes = TwoFactor::hashRecoveryCodes($codes);
        $user->save();

        $this->actingAs($user);

        $this->stateful()
            ->postJson('/api/admin/2fa/disable', ['password' => self::PASSWORD, 'code' => $codes[0]])
            ->assertOk();

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertSame([], $user->two_factor_recovery_codes);
    }

    public function test_enforcement_blocks_unenrolled_staff_from_panel_routes(): void
    {
        $this->setTwoFactorRequired(true);

        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $this->stateful()
            ->getJson('/api/admin/candidates')
            ->assertStatus(403)
            ->assertJsonPath('two_factor_required', true);

        $this->stateful()
            ->getJson('/api/admin/dashboard-overview')
            ->assertStatus(403);
    }

    public function test_enforcement_lets_enrolled_staff_through(): void
    {
        $this->setTwoFactorRequired(true);

        $admin = $this->twoFactorUser();
        $this->actingAs($admin);

        $this->stateful()
            ->getJson('/api/admin/dashboard-overview')
            ->assertOk();
    }

    public function test_me_reports_two_factor_state(): void
    {
        $user = $this->twoFactorUser();
        $this->actingAs($user);

        $this->stateful()
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('two_factor.enabled', true)
            ->assertJsonPath('two_factor.required', true)
            // Raw columns must never reach the client; the React app reads the
            // dedicated `two_factor` envelope instead.
            ->assertJsonMissingPath('user.two_factor_secret')
            ->assertJsonMissingPath('user.two_factor_enabled')
            ->assertJsonMissingPath('user.two_factor_recovery_codes')
            ->assertJsonMissingPath('user.password');
    }

    /**
     * A demoted account still holds a valid session cookie, but it is no longer
     * a panel session. /me must stop confirming "signed in" — it used to answer
     * 200 with the stale role, so the SPA kept rendering a panel whose every
     * request then 403'd instead of bouncing back to the login screen.
     */
    public function test_me_revokes_a_session_that_is_no_longer_panel_staff(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $this->actingAs($user);

        $this->stateful()->getJson('/api/admin/me')->assertOk();

        // AdminUserController::updateRole moves them off the panel mid-session.
        $user->role = 'student';
        $user->save();

        $this->stateful()
            ->getJson('/api/admin/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Your panel access has been revoked. Please sign in again.']);

        // Every panel route refuses the stale session as well.
        $this->stateful()
            ->getJson('/api/admin/candidates')
            ->assertStatus(403);
    }

    public function test_raw_two_factor_columns_are_omitted_at_the_login_step(): void
    {
        $user = $this->twoFactorUser();

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('requires_two_factor', true)
            ->assertJsonPath('two_factor.enabled', true)
            ->assertJsonMissingPath('user.two_factor_secret')
            ->assertJsonMissingPath('user.two_factor_enabled')
            ->assertJsonMissingPath('user.two_factor_recovery_codes')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token')
            ->assertJsonMissingPath('user.failed_login_attempts');
    }

    private function setTwoFactorRequired(bool $required): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.twoFactorRequired'],
            ['value' => $required ? 'true' : 'false', 'updated_at' => now()],
        );
    }

    /** A panel user with 2FA already enabled (secret + a few codes stored). */
    private function twoFactorUser(): User
    {
        $user = $this->makeUser(['role' => 'admin']);
        $user->two_factor_secret = TwoFactor::generateSecret();
        $user->two_factor_enabled = true;
        $user->two_factor_recovery_codes = TwoFactor::hashRecoveryCodes(TwoFactor::generateRecoveryCodes(5));
        $user->save();

        return $user;
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

    /** Minimal schema for the flows exercised here (mirrors the real columns). */
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
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_recovery_codes')->nullable();
            $table->text('avatar_url')->nullable();
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

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // dashboard-overview counts approved candidates, so the table exists.
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('party_list')->nullable();
            $table->string('position_key');
            $table->string('approval_status')->default('pending');
            $table->timestamps();
        });

        // dashboard-overview also counts the caller's unread notifications.
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 50)->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        // …and its published-announcements panel.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'created_at']);
        });
    }
}
