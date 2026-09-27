<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Regression coverage for the is_active gate on both sign-in flows
 * (AdminAuthController::login and StudentAuthController::login) and for the
 * token revocation performed by AdminUserController::updateStatus.
 *
 * These tests build an isolated in-memory SQLite schema instead of using
 * RefreshDatabase: the migration set contains MySQL-only ALTER TABLE
 * statements, and the default connection of this checkout points at a live
 * MySQL database, so migrating would either fail or touch real data.
 */
class DisabledUserLoginTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

    private const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite is the suite's configured database (phpunit.xml). PHP builds
        // without pdo_sqlite (this dev box ships only pdo_mysql) skip the
        // DB-backed cases rather than assert against the live MySQL database.
        // This checkout may ship a cached config where app.env is not
        // "testing"; declare the test environment explicitly so Sanctum's
        // stateful (session) middleware skips CSRF validation as usual.
        $this->app->detectEnvironment(fn () => 'testing');

        // Drop any connection resolved during boot before repointing the
        // default connection at the private in-memory database.
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

    public function test_disabled_admin_cannot_sign_in(): void
    {
        $admin = $this->makeUser(['role' => 'admin', 'is_active' => false]);

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);

        // Neither a Sanctum token nor a session may be created.
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertGuest();
    }

    public function test_disabled_student_cannot_sign_in(): void
    {
        $student = $this->makeUser(['role' => 'student', 'is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);

        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_enabled_admin_can_still_sign_in(): void
    {
        $admin = $this->makeUser(['role' => 'admin', 'is_active' => true]);

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonStructure(['user', 'two_factor']);

        // The panel is cookie-session only; no API token is minted.
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_enabled_student_can_still_sign_in(): void
    {
        $student = $this->makeUser(['role' => 'student', 'is_active' => true]);

        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonStructure(['token', 'student']);

        $this->assertSame(1, $student->tokens()->count());
    }

    public function test_admin_me_rejects_an_account_disabled_mid_session(): void
    {
        $admin = $this->makeUser(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($admin)
            ->getJson('/api/admin/me')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);
    }

    public function test_student_me_rejects_an_account_disabled_mid_session(): void
    {
        $student = $this->makeUser(['role' => 'student', 'is_active' => false]);
        $token = $student->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);
    }

    public function test_disabling_an_account_revokes_its_sanctum_tokens(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);
        $token = $student->createToken('mobile')->plainTextToken;

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$student->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $student->tokens()->count());

        // Drop the acting-as administrator so the request below resolves
        // through the bearer token alone.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    /**
     * A disabled panel account is blocked from permission-gated routes, not
     * just the login and /me endpoints. updateStatus() only revokes bearer
     * tokens, so the stateful web session would otherwise keep an admin panel
     * open until the cookie expired (the EnsurePermission is_active gate).
     */
    public function test_disabled_admin_is_blocked_from_panel_routes(): void
    {
        $admin = $this->makeUser(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($admin)
            ->getJson('/api/admin/candidates')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);

        $this->actingAs($admin)
            ->postJson('/api/admin/results/finalize')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);
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
    }
}
