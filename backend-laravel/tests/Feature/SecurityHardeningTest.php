<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security hardening follow-ups:
 *
 *   1. The Security settings an admin saves must actually govern every
 *      password-setting path — registration AND change-password / reset — not
 *      just registration. Before this, change/reset used Password::defaults()
 *      so a raised policy was silently ignored.
 *   2. Sensitive actions leave an audit_logs trail.
 *   3. `loginBackoffEnabled` is a real kill switch, not a write-only setting.
 */
class SecurityHardeningTest extends TestCase
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

    public function test_change_password_enforces_a_raised_settings_policy(): void
    {
        $this->setSecurity('passwordMinLength', '12');
        $this->setSecurity('passwordRequireSpecial', 'true');

        $student = $this->makeUser();

        // Meets the old default (8+, upper, number) but violates the raised
        // policy: 12+ characters AND a special character.
        $this->withToken($this->tokenFor($student))
            ->postJson('/api/auth/password/change', [
                'current_password' => self::PASSWORD,
                'password' => 'longerUPPER123',
                'password_confirmation' => 'longerUPPER123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->withToken($this->tokenFor($student))
            ->postJson('/api/auth/password/change', [
                'current_password' => self::PASSWORD,
                'password' => 'longerUPPER12!',
                'password_confirmation' => 'longerUPPER12!',
            ])
            ->assertOk();
    }

    public function test_change_password_writes_an_audit_row(): void
    {
        $student = $this->makeUser();

        $this->withToken($this->tokenFor($student))
            ->postJson('/api/auth/password/change', [
                'current_password' => self::PASSWORD,
                'password' => 'Brand-New-Pass-99',
                'password_confirmation' => 'Brand-New-Pass-99',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'password_changed',
            'entity_type' => 'user',
        ]);
    }

    public function test_login_backoff_can_be_disabled_via_settings(): void
    {
        $this->setSecurity('loginBackoffEnabled', 'false');
        $this->setSecurity('maxLoginAttempts', '3');

        $student = $this->makeUser();

        for ($i = 0; $i < 8; $i++) {
            $student->recordFailedLogin();
        }

        $student->refresh();
        $this->assertNull($student->locked_until);
        $this->assertFalse($student->isLocked());
    }

    public function test_login_backoff_still_locks_by_default(): void
    {
        $student = $this->makeUser();

        for ($i = 0; $i < User::MAX_LOGIN_ATTEMPTS; $i++) {
            $student->recordFailedLogin();
        }

        $student->refresh();
        $this->assertNotNull($student->locked_until);
        $this->assertTrue($student->isLocked());
    }

    private function setSecurity(string $key, string $value): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.'.$key],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('mobile')->plainTextToken;
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test Student',
            'email' => Str::uuid().'@example.test',
            'password' => self::PASSWORD,
            'role' => 'student',
            'is_active' => true,
            'must_change_password' => false,
        ], $attributes));
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

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('action', 100);
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }
}
