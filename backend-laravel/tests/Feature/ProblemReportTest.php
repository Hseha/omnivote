<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * POST /api/reports — student-submitted problem / bug report.
 *
 * Writes an in-app notification to every active staff member so the root
 * cause can be investigated.
 */
class ProblemReportTest extends TestCase
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

    public function test_a_student_can_submit_a_problem_report(): void
    {
        $student = $this->makeUser();
        $admin = $this->makeUser(['role' => 'admin']);
        $teacher = $this->makeUser(['role' => 'teacher']);
        $inactive = $this->makeUser(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/reports', [
                'screen' => 'CandidacyApplyScreen',
                'action' => 'submit',
                'error_code' => 'seat_taken',
                'error_message' => 'The ASLE seat for President is already taken.',
                'context' => ['party' => 'ASLE', 'tier' => 'national', 'position' => '3'],
                'description' => 'I tried to submit but got a seat-taken error.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Report received. Thank you.');

        $this->assertCount(2, UserNotification::query()->where('type', 'danger')->get());
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $admin->id)
            ->where('title', 'Problem report: CandidacyApplyScreen / submit')
            ->where('type', 'danger')
            ->exists());
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $teacher->id)
            ->where('title', 'Problem report: CandidacyApplyScreen / submit')
            ->exists());

        $this->assertFalse(UserNotification::query()
            ->where('user_id', $inactive->id)
            ->exists());
    }

    public function test_a_problem_report_requires_authentication(): void
    {
        $this->postJson('/api/reports', [
            'screen' => 'CandidacyApplyScreen',
            'action' => 'submit',
            'error_code' => 'unknown',
            'error_message' => 'Something broke.',
        ])->assertUnauthorized();
    }

    public function test_a_problem_report_validates_required_fields(): void
    {
        $student = $this->makeUser();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/reports', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'screen',
                'action',
                'error_code',
                'error_message',
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
            'year_level' => '11',
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

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 50)->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }
}
