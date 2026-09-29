<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\DepartmentCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Registrar imports record the student's program.
 *
 * The Course column is optional. A full program name or its short code ("BSIT")
 * both resolve to the canonical name from the `courses` table, kept by the admin
 * Courses manager. A genuine miss is stored as typed and NEVER blocks
 * provisioning, but the row is flagged for review so the registrar can see it.
 */
class RegistrarImportCourseTest extends TestCase
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

    public function test_exact_course_is_stored_on_account_and_feed_row(): void
    {
        $res = $this->importWithCourses([
            ['2024-0001', 'John Michael Valles', '1', 'College of Computer Studies', '2', 'Bachelor of Science in Information Technology'],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 1)
            ->assertJsonPath('summary.flagged_for_review', 0)
            ->assertJsonPath('temporary_credentials.0.course', 'Bachelor of Science in Information Technology');

        $user = User::where('student_id', '2024-0001')->firstOrFail();
        $this->assertSame('Bachelor of Science in Information Technology', $user->course);

        $feed = RegistrarImport::where('student_id', '2024-0001')->firstOrFail();
        $this->assertSame('Bachelor of Science in Information Technology', $feed->course);
    }

    public function test_course_code_imports_as_the_canonical_program_name(): void
    {
        $res = $this->importWithCourses([
            ['2024-0005', 'Rina Bautista', '2', 'College of Computer Studies', '1', 'BSIT'],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 1)
            ->assertJsonPath('summary.flagged_for_review', 0)
            ->assertJsonPath('temporary_credentials.0.course', 'Bachelor of Science in Information Technology');

        $this->assertSame(
            'Bachelor of Science in Information Technology',
            User::where('student_id', '2024-0005')->value('course'),
        );
    }

    public function test_course_code_is_case_and_spacing_insensitive(): void
    {
        $res = $this->importWithCourses([
            ['2024-0006', 'Owen del Cruz', '1', 'CCS', '2', '  bSiT '],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('summary.flagged_for_review', 0)
            ->assertJsonPath('temporary_credentials.0.course', 'Bachelor of Science in Information Technology');
        $this->assertSame(
            'College of Computer Studies',
            User::where('student_id', '2024-0006')->value('department'),
        );
    }

    public function test_legacy_course_spelling_imports_as_the_canonical_name(): void
    {
        $res = $this->importWithCourses([
            ['2024-0007', 'Mia Lacson', '3', 'College of Computer Studies', '1', 'Information Technology'],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('summary.flagged_for_review', 0)
            ->assertJsonPath('temporary_credentials.0.course', 'Bachelor of Science in Information Technology');
    }

    public function test_course_code_from_another_college_is_not_attached(): void
    {
        // BSIT belongs to Computer Studies, not Criminology. Storing the full
        // Information Technology name on a Criminology student would let them
        // vote in the wrong course-scoped race.
        $res = $this->importWithCourses([
            ['2024-0008', 'Noel Ramos', '2', 'College of Criminal Justice Education', '1', 'BSIT'],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 1)
            ->assertJsonPath('summary.courses_not_offered', 1)
            // Not a blocking review: the account is still created.
            ->assertJsonPath('summary.flagged_for_review', 0);

        $this->assertSame('BSIT', User::where('student_id', '2024-0008')->value('course'));
    }

    public function test_unmatched_course_still_provisions_the_account_but_is_reported(): void
    {
        $res = $this->importWithCourses([
            ['2024-0002', 'Bailey Santos', '2', 'College of Computer Studies', '1', 'BS Nautical'],
        ]);

        // The account is created: a bad course must never lock a student out,
        // which is why this is a warning and not a "flagged for review" row.
        $res->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 1)
            ->assertJsonPath('summary.flagged_for_review', 0)
            ->assertJsonPath('summary.skipped_incomplete', 0)
            // ...but the registrar is told, rather than it landing silently.
            ->assertJsonPath('summary.courses_not_offered', 1);

        $this->assertSame('BS Nautical', User::where('student_id', '2024-0002')->value('course'));

        $feed = RegistrarImport::where('student_id', '2024-0002')->firstOrFail();
        $this->assertSame('BS Nautical', $feed->course);
        $this->assertFalse((bool) $feed->needs_review);
        $this->assertStringContainsString(
            "Course 'BS Nautical' is not offered by College of Computer Studies",
            (string) $feed->review_reason,
        );
    }

    public function test_import_without_course_column_leaves_course_null(): void
    {
        $res = $this->importNoCourse([
            ['2024-0003', 'Casey Lim', '3', 'College of Computer Studies', '2'],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 1);

        $this->assertNull(User::where('student_id', '2024-0003')->value('course'));
        $this->assertNull(RegistrarImport::where('student_id', '2024-0003')->value('course'));
    }

    public function test_blanks_do_not_wipe_an_existing_course_on_reimport(): void
    {
        $this->importWithCourses([
            ['2024-0004', 'Dana Ortiz', '1', 'College of Computer Studies', '1', 'Bachelor of Science in Information Technology'],
        ]);

        // Second upload without a course column: existing course must survive.
        $this->importNoCourse([
            ['2024-0004', 'Dana Ortiz', '1', 'College of Computer Studies', '1'],
        ]);

        $this->assertSame(
            'Bachelor of Science in Information Technology',
            User::where('student_id', '2024-0004')->value('course'),
        );
    }

    /* ------------------------------------------------------------------ */

    private function import(string $csv)
    {
        return $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->stateful()
            ->post('/api/admin/registrar/import', [
                'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
            ]);
    }

    private function importWithCourses(array $rows)
    {
        $header = ['Student ID', 'Student Name', 'Year Level', 'Department', 'Block Number', 'Course'];

        return $this->import($this->buildCsv($header, $rows));
    }

    private function importNoCourse(array $rows)
    {
        $header = ['Student ID', 'Student Name', 'Year Level', 'Department', 'Block Number'];

        return $this->import($this->buildCsv($header, $rows));
    }

    private function buildCsv(array $header, array $rows): string
    {
        $lines = array_map(
            fn (array $row) => implode(',', array_map(fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"', $row)),
            array_merge([$header], $rows)
        );

        return implode("\n", $lines)."\n";
    }

    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function createSchema(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

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

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        foreach (DepartmentCatalog::COURSES as $i => $course) {
            $deptId = DB::table('departments')->where('code', $course['college'])->value('id');
            DB::table('courses')->insert([
                'department_id' => $deptId,
                'name' => $course['name'],
                'code' => $course['code'],
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('department_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('alias')->unique();
            $table->timestamps();
        });

        Schema::create('course_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
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

        foreach (DepartmentCatalog::courseAliases() as $collegeCode => $aliases) {
            $deptId = DB::table('departments')->where('code', $collegeCode)->value('id');
            foreach ($aliases as $alias => $courseCode) {
                DB::table('course_aliases')->insert([
                    'course_id' => DB::table('courses')->where('department_id', $deptId)->where('code', $courseCode)->value('id'),
                    'alias' => strtolower($alias),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

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

        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
            'is_active' => true,
        ]);

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
            $table->string('course')->nullable();
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

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->text('data')->nullable();
            $table->boolean('read')->default(false);
            $table->timestamps();
        });
    }
}