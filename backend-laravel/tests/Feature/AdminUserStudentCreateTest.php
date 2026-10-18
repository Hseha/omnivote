<?php

namespace Tests\Feature;

use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\RegistrarCode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Manual single-student provisioning (User Management → "Add Student"):
 *
 *   POST /api/admin/users/students — create one student account for a learner
 *        who has none yet, plus the registrar eligibility row and one-time
 *        activation code a bulk-imported account would have received.
 *
 * Guardrails: admin-only (permission:manage_accounts), duplicate student IDs
 * are rejected on both the account table and the eligibility feed, and the
 * same integrity gates the CSV import applies (ID format, numeric blocks,
 * college/course catalog) apply here too.
 */
class AdminUserStudentCreateTest extends TestCase
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

    public function test_admin_can_create_a_single_student_account(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'name' => 'Juan Dela Cruz',
            'year_level' => '2',
            'block_number' => 'Block 3',
            'department' => 'CCS',
            'course' => 'BSIT',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.student_id', '2024-0042')
            ->assertJsonPath('user.department', 'College of Computer Studies')
            ->assertJsonPath('user.course', 'Bachelor of Science in Information Technology')
            ->assertJsonPath('user.is_active', true)
            ->assertJsonStructure(['temporary_password', 'activation_code']);

        // The account mirrors bulk-import provisioning: random temp password,
        // forced password change on first sign-in, and the login handle the
        // registrar import would have derived from the name.
        $user = User::where('student_id', '2024-0042')->firstOrFail();
        $this->assertSame('juan.dela.cruz', $user->email);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertTrue((bool) $user->is_active);
        $this->assertFalse((bool) $user->has_voted);
        $this->assertSame('2', $user->year_level);
        $this->assertSame('3', $user->block_number);
        $this->assertNotSame($response->json('temporary_password'), $user->password);

        // The eligibility feed row exists too (activation-code recovery path),
        // stores only the bcrypt hash, and matches the returned plaintext once.
        $import = RegistrarImport::where('student_id', '2024-0042')->firstOrFail();
        $this->assertSame('student', $import->role);
        $this->assertSame('juan.dela.cruz', $import->email);
        $this->assertFalse((bool) $import->needs_review);
        $this->assertNotSame($response->json('activation_code'), $import->activation_code_hash);
        $this->assertTrue(RegistrarCode::matches($import, $response->json('activation_code')));
    }

    public function test_created_student_can_sign_in_with_the_temporary_password(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $create = $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0077',
            'name' => 'Maria Santos',
            'year_level' => '1',
            'block_number' => '1',
            'department' => 'College of Arts and Sciences',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'maria.santos',
            'password' => $create->json('temporary_password'),
        ])
            ->assertOk()
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('student.role', 'student');
    }

    public function test_duplicate_student_id_on_an_existing_account_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->makeUser(['role' => 'student', 'student_id' => '2024-0042']);

        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'name' => 'Impostor Duplicate',
            'year_level' => '2',
            'block_number' => '1',
            'department' => 'College of Computer Studies',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', "A user account with Student ID '2024-0042' already exists. Open that account in the list instead of creating a second one.");

        // Nothing was created anywhere.
        $this->assertSame(2, User::count());
        $this->assertSame(0, RegistrarImport::count());
    }

    public function test_duplicate_student_id_only_in_the_eligibility_feed_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        RegistrarImport::create([
            'student_id' => '2024-0042',
            'full_name' => 'Existing Feed Row',
            'email' => 'existing.feed.row',
            'role' => 'student',
            'year_level' => '1',
            'block_number' => '1',
        ]);

        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'name' => 'Second Attempt',
            'year_level' => '3',
            'block_number' => '2',
            'department' => 'College of Computer Studies',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', "Student ID '2024-0042' is already present in the registrar eligibility feed but has no account. Re-run the registrar import for this student, or correct the ID.");

        $this->assertSame(0, User::where('student_id', '2024-0042')->count());
    }

    public function test_malformed_student_id_and_alphabetic_blocks_are_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $base = [
            'name' => 'Maria Santos',
            'year_level' => '1',
            'block_number' => '1',
            'department' => 'College of Computer Studies',
        ];

        // Same gate the CSV import applies: spreadsheet exponential damage.
        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2.024E+11',
        ] + $base)
            ->assertStatus(422)
            ->assertJsonPath('message', "Student ID '2.024E+11' looks like spreadsheet exponential notation (e.g. 2.024E+11) — leading digits were lost in Excel. Re-export the ID column as text.");

        // Block numbers must be numeric, same wording as the import.
        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'block_number' => 'Block A',
        ] + $base)
            ->assertStatus(422)
            ->assertJsonPath('message', "Block number 'Block A' is not numeric — alphabetic block designations are not allowed. Use a numeric value such as 1, 2, or Block 1.");

        $this->assertSame(0, User::where('role', 'student')->where('student_id', '!=', null)->count());
        $this->assertSame(0, RegistrarImport::count());
    }

    public function test_unknown_department_and_foreign_course_are_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'name' => 'Maria Santos',
            'year_level' => '1',
            'block_number' => '1',
            'department' => 'College of Nonexistent Studies',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', "Department 'College of Nonexistent Studies' is not in the current department list.");

        // A course must belong to the chosen college.
        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'name' => 'Maria Santos',
            'year_level' => '1',
            'block_number' => '1',
            'department' => 'College of Computer Studies',
            'course' => 'Bachelor of Science in Criminology',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', "Course 'Bachelor of Science in Criminology' is not offered by College of Computer Studies.");

        $this->assertSame(0, User::where('role', 'student')->count());
    }

    public function test_login_handle_gets_a_suffix_on_collision_with_an_existing_account(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->makeUser(['role' => 'student', 'email' => 'juan.dela.cruz']);

        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'name' => 'Juan Dela Cruz',
            'year_level' => '2',
            'block_number' => '3',
            'department' => 'College of Computer Studies',
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'juan.dela.cruz2');
    }

    /**
     * End-to-end guarantee: a student created through User Management →
     * "Add Student" can apply for candidacy exactly like a seeded/self-
     * registered account. The apply gate checks account state, electorate
     * scope (voterIsInScope) and the party seat cap — this pins the whole
     * chain so a future change to the provisioning form (e.g. making
     * year_level/department optional again) cannot silently lock
     * admin-created students out of elections.
     */
    public function test_admin_created_student_can_apply_for_candidacy_during_registration(): void
    {
        $this->openRegistration();

        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0099',
            'name' => 'Liza Reyes',
            'year_level' => '4',
            'block_number' => '1',
            'department' => 'College of Computer Studies',
        ])->assertCreated();

        // The temporary credential blocks every student route behind the
        // passwordChanged middleware until the forced first-sign-in change;
        // complete it the way the app does so the candidacy routes are
        // reachable (the sign-in + change flows are covered elsewhere).
        $student = User::where('student_id', '2024-0099')->firstOrFail();
        $student->forceFill(['must_change_password' => false])->save();

        $presidentId = (int) DB::table('positions')->where('slug', 'president')->value('id');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('candidate.approval_status', 'pending')
            ->assertJsonPath('candidate.position_id', $presidentId)
            ->assertJsonPath('candidate.position.slug', 'president');

        $this->assertDatabaseHas('candidates', [
            'user_id' => $student->id,
            'position_id' => $presidentId,
            'party_name' => 'ASLE',
            'approval_status' => 'pending',
        ]);
    }

    public function test_a_teacher_cannot_create_student_accounts(): void
    {
        $teacher = $this->makeUser(['role' => 'teacher']);

        $this->actingAs($teacher)->postJson('/api/admin/users/students', [
            'student_id' => '2024-0042',
            'name' => 'Maria Santos',
            'year_level' => '1',
            'block_number' => '1',
            'department' => 'College of Computer Studies',
        ])->assertForbidden();

        $this->assertSame(0, User::where('student_id', '2024-0042')->count());
        $this->assertSame(0, RegistrarImport::count());
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

    /** Registration window so POST /candidate/apply passes its phase gate. */
    private function openRegistration(): void
    {
        $window = [
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->addDay(),
            'voting_opens_at' => now()->addDays(2),
            'voting_closes_at' => now()->addDays(3),
        ];

        foreach ($window as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
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

        // The eligibility feed columns RegistrarImport + RegistrarCode touch.
        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
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

        // Candidacy-apply fixtures: the apply gate reads positions (scope +
        // seat cap), parties (canonical-name normalization), candidates
        // (duplicate/seat reservation) and the election timeline.
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier')->default('national');
            $table->string('scope_type')->nullable();
            $table->string('scope_value')->nullable();
            $table->string('scope_type_2')->nullable();
            $table->string('scope_value_2')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('positions')->insert([
            'slug' => 'president',
            'label' => 'President',
            'tier' => 'national',
            'seat_count' => 1,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('parties')->insert([
            ['name' => 'ASLE', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'SVEA', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref', 36)->nullable()->unique();
            $table->string('slogan')->nullable();
            $table->string('party_name')->nullable();
            $table->text('platform_statement')->nullable();
            $table->text('platform_points')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('approval_status')->default('pending');
            $table->string('rejection_reason', 1000)->nullable();
            $table->unsignedInteger('rejection_count')->default(0);
            $table->timestamp('reapply_blocked_until')->nullable();
            $table->timestamps();
        });

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        foreach (['registration', 'voting_open', 'voting_closed', 'registration_closed'] as $phase) {
            DB::table('phases')->insert([
                'name' => $phase,
                'description' => $phase,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('election_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}
