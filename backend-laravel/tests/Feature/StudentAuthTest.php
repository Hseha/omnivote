<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Student bearer-token auth flows for the Flutter client:
 *   POST /api/auth/login            — reports must_change_password
 *   POST /api/auth/password/change  — enforced first-login password rotation
 * and the role gate that keeps panel accounts off the student-only routes.
 */
class StudentAuthTest extends TestCase
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

    public function test_login_reports_the_must_change_password_flag(): void
    {
        $student = $this->makeUser(['must_change_password' => true]);

        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('must_change_password', true)
            ->assertJsonStructure(['token', 'student']);
    }

    public function test_student_can_change_their_password(): void
    {
        $student = $this->makeUser(['must_change_password' => true]);

        $token = $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->json('token');

        $this->withToken($token)
            ->postJson('/api/auth/password/change', [
                'current_password' => self::PASSWORD,
                'password' => 'new-pass-1234',
                'password_confirmation' => 'new-pass-1234',
            ])
            ->assertOk();

        $student->refresh();
        $this->assertFalse((bool) $student->must_change_password);
        $this->assertNotSame(self::PASSWORD, $student->password);
    }

    public function test_change_password_rejects_a_wrong_current_password(): void
    {
        $student = $this->makeUser();

        $this->actingAs($student)
            ->postJson('/api/auth/password/change', [
                'current_password' => 'not-the-password',
                'password' => 'new-pass-1234',
                'password_confirmation' => 'new-pass-1234',
            ])
            ->assertStatus(422);
    }

    public function test_change_password_enforces_the_shared_policy(): void
    {
        $student = $this->makeUser();

        $this->actingAs($student)
            ->postJson('/api/auth/password/change', [
                'current_password' => self::PASSWORD,
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_non_student_accounts_are_forbidden_from_student_routes(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/api/auth/me')
            ->assertStatus(403);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test Student',
            'email' => Str::uuid().'@example.test',
            'password' => self::PASSWORD,
            'role' => 'student',
            'is_active' => true,
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
    }
}
