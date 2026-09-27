<?php

namespace Tests\Feature;

use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\DepartmentCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Registrar CSV import dedup/conflict handling.
 *
 * Guarantees: an account is ONLY ever created when its student_id does not
 * already exist and every required cell (name, year level, department, block
 * number) is present, and its login email (derived from the student's name) is
 * made unique with a numeric suffix. Same-ID rows update the matching account.
 * Incomplete rows are stored in the feed, flagged for review, and never
 * provision an account nor touch an existing one. In-file duplicate IDs are
 * counted.
 */
class RegistrarImportTest extends TestCase
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

        // The admin carrying the registrar.import permission that also gets the
        // completion notification.
        $this->makeUser(['role' => 'admin', 'email' => 'admin@omnivote.edu']);
    }

    public function test_fresh_import_provisions_accounts_with_generated_logins(): void
    {
        // Departments must already exist before an import may assign them.
        $this->makeUser(['role' => 'teacher', 'department' => 'BSIT']);
        $this->makeUser(['role' => 'teacher', 'department' => 'BSED']);

        $response = $this->import(
            $this->csv([
                ['2024-0001', 'Ana Flores', '11', 'BSIT', 'Block 1'],
                ['2024-0002', 'Bryan Cruz', '12', 'BSED', 'Block 2'],
            ])
        );

        $response->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 2)
            ->assertJsonPath('summary.duplicates_within_file', 0)
            ->assertJsonPath('summary.email_conflict_skipped', 0)
            ->assertJsonPath('summary.skipped_incomplete', 0)
            ->assertJsonPath('summary.skipped_unknown_department', 0);

        $this->assertSame(2, User::where('role', 'student')->count());

        // Login email is derived from the student's name, never from the CSV.
        $ana = User::where('email', 'ana.flores')->firstOrFail();
        $this->assertSame('2024-0001', $ana->student_id);
        $this->assertSame('11', $ana->year_level);
        $this->assertSame('1', $ana->block_number);
        $this->assertSame('BSIT', $ana->department);
        $this->assertFalse((bool) $ana->has_voted);
        // The temporary password is a CSPRNG value handed back once for the
        // registrar to distribute — it must never be the student ID or any other
        // public registrar field, or anyone holding the class list could sign in
        // as the student and cast their ballot (security assessment C-1).
        $this->assertFalse(Hash::check('2024-0001', $ana->password), 'temp password must not be the student_id');

        $tempPasswords = collect($response->json('temporary_credentials'))
            ->pluck('temp_password')
            ->all();
        $this->assertCount(2, $tempPasswords);

        foreach ($tempPasswords as $tempPassword) {
            $this->assertMatchesRegularExpression(
                '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}$/',
                $tempPassword,
                'generated credential must satisfy the password policy and be >=12 chars',
            );
        }

        $anaTemp = collect($response->json('temporary_credentials'))
            ->firstWhere('student_id', '2024-0001')['temp_password'];
        $this->assertTrue(Hash::check($anaTemp, $ana->password), 'issued credential must verify against the stored hash');

        // Two accounts must never share a generated credential.
        $this->assertCount(2, array_unique($tempPasswords));

        $this->assertTrue((bool) $ana->must_change_password);

        $bryan = User::where('email', 'bryan.cruz')->firstOrFail();
        $this->assertSame('2024-0002', $bryan->student_id);
    }

    public function test_reimport_updates_same_account_without_creating_duplicate(): void
    {
        $this->makeUser([
            'role' => 'student',
            'student_id' => '2024-0001',
            'email' => 'ana.flores',
            'password' => '2024-0001',
        ]);
        $this->makeUser(['role' => 'teacher', 'department' => 'BSIT']);

        $response = $this->import(
            $this->csv([
                ['2024-0001', 'Ana Flores Updated', '11', 'BSIT', 'Block 1'],
            ])
        );

        $response->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 0)
            ->assertJsonPath('summary.email_conflict_skipped', 0)
            ->assertJsonPath('summary.skipped_unknown_department', 0);

        $this->assertSame(1, User::where('role', 'student')->count(), 're-import must not create a second account');

        $user = User::where('student_id', '2024-0001')->firstOrFail();
        $this->assertSame('Ana Flores Updated', $user->name);
        $this->assertSame('ana.flores', $user->email, 'existing login stays untouched');
        $this->assertTrue(Hash::check('2024-0001', $user->password), 'existing password stays untouched');
    }

    public function test_same_name_for_two_different_ids_gets_suffixed_logins(): void
    {
        $this->makeUser(['role' => 'teacher', 'department' => 'BSIT']);

        $response = $this->import(
            $this->csv([
                ['2024-0001', 'Ana Flores', '11', 'BSIT', 'Block 1'],
                ['2024-0002', 'Ana Flores', '11', 'BSIT', 'Block 2'],
            ])
        );

        $response->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 2)
            ->assertJsonPath('summary.email_conflict_skipped', 0)
            ->assertJsonPath('summary.skipped_unknown_department', 0);

        // Identical names must not violate the users.email unique constraint.
        $this->assertSame(2, User::where('role', 'student')->count());
        $this->assertNotNull(User::where('email', 'ana.flores')->first());
        $second = User::where('email', 'ana.flores2')->firstOrFail();
        $this->assertSame('2024-0002', $second->student_id);
    }

    public function test_duplicate_student_id_within_one_file_creates_one_account(): void
    {
        $this->makeUser(['role' => 'teacher', 'department' => 'BSIT']);

        $response = $this->import(
            $this->csv([
                ['2024-0001', 'Ana Flores', '11', 'BSIT', 'Block 1'],
                ['2024-0001', 'Ana Flores Again', '11', 'BSIT', 'Block 1'],
            ])
        );

        $response->assertStatus(201)
            ->assertJsonPath('summary.duplicates_within_file', 1)
            ->assertJsonPath('summary.accounts_provisioned', 1)
            ->assertJsonPath('summary.total_records', 1)
            ->assertJsonPath('summary.skipped_unknown_department', 0);

        $this->assertSame(1, User::where('student_id', '2024-0001')->count());
        $this->assertSame('ana.flores', User::where('student_id', '2024-0001')->firstOrFail()->email);
    }

    public function test_incomplete_row_is_feed_only_and_never_touches_accounts(): void
    {
        // A row missing its department is stored in the feed, flagged, and
        // provisions nothing — User Management must never show blank fields.
        $response = $this->import(
            $this->csv([
                ['2024-0001', 'Ana Flores', '11', '', 'Block 1'],
            ])
        );

        $response->assertStatus(201)
            ->assertJsonPath('summary.skipped_incomplete', 1)
            ->assertJsonPath('summary.accounts_provisioned', 0)
            ->assertJsonPath('summary.created_eligibility_rows', 1)
            ->assertJsonPath('summary.flagged_for_review', 1);

        $this->assertSame(1, DB::table('registrar_imports')->where('student_id', '2024-0001')->count());
        $this->assertNull(User::where('student_id', '2024-0001')->first());

        // An existing account is also left untouched by an incomplete row.
        $this->makeUser([
            'role' => 'student',
            'student_id' => '2024-0002',
            'email' => 'bryan.cruz',
            'password' => '2024-0002',
            'year_level' => '11',
            'department' => 'BSIT',
            'block_number' => '2',
        ]);
        $this->import($this->csv([
            ['2024-0002', 'Bryan Cruz', '', '', ''],
        ]));

        $existing = User::where('student_id', '2024-0002')->firstOrFail();
        $this->assertSame('BSIT', $existing->department);
    }

    public function test_unknown_department_rows_are_never_provisioned(): void
    {
        // BSED does not exist anywhere yet — an import may not invent it.
        $this->makeUser(['role' => 'teacher', 'department' => 'BSIT']);

        $response = $this->import(
            $this->csv([
                ['2024-0001', 'Ana Flores', '11', 'BSIT', 'Block 1'],
                ['2024-0002', 'Bryan Cruz', '12', 'BSED', 'Block 2'],
            ])
        );

        $response->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 1)
            ->assertJsonPath('summary.skipped_unknown_department', 1)
            ->assertJsonPath('summary.flagged_for_review', 1)
            ->assertJsonPath('summary.skipped_incomplete', 0);

        $this->assertNull(User::where('student_id', '2024-0002')->first());
        $flagged = RegistrarImport::where('student_id', '2024-0002')->firstOrFail();
        $this->assertTrue((bool) $flagged->needs_review);
        $this->assertStringContainsString('not in the current department list', (string) $flagged->review_reason);

        // The department was NOT added anywhere (no master list entry, no user).
        $this->assertSame(0, User::where('department', 'BSED')->count());
        $this->assertNull(DB::table('election_settings')->where('key', 'admin.departments.list')->value('value'));
    }

    public function test_student_id_match_wins_and_preserves_panel_account(): void
    {
        // A panel account matched BY ID is updated, never overwritten: the
        // teacher's login and role survive a registrar row that reuses the ID.
        $this->makeUser(['role' => 'teacher', 'student_id' => '2024-1001', 'email' => 'faculty@school.edu']);
        $this->makeUser(['role' => 'teacher', 'department' => 'BSIT']);

        $response = $this->import(
            $this->csv([
                ['2024-1001', 'Prof. Rivera', '11', 'BSIT', 'Block 1'],
            ])
        );

        $response->assertStatus(201)
            ->assertJsonPath('summary.accounts_provisioned', 0)
            ->assertJsonPath('summary.email_conflict_skipped', 0)
            ->assertJsonPath('summary.skipped_unknown_department', 0)
            ->assertJsonPath('summary.rejected_rows', []);

        $this->assertSame(1, User::where('student_id', '2024-1001')->count());
        $this->assertSame('teacher', User::where('student_id', '2024-1001')->firstOrFail()->role, 'role upgrade safety preserved');
        $this->assertSame('faculty@school.edu', User::where('student_id', '2024-1001')->firstOrFail()->email, 'existing login untouched');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function import(string $csv)
    {
        return $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->stateful()
            ->post('/api/admin/registrar/import', [
                'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
            ]);
    }

    private function csv(array $rows): string
    {
        $header = ['Student ID', 'Student Name', 'Year Level', 'Department', 'Block Number'];
        $data = array_merge([$header], $rows);
        $lines = array_map(
            fn (array $row) => implode(',', array_map(fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"', $row)),
            $data
        );

        return implode("\n", $lines)."\n";
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

        // Mirror the migration: Colleges are pre-seeded, so the import guard
        // treats them as known even before any account carries one.
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
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}