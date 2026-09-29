<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\DepartmentCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Department management (add + rename) via the admin User Management screen.
 *
 * Departments live in the `departments` table (pre-seeded with the college
 * catalog); admins may add new colleges and rename existing ones. Renaming one
 * rewrites every `users.department` / `registrar_imports.department` cell that
 * matches, so filters and account forms never drift from the renamed value.
 */
class DepartmentManagementTest extends TestCase
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

        $this->makeUser(['role' => 'admin', 'email' => 'admin@omnivote.edu']);
    }

    public function test_admin_adds_a_department(): void
    {
        // The seeded catalog already owns the five Colleges, so a genuinely
        // new department (e.g. an institute) is what add is for.
        $response = $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->stateful()
            ->postJson('/api/admin/users/departments', ['name' => 'Engineering']);

        $response->assertStatus(201)
            ->assertJsonPath('message', "Department 'Engineering' added.");

        $this->assertContains('Engineering', $response->json('departments'));
        $this->assertSame(1, Department::where('name', 'Engineering')->count());
    }

    public function test_adding_duplicate_department_is_rejected(): void
    {
        $this->postDepartment('Engineering');

        // Case variants are the same department — never two rows.
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->stateful()
            ->postJson('/api/admin/users/departments', ['name' => 'engineering'])
            ->assertStatus(422);

        $this->assertSame(1, Department::where('name', 'Engineering')->count());
    }

    public function test_admin_renames_department_everywhere(): void
    {
        // Accounts/feed carry the canonical college name (the migration and the
        // import already normalize legacy codes), while the rename request may
        // still arrive as the legacy code "CCS".
        $ccsStudents = [
            $this->makeUser(['role' => 'student', 'email' => 'a@school.edu', 'department' => 'College of Computer Studies']),
            $this->makeUser(['role' => 'student', 'email' => 'b@school.edu', 'department' => 'College of Computer Studies']),
        ];
        RegistrarImport::create([
            'student_id' => '2024-0001',
            'full_name' => 'Ana Flores',
            'email' => 'ana@school.edu',
            'role' => 'student',
            'department' => 'College of Computer Studies',
        ]);

        $response = $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->stateful()
            ->patchJson('/api/admin/users/departments', ['from' => 'CCS', 'to' => 'Computer Science']);

        $response->assertStatus(200)
            ->assertJsonPath('message', "Department 'College of Computer Studies' renamed to 'Computer Science'.")
            ->assertJsonPath('affected_users', 2)
            ->assertJsonPath('affected_registrar_rows', 1);

        $departments = $response->json('departments');
        $this->assertContains('Computer Science', $departments);
        $this->assertNotContains('College of Computer Studies', $departments);

        $this->assertSame('Computer Science', $ccsStudents[0]->fresh()->department);
        $this->assertSame('Computer Science', $ccsStudents[1]->fresh()->department);
        $this->assertSame('Computer Science', RegistrarImport::where('student_id', '2024-0001')->first()->department);

        // The table row itself is renamed — no "CCS" left anywhere.
        $this->assertSame(0, Department::where('name', 'College of Computer Studies')->count());
        $this->assertSame(1, Department::where('name', 'Computer Science')->count());
    }

    public function test_rename_to_the_same_name_is_rejected(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->stateful()
            ->patchJson('/api/admin/users/departments', ['from' => 'CCS', 'to' => 'ccs'])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function postDepartment(string $name)
    {
        return $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->stateful()
            ->postJson('/api/admin/users/departments', ['name' => $name]);
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
            'password' => 'secret-password',
            'role' => 'student',
            'is_active' => true,
            'has_voted' => false,
            'needs_review' => false,
        ], $attributes));
    }

    /** Minimal schema for the models exercised by these flows. */
    private function createSchema(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Mirror the migration: Colleges are pre-seeded, so a catalog college
        // (e.g. "CCS") already exists while genuinely new ones are addable.
        DB::table('departments')->insert(array_map(
            fn (array $c, int $i) => [
                'name' => $c['name'],
                'code' => $c['code'],
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            DepartmentCatalog::COLLEGES,
            array_keys(DepartmentCatalog::COLLEGES)
        ));

        Schema::create('department_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('alias')->unique();
            $table->timestamps();
        });

        foreach (DepartmentCatalog::departmentAliases() as $alias => $departmentName) {
            DB::table('department_aliases')->insert([
                'department_id' => DB::table('departments')->where('name', $departmentName)->value('id'),
                'alias' => strtolower($alias),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->default('student');
            $table->string('year_level')->nullable();
            $table->string('block_number')->nullable();
            $table->string('department', 100)->nullable();
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
            $table->string('email')->nullable();
            $table->string('role')->default('student');
            $table->string('year_level')->nullable();
            $table->string('block_number')->nullable();
            $table->string('department')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->string('activation_code_hash')->nullable();
            $table->timestamp('activation_code_issued_at')->nullable();
            $table->timestamp('activation_code_used_at')->nullable();
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
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }
}