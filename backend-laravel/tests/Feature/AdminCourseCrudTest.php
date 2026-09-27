<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin Courses/Programs manager — the per-college program list the registrar
 * import and account forms reference (single source of truth):
 *
 *   POST   /api/admin/users/courses  — add a program under a college
 *   PATCH  /api/admin/users/courses  — rename a program within a college
 *   DELETE /api/admin/users/courses  — remove a program from a college
 *
 * All three are gated by permission:manage_accounts (admin only).
 */
class AdminCourseCrudTest extends TestCase
{
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
        $this->allowNoTwoFactor();
    }

    public function test_admin_can_add_a_course_under_a_department(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/users/courses', [
                'department' => 'College of Computer Studies',
                'name' => 'Bachelor of Science in Nursing',
            ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Course \'Bachelor of Science in Nursing\' added under College of Computer Studies.');

        $this->assertSame(1, Course::where('name', 'Bachelor of Science in Nursing')->count());
        $dept = Department::where('name', 'College of Computer Studies')->firstOrFail();
        $this->assertSame(1, Course::where('department_id', $dept->id)->where('name', 'Bachelor of Science in Nursing')->count());
    }

    public function test_course_names_are_case_insensitively_unique_per_department(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/users/courses', [
                'department' => 'College of Computer Studies',
                'name' => 'Bachelor of Science in Information Technology',
            ])
            ->assertStatus(422);
    }

    public function test_same_course_name_is_allowed_under_another_department(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/users/courses', [
                'department' => 'College of Arts and Sciences',
                'name' => 'Bachelor of Science in Information Technology',
            ])
            ->assertStatus(201);
    }

    public function test_admin_can_rename_a_course_within_a_department(): void
    {
        $this->asAdmin()
            ->patchJson('/api/admin/users/courses', [
                'department' => 'College of Computer Studies',
                'from' => 'Bachelor of Science in Information Technology',
                'to' => 'Bachelor of Science in Computer Science',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Course \'Bachelor of Science in Information Technology\' renamed to \'Bachelor of Science in Computer Science\' under College of Computer Studies.');

        $this->assertSame(
            'Bachelor of Science in Computer Science',
            Course::where('department_id', $this->departmentId('College of Computer Studies'))->value('name'),
        );
    }

    public function test_renaming_to_an_existing_name_is_rejected(): void
    {
        $deptId = $this->departmentId('College of Computer Studies');
        Course::firstOrCreate(['department_id' => $deptId, 'name' => 'Bachelor of Science in Nursing']);

        $this->asAdmin()
            ->patchJson('/api/admin/users/courses', [
                'department' => 'College of Computer Studies',
                'from' => 'Bachelor of Science in Information Technology',
                'to' => 'Bachelor of Science in Nursing',
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_delete_a_course_from_a_department(): void
    {
        $this->asAdmin()
            ->deleteJson('/api/admin/users/courses?department=College+of+Computer+Studies&name=Bachelor+of+Science+in+Information+Technology')
            ->assertOk()
            ->assertJsonPath('message', 'Course \'Bachelor of Science in Information Technology\' removed from College of Computer Studies.');

        $this->assertSame(
            0,
            Course::where('department_id', $this->departmentId('College of Computer Studies'))->count(),
        );
    }

    public function test_unknown_department_is_rejected(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/users/courses', [
                'department' => 'Mars University',
                'name' => 'Rocket Science',
            ])
            ->assertStatus(422);
    }

    public function test_teachers_cannot_manage_courses(): void
    {
        $this->actingAs($this->makeUser(['email' => 'teacher@example.test', 'role' => 'teacher']), 'web')
            ->postJson('/api/admin/users/courses', [
                'department' => 'College of Computer Studies',
                'name' => 'Bachelor of Science in Nursing',
            ])
            ->assertStatus(403);

        $this->assertSame(1, Course::count());
    }

    /* ------------------------------------------------------------------ */

    private function asAdmin(): static
    {
        return $this->actingAs($this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']), 'web')
            ->withHeaders(['Origin' => 'http://localhost']);
    }

    private function allowNoTwoFactor(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.twoFactorRequired'],
            ['value' => 'false', 'updated_at' => now()],
        );
    }

    private function departmentId(string $name): int
    {
        return (int) Department::where('name', $name)->value('id');
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

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('departments')->insert([
            ['name' => 'College of Computer Studies', 'code' => 'CCS', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'College of Arts and Sciences', 'code' => 'CAS', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // CCS starts with one seeded program — the exact copy fight test for it.
        Course::create([
            'department_id' => $this->departmentId('College of Computer Studies'),
            'name' => 'Bachelor of Science in Information Technology',
            'sort_order' => 1,
        ]);

        Schema::create('election_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}